<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Peoplelogy\XeroBridge\MyInvois\MyInvoisConfig;
use Peoplelogy\XeroBridge\MyInvois\MyInvoisException;

function myinvoisConfig(): MyInvoisConfig
{
    return app(MyInvoisConfig::class);
}

/**
 * Read from the config SOURCE, not from the resolved value.
 *
 * phpunit.xml.dist forces MYINVOIS_* so a developer's exported credential
 * cannot leak into the suite, which means config('myinvois.enabled') reflects
 * that force rather than what the package ships. The only honest way to assert
 * a shipped default is to read the file.
 *
 * @return array<string, mixed>
 */
function shippedMyInvoisConfig(): array
{
    return require __DIR__.'/../../config/myinvois.php';
}

/*
|--------------------------------------------------------------------------
| Shipped defaults
|--------------------------------------------------------------------------
*/

it('ships disabled', function () {
    // Load-bearing. Upgrading this package must not add a panel, a warning or
    // a startup cost for the consumers who will never use LHDN.
    //
    // Asserted on the SOURCE. phpunit.xml.dist forces MYINVOIS_ENABLED=false,
    // so checking the resolved value would pass no matter what the file said.
    $source = file_get_contents(__DIR__.'/../../config/myinvois.php');

    expect($source)->toContain("env('MYINVOIS_ENABLED', false)");
});

it('ships pointing at the sandbox', function () {
    // Source again, and for the same reason: production is not somewhere a
    // package should send a consumer by default.
    $source = file_get_contents(__DIR__.'/../../config/myinvois.php');

    expect($source)->toContain("env('MYINVOIS_ENVIRONMENT', 'sandbox')");
});

it('gives no fallback default to any credential', function (string $envKey) {
    // A wrong-but-present credential becomes an opaque 401 hours later; a
    // missing one throws at the point of use naming the key.
    //
    // Asserted against the config SOURCE, not the resolved value: phpunit.xml.dist
    // forces these env vars for the suite, so the resolved value is never null
    // here even though the file supplies no default. Same technique as ConfigTest.
    $source = file_get_contents(__DIR__.'/../../config/myinvois.php');

    expect($source)->toContain("env('{$envKey}')")
        ->and($source)->not->toContain("env('{$envKey}',");
})->with([
    'MYINVOIS_CLIENT_ID',
    'MYINVOIS_CLIENT_SECRET',
    'MYINVOIS_ON_BEHALF_OF',
]);

it('ships with result caching off for both outcomes', function () {
    $shipped = shippedMyInvoisConfig();

    expect($shipped['results']['matched_ttl'])->toBe(0)
        ->and($shipped['results']['unmatched_ttl'])->toBe(0);
});

it('ships the documented LHDN hosts', function () {
    $shipped = shippedMyInvoisConfig();

    expect($shipped['base_urls']['production'])->toBe('https://api.myinvois.hasil.gov.my')
        ->and($shipped['base_urls']['sandbox'])->toBe('https://preprod-api.myinvois.hasil.gov.my');
});

it('offers no TLS verification switch', function () {
    // LHDN's own docs tell people to disable SSL verification on cURL error 60.
    // If the key does not exist, it cannot be reached for at 5pm.
    $flat = json_encode(shippedMyInvoisConfig());

    expect($flat)->not->toContain('verify')
        ->not->toContain('ssl');
});

/*
|--------------------------------------------------------------------------
| Registration
|--------------------------------------------------------------------------
*/

it('merges under its own top-level key', function () {
    expect(config('myinvois'))->toBeArray()
        ->and(config('myinvois.base_urls.sandbox'))->toBe('https://preprod-api.myinvois.hasil.gov.my');
});

it('does not pollute the xero-bridge config', function () {
    // Two separate files with two separate publish tags, so republishing one
    // can never overwrite the other.
    expect(config('xero-bridge.myinvois'))->toBeNull()
        ->and(config('myinvois.client_id'))->not->toBe(config('xero-bridge.client_id'));
});

it('publishes under its own tag', function () {
    $groups = ServiceProvider::$publishGroups;

    expect($groups)->toHaveKey('myinvois-config');

    $paths = array_values($groups['myinvois-config']);

    expect($paths)->toHaveCount(1)
        ->and($paths[0])->toEndWith('myinvois.php');
});

