<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Tests\Support;

use Peoplelogy\XeroBridge\Tests\TestCase;

/**
 * Boots with no webhook signing key, and webhooks.enabled left at its shipped
 * default, on: what every application that only calls Xero runs with.
 *
 * Cleared HERE, after the base TestCase sets a key. phpunit.xml.dist forces
 * XERO_WEBHOOK_KEY into the environment, and the providers registered -- the
 * package config read env() -- before this runs, so the config value is the
 * one place left to clear it. Routes are registered at boot, so, as for
 * ConsoleUnsetTestCase, it has to be cleared before the application starts:
 * a test that nulls the key afterwards is the stale route:cache case instead.
 */
class WebhookKeyUnsetTestCase extends TestCase
{
    public function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('xero-bridge.webhook_key', null);
    }
}
