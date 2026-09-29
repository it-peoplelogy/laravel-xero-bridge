<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Tests\Support;

use Peoplelogy\XeroBridge\Tests\TestCase;

/**
 * Boots with the console switched off, so the tests bound to it can prove the
 * routes were never registered in the first place -- which a test that merely
 * flips config at request time cannot show.
 */
class ConsoleDisabledTestCase extends TestCase
{
    public function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('xero-bridge.console.enabled', false);
    }
}
