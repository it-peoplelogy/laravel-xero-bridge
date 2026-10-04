<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Tests\Support;

use Peoplelogy\XeroBridge\Tests\TestCase;

/**
 * Boots with webhooks.enabled = false -- XERO_WEBHOOKS_ENABLED=false -- while
 * the base TestCase's signing key stays set, to prove the switch still keeps
 * the route away when a key alone would serve it. Decided at boot, like every
 * route flag, so it is set before the application starts.
 */
class WebhooksDisabledTestCase extends TestCase
{
    public function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('xero-bridge.webhooks.enabled', false);
    }
}
