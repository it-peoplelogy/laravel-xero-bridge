<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Peoplelogy\XeroBridge\Models\XeroConnection;

/**
 * An organisation finished the consent flow and was stored.
 */
class XeroConnected
{
    use Dispatchable;

    public function __construct(
        public readonly XeroConnection $connection,
        /** True when an existing key was repointed at a different organisation. */
        public readonly bool $wasRepointed = false,
    ) {}
}
