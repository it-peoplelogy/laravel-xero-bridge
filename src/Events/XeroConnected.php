<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Peoplelogy\XeroBridge\Models\XeroConnection;
use Peoplelogy\XeroBridge\OAuth\Actor;

/**
 * An organisation finished the consent flow and was stored.
 */
class XeroConnected
{
    use Dispatchable;

    /**
     * Who completed the consent: the user signed in on the callback request,
     * as scalars (see Actor for why never the model). Null when nobody was
     * signed in on that request -- only possible once `auth` is removed from
     * XERO_ROUTES_MIDDLEWARE, and even then a user signed in through the
     * session is still recorded -- when the guard's identifier is neither an
     * int nor a string, or when the user could not be resolved, which never
     * fails the connection.
     *
     * A declared property with a default rather than a promoted readonly one,
     * on purpose. An event serialised by an earlier version -- a queued
     * listener still waiting when the deploy lands -- has no `actor` in its
     * payload. Unserialising it leaves a property that has a default at that
     * default, null; a promoted readonly property would be left uninitialised
     * instead, and the first read of `$event->actor` would throw an Error. A
     * readonly property cannot have a default, so this one is not readonly.
     */
    public ?Actor $actor = null;

    public function __construct(
        public readonly XeroConnection $connection,
        /** True when an existing key was repointed at a different organisation. */
        public readonly bool $wasRepointed = false,
        ?Actor $actor = null,
    ) {
        $this->actor = $actor;
    }
}
