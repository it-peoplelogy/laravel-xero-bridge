<?php

declare(strict_types=1);

use Peoplelogy\XeroBridge\Models\XeroConnection;
use Peoplelogy\XeroBridge\Tests\Support\CustomPrefixTestCase;
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
