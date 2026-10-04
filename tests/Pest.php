<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Peoplelogy\XeroBridge\Models\XeroConnection;
use Peoplelogy\XeroBridge\Tests\Support\ConsoleDisabledTestCase;
use Peoplelogy\XeroBridge\Tests\Support\ConsoleTestCase;
use Peoplelogy\XeroBridge\Tests\Support\ConsoleUnsetTestCase;
use Peoplelogy\XeroBridge\Tests\Support\CustomPrefixTestCase;
use Peoplelogy\XeroBridge\Tests\Support\WebhookPrefixTestCase;
use Peoplelogy\XeroBridge\Tests\Support\WebRoutesTestCase;
use Peoplelogy\XeroBridge\Tests\TestCase;

/*
| Bound per directory rather than with a single ->in(__DIR__), because Pest
| refuses to let a file-level uses() override a directory binding, and the
| route tests must boot with different middleware. Routes are registered
| during boot, so that choice has to be made before the application starts --
| see tests/Support/WebRoutesTestCase.php.
*/

uses(TestCase::class)->in('Unit');
uses(WebRoutesTestCase::class)->in('Feature');
uses(CustomPrefixTestCase::class)->in('Prefix');
uses(ConsoleTestCase::class)->in('Console');
uses(ConsoleDisabledTestCase::class)->in('ConsoleDisabled');
uses(ConsoleUnsetTestCase::class)->in('ConsoleUnset');
uses(WebhookPrefixTestCase::class)->in('WebhookPrefix');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
|
| Deliberately minimal. Every custom helper and expectation added here is
| something that has to behave identically on Pest 3, 4 and 5 -- the suite
| runs on all three across the CI matrix.
|
*/

/**
 * A saved, healthy connection row.
 */
function connection(array $overrides = []): XeroConnection
{
    return XeroConnection::create(array_merge([
        'key' => 'default',
        'tenant_id' => 'tenant-1',
        'connection_id' => 'conn-1',
        'tenant_name' => 'Acme Sdn Bhd',
        'tenant_type' => 'ORGANISATION',
        'auth_event_id' => 'auth-1',
        'access_token' => 'access-token-1',
        'refresh_token' => 'refresh-token-1',
        'expires_at' => now()->addMinutes(30),
        'scopes' => 'openid profile email offline_access accounting.invoices accounting.settings',
    ], $overrides));
}

/**
 * A token-refresh lock store that genuinely excludes other processes, so a
 * test can start from a HEALTHY `xero-bridge:status --strict`.
 *
 * The base TestCase runs on the `array` cache, whose locks only exclude within
 * one process. That is fine for a test and exactly what --strict exists to
 * refuse, so without this every --strict test would fail before it reached the
 * thing it is about. Creates the table Laravel's database store takes its locks
 * in, defines that store explicitly -- so no Testbench skeleton's cache.php is
 * relied on -- and points tokens.lock_store at it.
 *
 * Call it inside the test, once the application has booted. Only locks are
 * taken through it, so the store's own `cache` table is not needed.
 */
function healthyLockStore(): void
{
    Schema::create('cache_locks', function (Blueprint $table) {
        $table->string('key')->primary();
        $table->string('owner');
        $table->integer('expiration');
    });

    config()->set('cache.stores.database', [
        'driver' => 'database',
        'connection' => null,
        'table' => 'cache',
        'lock_connection' => null,
        'lock_table' => 'cache_locks',
    ]);

    // In case anything resolved the store before its definition changed.
    Cache::forgetDriver('database');

    config()->set('xero-bridge.tokens.lock_store', 'database');
}

/**
 * Run xero-bridge:install, insist that it exits 0, and return everything it
 * printed -- having already deleted everything it published.
 *
 * The command really publishes, and under Testbench config_path() and
 * database_path() point into the shared skeleton in vendor/, which every later
 * boot on this machine loads. So the files go in the same breath, not in an
 * afterEach: a published config sitting there is what the suite would boot
 * against next.
 *
 * Captured whole through Artisan::call() rather than asserted with
 * expectsOutputToContain(): that hands each written line to the FIRST
 * expectation it matches, so two expected phrases on one line cannot both be
 * met, and a forbidden phrase on a line that also holds an expected one is
 * never checked at all.
 */
function installCommandOutput(): string
{
    try {
        $exitCode = Artisan::call('xero-bridge:install');
        $output = Artisan::output();
    } finally {
        File::delete([config_path('xero-bridge.php'), config_path('myinvois.php')]);

        foreach (['*_create_xero_*_table.php', '*_create_myinvois_validations_table.php'] as $migration) {
            File::delete(File::glob(database_path('migrations/'.$migration)) ?: []);
        }
    }

    expect($exitCode)->toBe(0);

    return $output;
}

/**
 * The rate-limit headers Xero returns on every response. Including them by
 * default means the client's header-parsing path is exercised everywhere
 * rather than in one dedicated test.
 */
function xeroHeaders(array $overrides = []): array
{
    return array_merge([
        'X-MinLimit-Remaining' => '59',
        'X-DayLimit-Remaining' => '4999',
        'X-AppMinLimit-Remaining' => '9999',
    ], $overrides);
}
