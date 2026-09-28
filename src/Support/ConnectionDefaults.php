<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Support;

/**
 * Per-connection invoice defaults.
 *
 * ACCOUNT CODES AND TAX TYPES DIFFER PER XERO ORGANISATION -- a '200' sales
 * account in one org may be '4000' in another -- which is exactly why these
 * are per connection rather than global constants.
 */
final class ConnectionDefaults
{
    /** @param array<string, mixed> $values */
    private function __construct(private readonly array $values) {}

    /**
     * Named connections inherit the 'default' block; an explicit key wins
     * even when it is set to null. A key absent from config is not an error,
     * it simply inherits everything.
     *
     * @param  array<string, mixed>  $connections
     */
    public static function for(string $key, array $connections): self
    {
        return new self(array_replace(
            (array) ($connections['default'] ?? []),
            (array) ($connections[$key] ?? []),
        ));
    }

    /** @param array<string, mixed> $overrides */
    public function merge(array $overrides): self
    {
        return new self(array_replace($this->values, $overrides));
    }

    public function accountCode(): ?string
    {
        return $this->stringOrNull('account_code');
    }

    /**
     * Null by default and usually SHOULD be null. Malaysian SST is per line
     * (training 8%, education and rental 6%), so one invoice can carry two
     * rates and a connection-wide default would stamp the wrong one on a
     * venue recharge.
     */
    public function taxType(): ?string
    {
        return $this->stringOrNull('tax_type');
    }

    public function currency(): ?string
    {
        return $this->stringOrNull('currency');
    }

    public function brandingThemeId(): ?string
    {
        return $this->stringOrNull('branding_theme_id');
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    private function stringOrNull(string $key): ?string
    {
        $value = $this->values[$key] ?? null;

        return ($value === null || $value === '') ? null : (string) $value;
    }
}
