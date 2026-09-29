<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\MyInvois;

use Illuminate\Contracts\Config\Repository;

/**
 * Typed, validating reader for config/myinvois.php.
 *
 * Shaped after Support\XeroConfig deliberately: everything that can be wrong in
 * the environment is caught here, at the point of use, with a message naming
 * the key and the fix -- rather than surfacing later as an opaque 401.
 */
final class MyInvoisConfig
{
    /**
     * Values that are obviously not credentials.
     *
     * LHDN auto-blocks a Client ID that sends placeholder values to the token
     * endpoint, so an unfilled environment file is worth catching before it
     * costs somebody their registration.
     *
     * Matched EXACT, trimmed and case-insensitively -- never as a substring. A
     * legitimate high-entropy secret can easily contain "0" or "xxx", and
     * locking a consumer out of a working integration with a message insisting
     * their real credential is a placeholder is worse than having no guard.
     *
     * @var list<string>
     */
    private const PLACEHOLDERS = [
        '0',
        'null',
        'none',
        'todo',
        'tbd',
        'xxx',
        'xxxx',
        'changeme',
        'your_client_id',
        'your_client_secret',
        'client_id',
        'client_secret',
    ];

    public function __construct(private readonly Repository $config) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config->get("myinvois.{$key}", $default);
    }

    public function enabled(): bool
    {
        return (bool) $this->get('enabled', false);
    }

    /** 'sandbox' or 'production'. */
    public function environment(): string
    {
        $value = strtolower(trim((string) ($this->get('environment') ?? '')));

        if ($value !== 'sandbox' && $value !== 'production') {
            throw MyInvoisException::unknownEnvironment($value);
        }

        return $value;
    }

    /**
     * The API host for the current environment. The identity service lives on
     * the same host, so the token URL and the API URL can never disagree.
     */
    public function baseUrl(): string
    {
        return rtrim((string) $this->get('base_urls.'.$this->environment()), '/');
    }

    public function tokenUrl(): string
    {
        return $this->baseUrl().'/connect/token';
    }

    public function validateUrl(string $tin): string
    {
        return $this->baseUrl().'/api/v1.0/taxpayer/validate/'.rawurlencode($tin);
    }

    public function clientId(): string
    {
        return $this->credential('client_id', 'MYINVOIS_CLIENT_ID');
    }

    public function clientSecret(): string
    {
        return $this->credential('client_secret', 'MYINVOIS_CLIENT_SECRET');
    }

    public function onBehalfOf(): ?string
    {
        $value = trim((string) ($this->get('on_behalf_of') ?? ''));

        return $value === '' ? null : $value;
    }

    public function tokenStore(): ?string
    {
        $value = $this->get('token.store');

        return ($value === null || $value === '') ? null : (string) $value;
    }

    public function tokenLeeway(): int
    {
        return max(0, (int) $this->get('token.leeway', 60));
    }

    public function resultStore(): ?string
    {
        $value = $this->get('results.store');

        return ($value === null || $value === '') ? null : (string) $value;
    }

    /** Seconds to cache a positive answer; 0 disables caching it. */
    public function matchedTtl(): int
    {
        return max(0, (int) $this->get('results.matched_ttl', 0));
    }

    /** Seconds to cache a negative answer; 0 disables caching it. */
    public function unmatchedTtl(): int
    {
        return max(0, (int) $this->get('results.unmatched_ttl', 0));
    }

    public function timeout(): int
    {
        return max(1, (int) $this->get('http.timeout', 30));
    }

    public function connectTimeout(): int
    {
        return max(1, (int) $this->get('http.connect_timeout', 10));
    }

    /** TOTAL attempts including the first, matching Laravel's Http::retry(). */
    public function retries(): int
    {
        return max(1, (int) $this->get('http.retries', 3));
    }

    public function retryBaseMs(): int
    {
        return max(0, (int) $this->get('http.retry_base_ms', 1000));
    }

    /**
     * Everything must be right before a single byte leaves the process.
     *
     * Called by the client on every public entry point, so a misconfiguration
     * is a clear local message rather than a 401 from LHDN minutes later.
     */
    public function assertUsable(): void
    {
        if (! $this->enabled()) {
            throw MyInvoisException::disabled();
        }

        $this->environment();
        $this->clientId();
        $this->clientSecret();
    }

    /**
     * The same checks, as a list rather than an exception.
     *
     * Used by the console panel so it can show what is wrong without throwing,
     * and so it never reports on a module the host has not switched on.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $problems = [];

        foreach ([['client_id', 'MYINVOIS_CLIENT_ID'], ['client_secret', 'MYINVOIS_CLIENT_SECRET']] as [$key, $envKey]) {
            try {
                $this->credential($key, $envKey);
            } catch (MyInvoisException $e) {
                $problems[] = $e->getMessage();
            }
        }

        try {
            $this->environment();
        } catch (MyInvoisException $e) {
            $problems[] = $e->getMessage();
        }

        return $problems;
    }

    private function credential(string $key, string $envKey): string
    {
        $value = trim((string) ($this->get($key) ?? ''));

        if ($value === '') {
            throw MyInvoisException::missing($key, $envKey);
        }

        if (in_array(strtolower($value), self::PLACEHOLDERS, true)) {
            throw MyInvoisException::placeholder($key, $envKey);
        }

        return $value;
    }
}
