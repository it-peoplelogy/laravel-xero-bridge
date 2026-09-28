<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\OAuth;

/**
 * One element of the response from https://api.xero.com/connections.
 *
 * That endpoint returns a BARE JSON ARRAY, not an object with a "Connections"
 * key, and it returns every tenant the token can reach -- not only the one
 * just authorised. So more than one ORGANISATION is the normal case for a
 * returning user.
 */
final class TenantInfo
{
    public const TYPE_ORGANISATION = 'ORGANISATION';

    private function __construct(
        /** Xero's CONNECTION id. DELETE /connections/{id} takes this, not the tenantId. */
        public readonly ?string $id,
        public readonly string $tenantId,
        public readonly string $tenantType,
        /** Documented as nullable -- it is null for PRACTICEMANAGER in Xero's own example. */
        public readonly ?string $tenantName,
        public readonly ?string $authEventId,
        public readonly ?string $createdDateUtc,
        public readonly ?string $updatedDateUtc,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            id: isset($payload['id']) ? (string) $payload['id'] : null,
            tenantId: (string) ($payload['tenantId'] ?? ''),
            // Values are ORGANISATION | PRACTICEMANAGER | PRACTICE. Note
            // PRACTICEMANAGER is one word with no separator.
            tenantType: (string) ($payload['tenantType'] ?? self::TYPE_ORGANISATION),
            tenantName: isset($payload['tenantName']) && $payload['tenantName'] !== ''
                ? (string) $payload['tenantName']
                : null,
            authEventId: isset($payload['authEventId']) ? (string) $payload['authEventId'] : null,
            createdDateUtc: isset($payload['createdDateUtc']) ? (string) $payload['createdDateUtc'] : null,
            updatedDateUtc: isset($payload['updatedDateUtc']) ? (string) $payload['updatedDateUtc'] : null,
        );
    }

    /** Only ORGANISATION tenants can be used with the Accounting API. */
    public function isOrganisation(): bool
    {
        return strcasecmp($this->tenantType, self::TYPE_ORGANISATION) === 0;
    }

    public function displayName(): string
    {
        return $this->tenantName ?: $this->tenantId;
    }

    /** Sort key for "most recently authorised", with a deterministic tie-break. */
    public function recencyKey(): string
    {
        return ($this->updatedDateUtc ?? '').'|'.($this->createdDateUtc ?? '').'|'.$this->tenantId;
    }
}
