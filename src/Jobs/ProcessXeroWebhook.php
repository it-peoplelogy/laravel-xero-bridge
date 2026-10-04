<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Peoplelogy\XeroBridge\Contracts\ConnectionRepository;
use Peoplelogy\XeroBridge\Events\XeroWebhookReceived;
use Peoplelogy\XeroBridge\Support\XeroConfig;
use Peoplelogy\XeroBridge\Webhooks\WebhookEnvelope;
use Peoplelogy\XeroBridge\Webhooks\WebhookEvent;
use Peoplelogy\XeroBridge\Webhooks\WebhookEventRecorder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fans one webhook envelope out into a XeroWebhookReceived per event.
 *
 * This exists so the CONTROLLER never dispatches events. Xero requires a 2xx
 * within 5 seconds and disables a subscription after 24 hours of failures --
 * so a single synchronous listener written by a consuming application must
 * not be able to hold the response open.
 *
 * ShouldBeUnique closes a duplicate path that is GUARANTEED rather than
 * merely possible. The controller queues this job BEFORE returning its 200,
 * so if the response misses Xero's five-second budget the job is already
 * queued when Xero retries -- and every listener then fires twice for one
 * logical change. The lock is keyed on the events themselves, so the retry
 * finds the original queued or running and is answered without being queued
 * again.
 *
 * The CONTROLLER takes that lock, not Laravel. Laravel acquires a
 * ShouldBeUnique lock only on the PendingDispatch path (the dispatch()
 * helper, ProcessXeroWebhook::dispatch()); the Bus dispatcher the controller
 * queues through goes straight to the queue, which is how 1.4.x declared
 * this interface without the lock ever being taken. Releasing is Laravel's
 * as for any unique job: the worker gives the lock back when the job
 * finishes, or when its last attempt fails.
 *
 * Its honest limit: a lock is short-lived, so this catches the retry storm
 * within minutes. It cannot dedupe a replay Xero sends days later, after an
 * outage. That belongs at the consumer's side-effect boundary -- or, if
 * enabled, in the durable xero_webhook_events table.
 */
