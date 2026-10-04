<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Webhooks;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Peoplelogy\XeroBridge\Models\XeroWebhookEvent;
use Peoplelogy\XeroBridge\Support\Clock;
use Peoplelogy\XeroBridge\Support\TableGuard;
use Peoplelogy\XeroBridge\Support\XeroConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records that an event was dispatched, so a replay does not dispatch it again.
 *
 * THE ORDERING IS THE DESIGN, and it is the opposite of the write ledger's.
 *
 * Here the row is written AFTER the event is dispatched, not before. A webhook
 * event is a notification about a change that has already happened in Xero;
 * losing one means a consumer never hears about a change, and Xero will not
 * send it again once the endpoint has 200'd. So the failure that matters is
 * "recorded but never dispatched", and claiming first would create exactly
 * that: a worker killed between the claim and the dispatch would suppress the
 * event permanently.
 *
 * Dispatching first means the worst case is a DUPLICATE dispatch, which is the
 * thing every consumer is already told to tolerate -- XeroWebhookReceived's
 * docblock says LISTENERS MUST BE IDEMPOTENT in capitals, and that was true
 * before this table existed.
 *
 * The write ledger reverses this, for the equally good reason that a duplicate
 * invoice is money and a stuck row is not.
 */
final class WebhookEventRecorder
{
    public function __construct(
        private readonly XeroConfig $config,
        private readonly TableGuard $tables,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Should this event be handed to listeners?
     *
     * False only when we can prove we have dispatched it before. Every
     * uncertainty -- feature off, table absent, database unreachable --
     * resolves to TRUE, because a missed notification is worse than a
     * duplicate one that listeners are already required to tolerate.
     */
    public function shouldDispatch(WebhookEvent $event): bool
    {
        if (! $this->usable()) {
            return true;
        }

        try {
            $known = XeroWebhookEvent::query()
                ->where('dedupe_key', $event->dedupeKey())
                ->exists();

            if ($known) {
                // Counted HERE, on the ordinary replay path, not only on the
                // rarer race in recordDispatched(). delivery_count is the only
                // direct evidence that the 31-day window is being exercised,
                // and it would stay stuck at 1 forever if it were bumped only
                // when two workers collided.
                $this->countRedelivery($event, Clock::now());

                return false;
            }

            return true;
        } catch (Throwable $e) {
            $this->logger->warning(
                'xero-bridge: could not check webhook replay state, dispatching anyway.',
                ['exception' => $e->getMessage()],
            );

            return true;
        }
    }

    /**
     * Record that the event has now been dispatched.
     *
     * On a replay this bumps delivery_count instead of inserting, which is the
     * only direct evidence available that the 31-day window is being exercised.
     *
     * NEVER THROWS. It runs after the listeners, so an exception here would
     * fail a job whose listeners had already run: every retry would dispatch
     * the event again, and the events behind it in the envelope would wait,
     * then be lost with the job once its last attempt failed. Recording is a
     * side benefit. A failure to record is logged, and costs only a later
     * delivery of the same event being dispatched again, which listeners must
     * tolerate anyway.
     */
    public function recordDispatched(WebhookEvent $event): void
    {
        if (! $this->usable()) {
            return;
        }

        $now = Clock::now();

        try {
            XeroWebhookEvent::create([
                'dedupe_key' => $event->dedupeKey(),
                'tenant_id' => $event->tenantId,
                'resource_id' => $event->resourceId,
                'event_type' => $event->eventType,
                'event_category' => $event->eventCategory,
                'event_date_utc' => $event->eventDateUtc,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'delivery_count' => 1,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another worker recorded the same event between our check and our
            // insert. That is the constraint doing its job, not a failure --
            // count the delivery and carry on.
            //
            // Laravel's own subclass, raised for a duplicate key and nothing
            // else on every driver it ships. Not SQLSTATE 23000: on MySQL and
            // SQLite that is every integrity violation -- NOT NULL, foreign key
            // and CHECK too -- so a column a host added without a default would
            // have been counted as a redelivery while the event went
            // unrecorded, with nothing in the log.
            $this->countRedelivery($event, $now);
        } catch (Throwable $e) {
            // Anything else, a database error included: logged, never thrown.
            // See the docblock.
            $this->logger->warning(
                'xero-bridge: could not record a dispatched webhook event.',
                ['exception' => $e->getMessage()],
            );
        }
    }

    private function countRedelivery(WebhookEvent $event, CarbonImmutable $now): void
    {
        try {
            XeroWebhookEvent::query()
                ->where('dedupe_key', $event->dedupeKey())
                ->update([
                    'last_seen_at' => $now,
                    'delivery_count' => DB::raw('delivery_count + 1'),
                ]);
        } catch (Throwable $e) {
            $this->logger->warning(
                'xero-bridge: could not count a webhook redelivery.',
                ['exception' => $e->getMessage()],
            );
        }
    }

    private function usable(): bool
    {
        return (bool) $this->config->get('webhooks.dedupe.enabled', false)
            && $this->tables->has(
                $this->config->get('database.connection'),
                (string) $this->config->get('webhooks.dedupe.table', 'xero_webhook_events'),
                'xero-bridge-migrations',
            );
    }
}
