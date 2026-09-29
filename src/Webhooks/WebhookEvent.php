<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Webhooks;

use Carbon\CarbonImmutable;
use Peoplelogy\XeroBridge\Support\XeroDate;

/**
 * One entry from a webhook payload's `events` array.
 *
 * Carries every field Xero declares required, including tenantType, which is
 * easy to miss.
 */
final class WebhookEvent
{
    public const CATEGORY_CONTACT = 'CONTACT';

    public const CATEGORY_INVOICE = 'INVOICE';

    public const CATEGORY_CREDITNOTE = 'CREDITNOTE';

    private function __construct(
        public readonly string $resourceUrl,
        public readonly string $resourceId,
        public readonly string $eventDateUtc,
        public readonly string $eventType,
        public readonly string $eventCategory,
        public readonly string $tenantId,
        public readonly string $tenantType,
        /** Present on CreditNote, Prepayment and Overpayment events only. */
        public readonly ?array $data,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            resourceUrl: (string) ($payload['resourceUrl'] ?? ''),
            resourceId: (string) ($payload['resourceId'] ?? ''),
            eventDateUtc: (string) ($payload['eventDateUtc'] ?? ''),
            eventType: (string) ($payload['eventType'] ?? ''),
            eventCategory: (string) ($payload['eventCategory'] ?? ''),
            tenantId: (string) ($payload['tenantId'] ?? ''),
            // Defaulted rather than required: a missing value should not
            // throw away an otherwise valid event.
            tenantType: (string) ($payload['tenantType'] ?? 'ORGANISATION'),
            data: isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : null,
        );
    }

    /**
     * Compared case-insensitively: Xero's OpenAPI spec declares the constant
     * as UPDATE while its own webhook documentation shows "Update".
     */
    public function isUpdate(): bool
    {
        return strcasecmp($this->eventType, 'UPDATE') === 0;
    }

    public function isCreate(): bool
    {
        return strcasecmp($this->eventType, 'CREATE') === 0;
    }

    public function isCategory(string $category): bool
    {
        return strcasecmp($this->eventCategory, $category) === 0;
    }

    public function isInvoice(): bool
    {
        return $this->isCategory(self::CATEGORY_INVOICE);
    }

    public function isContact(): bool
    {
        return $this->isCategory(self::CATEGORY_CONTACT);
    }

    /** Format is like 2025-10-21T01:15:39.902, with no trailing Z. */
    public function occurredAt(): ?CarbonImmutable
    {
        return XeroDate::parse($this->eventDateUtc);
    }

    /**
     * A stable identity for this logical event, for deduplicating replays.
     *
     * Xero stores undelivered events for up to 31 days and replays them, so
     * the same change arrives more than once -- after an outage, after a
     * retry, and after a deployment that briefly 502s.
     *
     * DO NOT use the envelope's `entropy` field for this. It DIFFERS between
     * deliveries of the same logical event, which makes it worse than useless
     * as a key: every replay would look new. It exists to vary the payload
     * signature, not to identify the event.
     *
     * The four parts each earn their place. `tenantId` because the same
     * resource id can exist in two connected organisations. `resourceId` and
     * `eventType` because a CREATE and a later UPDATE of one invoice are
     * different events. `eventDateUtc` because Xero genuinely sends the same
     * resource again when it changes again, and that is a new event rather
     * than a replay -- it is millisecond-precise, so it separates them.
     */
    public function dedupeKey(): string
    {
        return hash('sha256', implode('|', [
            $this->tenantId,
            $this->resourceId,
            $this->eventType,
            $this->eventDateUtc,
        ]));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'resourceUrl' => $this->resourceUrl,
            'resourceId' => $this->resourceId,
            'eventDateUtc' => $this->eventDateUtc,
            'eventType' => $this->eventType,
            'eventCategory' => $this->eventCategory,
            'tenantId' => $this->tenantId,
            'tenantType' => $this->tenantType,
            'data' => $this->data,
        ], static fn ($value) => $value !== null);
    }
}