it('works when the config file was never published', function () {
    // The merge happens in register(), before a cached config file is written,
    // so a consumer who never publishes still gets every default. The env()
    // ban exists because this package has been burned by config:cache before,
    // so the reasoning is asserted rather than trusted.
    expect(config('myinvois.token.leeway'))->toBe(60)
        ->and(config('myinvois.http.retries'))->toBe(3);
});

/*
|--------------------------------------------------------------------------
| URLs
|--------------------------------------------------------------------------
*/

it('derives both URLs from one environment switch', function (string $environment, string $host) {
    // The identity service lives on the same host as the API, so the token call
    // and the validate call can never disagree about which environment they are
    // talking to.
    config()->set('myinvois.environment', $environment);

    expect(myinvoisConfig()->tokenUrl())->toBe($host.'/connect/token')
        ->and(myinvoisConfig()->validateUrl('C1'))->toBe($host.'/api/v1.0/taxpayer/validate/C1');
})->with([
    'sandbox' => ['sandbox', 'https://preprod-api.myinvois.hasil.gov.my'],
    'production' => ['production', 'https://api.myinvois.hasil.gov.my'],
]);

it('refuses an environment that is neither', function () {
    config()->set('myinvois.environment', 'staging');

    expect(fn () => myinvoisConfig()->baseUrl())
        ->toThrow(MyInvoisException::class, 'sandbox');
});

it('url-encodes a TIN into the path', function () {
    config()->set('myinvois.environment', 'sandbox');

    expect(myinvoisConfig()->validateUrl('C 1/2'))->toEndWith('/validate/C%201%2F2');
});

/*
|--------------------------------------------------------------------------
| Credentials
|--------------------------------------------------------------------------
*/

it('names the env key when a credential is missing', function () {
    config()->set('myinvois.client_id', null);

    expect(fn () => myinvoisConfig()->clientId())
        ->toThrow(MyInvoisException::class, 'MYINVOIS_CLIENT_ID');
});

it('rejects an obvious placeholder before spending a request', function (string $placeholder) {
    // LHDN auto-blocks a Client ID that sends placeholder values to the token
    // endpoint, so an unfilled environment file must not reach them at all.
    config()->set('myinvois.client_id', $placeholder);

    expect(fn () => myinvoisConfig()->clientId())
        ->toThrow(MyInvoisException::class, 'placeholder');
})->with(['0', 'YOUR_CLIENT_ID', 'your_client_id', 'changeme', 'TBD', '  xxx  ']);

it('never treats a real credential containing a placeholder word as one', function (string $real) {
    // Matching is exact, not substring. Locking someone out of a working
    // integration because their secret contains "xxx" is worse than no guard.
    config()->set('myinvois.client_id', $real);

    expect(myinvoisConfig()->clientId())->toBe($real);
})->with([
    'contains xxx' => ['a7xxx9f2e1b4c8d3'],
    'contains 0' => ['0f8e7d6c5b4a3921'],
    'contains none' => ['nonesuch-9f2e1b4c'],
    'contains tbd' => ['tbd8815f-4c2a-11ee'],
]);

/*
|--------------------------------------------------------------------------
| problems()
|--------------------------------------------------------------------------
*/

it('reports no problems while disabled, however broken the settings are', function () {
    // The console must not nag a consumer about credentials they will never set.
    config()->set('myinvois.enabled', false);
    config()->set('myinvois.client_id', null);
    config()->set('myinvois.client_secret', null);
    config()->set('myinvois.environment', 'nonsense');

    expect(myinvoisConfig()->problems())->toBe([]);
});

it('lists what is wrong once enabled', function () {
    config()->set('myinvois.enabled', true);
    config()->set('myinvois.client_id', null);
    config()->set('myinvois.client_secret', 'YOUR_CLIENT_SECRET');

    $problems = myinvoisConfig()->problems();

    expect($problems)->toHaveCount(2)
        ->and(implode(' ', $problems))->toContain('MYINVOIS_CLIENT_ID')
        ->and(implode(' ', $problems))->toContain('placeholder');
});

it('reports nothing when it is properly configured', function () {
    config()->set('myinvois.enabled', true);
    config()->set('myinvois.client_id', 'real-id');
    config()->set('myinvois.client_secret', 'real-secret');

    expect(myinvoisConfig()->problems())->toBe([]);
});
