<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Client;

use Illuminate\Http\Client\Response;

/**
 * Xero returns these headers on EVERY response, not only on a 429, so they
 * are the only early warning available before a rate-limit storm.
 */
final class RateLimitStatus
{
    private function __construct(
        public readonly ?int $minuteRemaining,
        public readonly ?int $dayRemaining,
        public readonly ?int $appMinuteRemaining,
        /** Present only on a 429: 'minute' | 'day' | 'concurrent' | 'appminute'. */
        public readonly ?string $problem,
        public readonly ?int $retryAfter,
    ) {}

    public static function fromResponse(Response $response): self
    {
        $int = static function (?string $value): ?int {
            return ($value === null || $value === '' || ! ctype_digit(trim($value)))
                ? null
                : (int) trim($value);
        };

        return new self(
            minuteRemaining: $int($response->header('X-MinLimit-Remaining') ?: null),
            dayRemaining: $int($response->header('X-DayLimit-Remaining') ?: null),
            appMinuteRemaining: $int($response->header('X-AppMinLimit-Remaining') ?: null),
            problem: $response->header('X-Rate-Limit-Problem') ?: null,
            retryAfter: $int($response->header('Retry-After') ?: null),
        );
    }

    public function isRunningLow(): bool
    {
        return ($this->minuteRemaining !== null && $this->minuteRemaining < 5)
            || ($this->dayRemaining !== null && $this->dayRemaining < 100);
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'minute_remaining' => $this->minuteRemaining,
            'day_remaining' => $this->dayRemaining,
            'app_minute_remaining' => $this->appMinuteRemaining,
            'problem' => $this->problem,
            'retry_after' => $this->retryAfter,
        ];
    }
}
