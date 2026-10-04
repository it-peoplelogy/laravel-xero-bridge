<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Tests\Support;

/**
 * Boots with the webhook under a prefix of its own -- the API-style layout a
 * host gets with XERO_WEBHOOK_PREFIX=api/v1/xero -- while connect, callback
 * and the console stay under a different routes.prefix. Routes are registered
 * at boot, so, as for CustomPrefixTestCase, this has to happen before the
 * application starts.
 *
 * The console is switched on so its route exists to prove it did NOT follow
 * the webhook.
 */
class WebhookPrefixTestCase extends WebRoutesTestCase
{
    public function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('xero-bridge.routes.prefix', 'admin/xero');
        $app['config']->set('xero-bridge.webhooks.prefix', 'api/v1/xero');
        $app['config']->set('xero-bridge.redirect_uri', 'https://example.test/admin/xero/callback');
        $app['config']->set('xero-bridge.console.enabled', true);
    }
}
