<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;
use Peoplelogy\XeroBridge\Models\XeroConnection;

/**
 * Fired only AFTER rotated tokens have been durably saved -- never on the
 * "another process already refreshed" short-circuit, and never before the
 * write succeeds.
 */
class TokenRefreshed
{
    use Dispatchable;

    public function __construct(
        public readonly XeroConnection $connection,
        public readonly ?CarbonImmutable $expiresAt,
    ) {}
}
