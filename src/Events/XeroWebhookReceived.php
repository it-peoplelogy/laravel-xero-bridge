<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Peoplelogy\XeroBridge\Contracts\ConnectionRepository;
use Peoplelogy\XeroBridge\Models\XeroConnection;
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

    /**
     * The stored connection for this event's organisation, or null when none
     * is stored here -- the "is this one of ours?" question a listener asks
     * unless webhooks.unknown_tenants is `ignore`.
     *
     * Looked up when called, never carried on the event. A queued listener
     * can run long after the job, and in between a key can be repointed at
     * another organisation (on_key_conflict=replace) or an organisation moved
     * to a new key (on_tenant_conflict=rekey): the tenant id is the identity
     * that holds. Adding no property also means an event queued before this
     * method existed unserializes and works exactly as before.
     *
     * An invalidated connection is returned too, so check isUsable() before
     * calling Xero with it. Every call is a query. An App Store subscription
     * event (tenantType APPLICATION) carries the app's ID, so it gets null.
     */
    public function connection(): ?XeroConnection
    {
        return app(ConnectionRepository::class)->findByTenantId($this->tenantId());
    }
}
