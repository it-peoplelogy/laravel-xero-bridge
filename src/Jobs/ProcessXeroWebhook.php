<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Peoplelogy\XeroBridge\Events\XeroWebhookReceived;
use Peoplelogy\XeroBridge\Webhooks\WebhookEnvelope;

/**
 * Fans one webhook envelope out into a XeroWebhookReceived per event.
 *
 * This exists so the CONTROLLER never dispatches events. Xero requires a 2xx
 * within 5 seconds and disables a subscription after 24 hours of failures --
 * so a single synchronous listener written by a consuming application must
 * not be able to hold the response open.
 */
class ProcessXeroWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @param array<string, mixed> $payload */
    public function __construct(private readonly array $payload) {}

    public function handle(): void
    {
        $envelope = WebhookEnvelope::fromArray($this->payload);

        // Order is preserved: Xero replays stored events in sequence and
        // consumers may rely on that.
        foreach ($envelope->events as $event) {
            XeroWebhookReceived::dispatch($event, $envelope);
        }
    }
}
