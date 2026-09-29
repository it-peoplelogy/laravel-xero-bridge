<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Peoplelogy\XeroBridge\Events\XeroWebhookReceived;
use Peoplelogy\XeroBridge\Webhooks\WebhookEnvelope;
use Peoplelogy\XeroBridge\Webhooks\WebhookEvent;
use Peoplelogy\XeroBridge\Webhooks\WebhookEventRecorder;

/**
 * Fans one webhook envelope out into a XeroWebhookReceived per event.
 *
 * This exists so the CONTROLLER never dispatches events. Xero requires a 2xx
 * within 5 seconds and disables a subscription after 24 hours of failures --
 * so a single synchronous listener written by a consuming application must
 * not be able to hold the response open.
 *
 * ShouldBeUnique closes a duplicate path that is GUARANTEED rather than
 * merely possible. The controller dispatches this job BEFORE returning its
 * 200, so if the response misses Xero's five-second budget the job is already
 * queued when Xero retries -- and every listener then fires twice for one
 * logical change. The lock is keyed on the events themselves, so the retry
 * finds the original in flight and is dropped.
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

        // Order is preserved: Xero replays stored events in sequence and
        // consumers may rely on that.
        foreach ($envelope->events as $event) {
            if (! $recorder->shouldDispatch($event)) {
                continue;
            }

            XeroWebhookReceived::dispatch($event, $envelope);

            // After the dispatch, never before -- see WebhookEventRecorder.
            $recorder->recordDispatched($event);
        }
    }
}
