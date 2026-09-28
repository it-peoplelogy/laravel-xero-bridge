<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Exceptions;

/**
 * HTTP 429.
 *
 * Xero's limits per tenant are 60 calls/minute, 5 concurrent, and 1,000/day
 * on Starter or 5,000/day above it, plus 10,000/minute across the whole app.
 *
 * retryAfter() is the important accessor: a queued job should
 * release($e->retryAfter()) rather than block a worker, because a daily-limit
 * Retry-After can be measured in hours.
 *
 * Note limitProblem() can be null: Xero only sends Retry-After for the minute
 * and daily limits, so a concurrency 429 carries neither header reliably.
 */
class XeroRateLimitException extends XeroBridgeException
{
    protected ?string $limitProblem = null;

    /** 'minute' | 'day' | 'concurrent' | 'appminute' | null */
    public function limitProblem(): ?string
    {
        return $this->limitProblem;
    }

    public function withLimitProblem(?string $problem): static
    {
        $this->limitProblem = ($problem === null || $problem === '') ? null : $problem;

        return $this;
    }
}
