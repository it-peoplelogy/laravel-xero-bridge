<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Tests\Support;

use Peoplelogy\XeroBridge\Tests\TestCase;

/**
 * The console route is registered during boot, so its middleware and its
 * enabled flag have to be decided before the application starts -- exactly as
 * for WebRoutesTestCase.
 *
 * `enabled` is pinned explicitly rather than left to the tri-state default.
 * The suite runs under APP_ENV=testing, which would switch the console on
 * anyway; pinning it means the gate tests own that behaviour instead of
 * inheriting it by accident.
 *
 * `auth` is dropped for the same reason WebRoutesTestCase drops it: these
 * tests are about the console, not the host's authentication. That the
 * shipped default includes `auth` is asserted separately, in ConfigTest.
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
