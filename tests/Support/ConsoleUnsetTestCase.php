<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Tests\Support;

use Peoplelogy\XeroBridge\Tests\TestCase;

/**
 * Boots with the console left exactly as a host that never set
 * XERO_CONSOLE_ENABLED gets it: null.
 *
 * In `local`, on purpose. That is the environment the pre-1.5.0 default
 * switched the console ON for, so it is the one that proves the default really
 * is off now -- at registration, which a test that merely flips config at
 * request time cannot show. Same reasoning as ConsoleDisabledTestCase: routes
 * are registered at boot, so this has to be decided before the application
 * starts.
 */
class ConsoleUnsetTestCase extends TestCase
{
    public function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('xero-bridge.console.enabled', null);

        $app['env'] = 'local';
        $app['config']->set('app.env', 'local');
    }
}
