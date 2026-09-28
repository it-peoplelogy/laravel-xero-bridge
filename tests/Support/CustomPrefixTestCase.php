<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Tests\Support;

/**
 * Boots the package with a non-default route prefix, to prove the prefix is
 * genuinely read from config rather than hardcoded. Same reasoning as
 * WebRoutesTestCase: routes are registered at boot, so this has to happen
 * before the application starts.
 */
class CustomPrefixTestCase extends WebRoutesTestCase
{
    public function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('xero-bridge.routes.prefix', 'accounting');
        $app['config']->set('xero-bridge.redirect_uri', 'https://example.test/accounting/callback');
    }
}
