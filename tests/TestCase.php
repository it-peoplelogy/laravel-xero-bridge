<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Tests;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use Peoplelogy\XeroBridge\XeroBridgeServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Hermeticity gate. Any request the suite makes that is not explicitly
        // stubbed now throws instead of quietly reaching api.xero.com. This
        // lives in setUp() rather than a Pest beforeEach so it also covers any
        // plain PHPUnit class added later.
        Http::preventStrayRequests();

        // Xero's token maths -- 30-minute access tokens, a 60-second refresh
        // leeway, a 30-minute rotation grace window -- is unreadable against a
        // moving clock. Individual tests call travelTo() from here.
        Carbon::setTestNow('2026-01-15 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            XeroBridgeServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            // Explicitly empty. pips applies a connection-level 'pips_' prefix,
            // so pinning '' here proves no query in the package assumes one.
            'prefix' => '',
        ]);

        // The encrypted casts need a key. Generated per boot so that no fixed
        // value can ever be mistaken for a real credential, and so copying this
        // file into an app cannot weaken that app's key.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // Obviously-fake Xero config. Nothing here is read from a real .env.
        $app['config']->set('xero-bridge.client_id', 'test-client-id');
        $app['config']->set('xero-bridge.client_secret', 'test-client-secret');
        $app['config']->set('xero-bridge.redirect_uri', 'https://example.test/xero/callback');
        $app['config']->set('xero-bridge.webhook_key', 'test-webhook-key');

        // account_code has no shipped default on purpose, so configure it
        // here exactly as a consuming application must.
        $app['config']->set('xero-bridge.connections.default.account_code', '200');

        $app['config']->set('queue.default', 'sync');
        $app['config']->set('cache.default', 'array');
    }

    /**
     * Package migrations ship as .php.stub so they can be published with a
     * fresh timestamp, and Laravel's migrator only globs *.php -- so
     * loadMigrationsFrom() would find nothing. Include and run them directly.
     */
    protected function defineDatabaseMigrations(): void
    {
        foreach (File::allFiles(__DIR__.'/../database/migrations') as $migration) {
            (include $migration->getRealPath())->up();
        }
    }
}
