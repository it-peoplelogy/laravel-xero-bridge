<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Peoplelogy\XeroBridge\Webhooks\WebhookEnvelope;
use Peoplelogy\XeroBridge\Webhooks\WebhookEvent;

/**
 * One Xero webhook event, dispatched from a QUEUED job -- never from the
 * controller.
 *
 * That matters: Xero requires a 2xx within 5 seconds, and after 24 hours of
 * failures it disables the subscription outright. Dispatching from the job
 * means a slow listener registered by a consuming application cannot blow
 * that budget no matter how it is written.
 *
 * LISTENERS MUST BE IDEMPOTENT. Xero stores events for up to 31 days and
 * replays them in order after an outage, so the same event can arrive more
 * than once.
 */
class XeroWebhookReceived
{
    use Dispatchable;

    public function __construct(
        public readonly WebhookEvent $event,
        public readonly WebhookEnvelope $envelope,
    ) {}

    public function tenantId(): string
    {
        return $this->event->tenantId;
    }

    public function resourceId(): string
    {
        return $this->event->resourceId;
    }
}
