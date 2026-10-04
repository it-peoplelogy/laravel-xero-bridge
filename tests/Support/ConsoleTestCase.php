<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Tests\Support;

use Peoplelogy\XeroBridge\Tests\TestCase;

/**
 * The console route is registered during boot, so its middleware and its
 * enabled flag have to be decided before the application starts -- exactly as
 * for WebRoutesTestCase.
 *
 * `enabled` is pinned to true, because the shipped default -- nothing set -- is
 * off, and then no console route would be registered for these tests to reach.
 * The gate tests flip it per request from here; what the shipped default
 * registers at boot is proven separately, in tests/ConsoleUnset.
 *
 * `auth` is dropped for the same reason WebRoutesTestCase drops it: these
 * tests are about the console, not the host's authentication. That the
 * shipped default includes `auth` is asserted separately, in
 * tests/Unit/ConsoleConfigTest.php.
 */
class ConsoleTestCase extends TestCase
{
    public function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('xero-bridge.console.enabled', true);
        $app['config']->set('xero-bridge.console.middleware', ['web']);
        $app['config']->set('xero-bridge.console.writable_organisations', []);
    }
}
