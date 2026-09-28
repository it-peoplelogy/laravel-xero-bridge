<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\OAuth;

use Carbon\CarbonImmutable;
use Peoplelogy\XeroBridge\Support\Scopes;

/**
 * An immutable view over a response from https://identity.xero.com/connect/token.
 *
 * The id_token is deliberately NOT exposed or stored. It carries the
 * authorising user's name and email, which is PII this package has no reason
 * to hold, and storing it would put it in an unencrypted column.
 */
final class TokenResponse
{
    private function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly int $expiresIn,
        public readonly string $scope,
        public readonly string $tokenType,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            accessToken: (string) ($payload['access_token'] ?? ''),
            refreshToken: isset($payload['refresh_token']) ? (string) $payload['refresh_token'] : null,
            // Xero documents 1800 (30 minutes); default to it rather than 0,
            // which would make every token look permanently expired.
            expiresIn: (int) ($payload['expires_in'] ?? 1800),
            scope: Scopes::normalize($payload['scope'] ?? null),
            tokenType: (string) ($payload['token_type'] ?? 'Bearer'),
        );
    }

    public function hasRefreshToken(): bool
    {
        return $this->refreshToken !== null && $this->refreshToken !== '';
    }

    public function expiresAt(): CarbonImmutable
    {
        return CarbonImmutable::now()->addSeconds($this->expiresIn);
    }

    /** @return list<string> */
    public function grantedScopes(): array
    {
        return Scopes::parse($this->scope);
    }
}
