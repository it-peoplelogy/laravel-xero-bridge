<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Tests\Support;

use Illuminate\Support\Arr;

/**
 * Boots the package with a non-default route prefix, to prove the prefix is
 * genuinely read from config rather than hardcoded. Same reasoning as
 * WebRoutesTestCase: routes are registered at boot, so this has to happen
 * before the application starts.
 *
 * The webhooks block is left with no `prefix` key at all -- what a config
 * cached before that key existed still holds, since a cached config is never
 * filled -- so the webhook has to find routes.prefix on its own.
 */
class CustomPrefixTestCase extends WebRoutesTestCase
{
    public function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('xero-bridge.routes.prefix', 'accounting');
        $app['config']->set('xero-bridge.redirect_uri', 'https://example.test/accounting/callback');
        $app['config']->set(
            'xero-bridge.webhooks',
            Arr::except((array) $app['config']->get('xero-bridge.webhooks'), 'prefix'),
        );
    }
}