class ProcessXeroWebhook implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @param array<string, mixed> $payload */
    public function __construct(private readonly array $payload) {}

    /**
     * The identity of this delivery, for the uniqueness lock.
     *
     * Built from the EVENTS, not the envelope. Xero varies `entropy` between
     * deliveries of the same logical events specifically so the payload
     * signature differs, which means hashing the raw payload would make every
     * retry look new -- the exact opposite of what is wanted here.
     */
    public function uniqueId(): string
    {
        $keys = array_map(
            static fn (WebhookEvent $event): string => $event->dedupeKey(),
            WebhookEnvelope::fromArray($this->payload)->events,
        );

        return hash('sha256', implode("\n", $keys));
    }

    /**
     * How long the lock is held if the job never completes.
     *
     * Long enough to cover Xero's retry window for one delivery, short enough
     * that a worker killed mid-job does not block the same events for hours.
     */
    public function uniqueFor(): int
    {
        return max(60, (int) config('xero-bridge.webhooks.unique_for', 900));
    }

    /**
     * The cache store that holds the uniqueness lock: the one XERO_LOCK_STORE
     * names (xero-bridge.tokens.lock_store), the same store token refreshes
     * lock in, or the default store while that is unset.
     *
     * A lock is only as good as its store. On the default store, a host whose
     * default is `file` and whose XERO_LOCK_STORE is redis would lock token
     * refreshes across servers but webhook retries on one server only, so a
     * retry that reached another web server would queue the same events
     * again.
     *
     * A name that cannot be resolved, or a store that cannot lock, falls back
     * to the default store instead of throwing. Laravel calls this again in
     * the WORKER to release the lock after the listeners have run, and an
     * exception there fails the attempt -- its retries would dispatch the
     * envelope's events again, from a job that had already done its work.
     * That misconfiguration does not go unnoticed: token refreshes throw on
     * an undefined store and warn about one that cannot lock, and
     * xero-bridge:status reports both.
     *
     * The null store counts as one that cannot lock. It does implement
     * LockProvider, but every lock it hands out is granted at once and
     * excludes nothing, so taking this lock there would switch uniqueness off
     * without a word -- the duplicate this lock exists to stop.
     *
     * Resolved here on every call and never stored on the job, so the
     * serialized payload stays exactly what earlier versions queued. Never
     * null either: Laravel 11 and early 12 use the return value as it is.
     */
    public function uniqueVia(): CacheRepository
    {
        $cache = app(CacheFactory::class);
        // The same reading of XERO_LOCK_STORE a token refresh uses: only a
        // non-empty string names a store.
        $name = app(XeroConfig::class)->lockStore();

        if ($name !== null) {
            try {
                $store = $cache->store($name);
                $driver = $store->getStore();

                if ($driver instanceof LockProvider && ! $driver instanceof NullStore) {
                    return $store;
                }
            } catch (Throwable) {
                // The default store below; see the docblock.
            }
        }

        return $cache->store();
    }

    public function tries(): int
    {
        return max(1, (int) config('xero-bridge.webhooks.tries', 5));
    }

    /**
     * Escalating, because the usual reason a listener fails is that something
     * downstream is briefly unavailable, and hammering it does not help.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 120, 600];
    }

    public function handle(): void
    {
        $envelope = WebhookEnvelope::fromArray($this->payload);

        // Resolved HERE rather than injected, so the job's serialized payload
        // is unchanged and anything queued under an earlier version still
        // deserializes after this upgrade.
        $recorder = app(WebhookEventRecorder::class);

        $filter = $this->ignoresUnknownTenants();

        // Tenant id => has a stored connection, for THIS run only. Never a
        // static or a singleton: a worker runs for days, and an organisation
        // connected in the meantime must be seen on its very next event.
        $known = [];

        // Tenant id => events skipped for it, for the one log line below.
        $skipped = [];

        // Order is preserved: Xero replays stored events in sequence and
        // consumers may rely on that.
        foreach ($envelope->events as $event) {
            // Before the recorder, so a skipped event is never dispatched,
            // recorded or counted. A dedupe row means "dispatched", and a row
            // for an event nobody heard about would suppress it for good once
            // the organisation is connected.
            if ($filter && ! $this->isKnownTenant($event, $known)) {
                $skipped[$event->tenantId] = ($skipped[$event->tenantId] ?? 0) + 1;

                continue;
            }

            if (! $recorder->shouldDispatch($event)) {
                continue;
            }

            XeroWebhookReceived::dispatch($event, $envelope);

            // After the dispatch, never before -- see WebhookEventRecorder.
            $recorder->recordDispatched($event);
        }

        // One line per envelope, not per event: an organisation connected to
        // the same Xero app from another environment sends a steady stream.
        if ($skipped !== []) {
            app(LoggerInterface::class)->info(
                'xero-bridge: skipped Xero webhook events for organisations with no stored connection '
                .'(webhooks.unknown_tenants is "ignore").',
                [
                    'tenant_ids' => array_map(strval(...), array_keys($skipped)),
                    'count' => array_sum($skipped),
                ],
            );
        }
    }

    /**
     * True only when webhooks.unknown_tenants is `ignore`, in any case and
     * with stray whitespace.
     *
     * Anything else is `dispatch`: the key missing (a config cached before
     * 1.5.0), a typo, a non-string. A filter switched on by a misspelling
     * would drop real notifications, while one left off only costs each
     * listener its own check -- which is how every release before 1.5.0
     * behaved.
     */
    private function ignoresUnknownTenants(): bool
    {
        $mode = config('xero-bridge.webhooks.unknown_tenants', 'dispatch');

        return is_string($mode) && strtolower(trim($mode)) === 'ignore';
    }

    /**
     * Does the event's organisation have a stored connection here?
     *
     * Only ORGANISATION events are looked up; every other tenantType passes.
     * An App Store subscription event says APPLICATION and carries the app's
     * own ID where a tenant ID would be, so it can never match a stored row
     * -- filtering it would drop exactly the events about the subscription.
     *
     * Existence only. An invalidated row still counts: the organisation IS
     * connected here and only needs a reconnect, so its listeners keep
     * hearing about it. isUsable() is never called, because it decrypts the
     * refresh token.
     *
     * A lookup that throws answers yes, unmemoised, so the event is
     * dispatched and the tenant's next event asks again. Skipping on an error
     * would turn a database blip into lost notifications.
     *
     * @param  array<string, bool>  $known  answers already found in this run
     */
    private function isKnownTenant(WebhookEvent $event, array &$known): bool
    {
        if (strcasecmp($event->tenantType, 'ORGANISATION') !== 0) {
            return true;
        }

        if (array_key_exists($event->tenantId, $known)) {
            return $known[$event->tenantId];
        }

        try {
            // Through the container at the moment of use, never the
            // constructor: the job's serialized payload must not change.
            $connection = app(ConnectionRepository::class)->findByTenantId($event->tenantId);
        } catch (Throwable $e) {
            app(LoggerInterface::class)->warning(
                'xero-bridge: could not look up the organisation of a Xero webhook event, so it was dispatched anyway.',
                ['tenant_id' => $event->tenantId, 'exception' => $e->getMessage()],
            );

            return true;
        }

        return $known[$event->tenantId] = $connection !== null;
    }
}
