<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Peoplelogy\XeroBridge\Models\XeroConnection;

/**
 * A connection is terminally dead and a human must re-authorise it.
 *
 * Fired ONLY on a genuine invalid_grant, and ONLY on the transition from
 * valid to invalidated -- so a scheduled refresh running every night cannot
 * spam whoever is listening. A transient 5xx, a timeout, or a failure to save
 * rotated tokens must never fire this.
 *
 * This is the event to hang an alert on: the host project's Xero connection
 * dropped in June and nobody was told for months.
 */
class ConnectionExpired
{
    use Dispatchable;

    public function __construct(
        public readonly XeroConnection $connection,
        public readonly string $reason,
        /** Where a human should go to fix it. */
        public readonly string $connectUrl,
    ) {}
}
