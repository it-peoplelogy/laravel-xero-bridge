<?php

declare(strict_types=1);

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Peoplelogy\XeroBridge\Support\Diagnostics;

/**
 * Diagnostics is shared by xero-bridge:status and the test console, so these
 * pin the shape both of them read.
 */
function diagnostics(): Diagnostics
{
    return app(Diagnostics::class);
}

/*
|--------------------------------------------------------------------------
| Missing configuration
|--------------------------------------------------------------------------
*/

it('names the environment variable behind each missing setting', function () {
    config()->set('xero-bridge.client_id', null);
    config()->set('xero-bridge.client_secret', '');
    config()->set('xero-bridge.scopes', 'openid profile');

    expect(diagnostics()->missingConfig())->toBe([
        'client_id' => 'XERO_CLIENT_ID',
        'client_secret' => 'XERO_CLIENT_SECRET',
        'scopes' => 'XERO_SCOPES (must include offline_access)',
    ]);
});

it('reports nothing missing when the configuration is complete', function () {
    expect(diagnostics()->missingConfig())->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Describing a connection
|--------------------------------------------------------------------------
*/

it('describes a connection without ever exposing a token', function () {
    $row = diagnostics()->describe(connection());

    expect($row['key'])->toBe('default')
        ->and($row['organisation'])->toBe('Acme Sdn Bhd')
        ->and($row['expired'])->toBeFalse()
        ->and($row['usable'])->toBeTrue()
        ->and($row['needs_reauthorisation'])->toBeFalse()
        ->and($row['expires_in'])->toBe(1800)
        ->and($row['scopes'])->toContain('offline_access');

    // The model hides the tokens, but this array is built key by key, so the
    // guarantee has to be asserted rather than assumed.
    $encoded = json_encode($row);

    expect($encoded)->not->toContain('access-token-1')
        ->not->toContain('refresh-token-1');
});

it('keeps the status command json shape stable', function () {
    // xero-bridge:status --json can be wired to monitoring, so its keys are a
    // contract. If a console-only field is ever needed, append it -- do not
    // reorder or rename what is already here.
    expect(array_keys(diagnostics()->describe(connection())))->toBe([
        'key',
        'organisation',
        'tenant_id',
        'tenant_type',
        'expires_at',
        'expires_in',
        'expired',
        'usable',
        'needs_reauthorisation',
        'invalidated_reason',
        'failure_count',
        'last_refreshed_at',
        'last_failure_at',
        'scopes',
        'connect_url',
    ]);
});

it('flags a connection that needs reauthorising', function () {
    $row = diagnostics()->describe(connection([
        'invalidated_at' => now(),
        'invalidated_reason' => 'invalid_grant',
    ]));

    expect($row['needs_reauthorisation'])->toBeTrue()
        ->and($row['usable'])->toBeFalse()
        ->and($row['invalidated_reason'])->toBe('invalid_grant');
});

/*
|--------------------------------------------------------------------------
| Warnings
|--------------------------------------------------------------------------
*/

it('warns when no webhook key is set', function () {
    config()->set('xero-bridge.webhook_key', null);

    expect(diagnostics()->warnings([]))
        ->toContain('No XERO_WEBHOOK_KEY is set, so every webhook will be rejected with a 401.');
});

it('warns when the cache store cannot lock across processes', function () {
    config()->set('cache.default', 'file');
    config()->set('xero-bridge.tokens.lock_store', null);

    expect(implode(' ', diagnostics()->warnings([])))->toContain('XERO_LOCK_STORE');
});

it('warns about transient failures, but not on a dead connection', function () {
    $failing = diagnostics()->describe(connection(['failure_count' => 3]));

    expect(implode(' ', diagnostics()->warnings([$failing])))
        ->toContain('3 recent transient failure(s)');

    // Once it needs reauthorising, the failure count is no longer the story.
    $dead = diagnostics()->describe(connection([
        'key' => 'dead',
        'tenant_id' => 'tenant-2',
        'failure_count' => 3,
        'invalidated_at' => now(),
    ]));

    expect(implode(' ', diagnostics()->warnings([$dead])))
        ->not->toContain('transient failure');
});

/*
|--------------------------------------------------------------------------
| Pre-flight
|--------------------------------------------------------------------------
*/

it('passes pre-flight with a valid configuration', function () {
    $preflight = diagnostics()->preflight();

    expect($preflight['connect']['ok'])->toBeTrue()
        ->and($preflight['lock']['ok'])->toBeTrue();
});

it('fails pre-flight on a redirect URI Xero would reject', function () {
    // Xero rejects http://127.0.0.1 explicitly, and its own error for that is
    // vague -- which is exactly why this is proven up front.
    config()->set('xero-bridge.redirect_uri', 'http://127.0.0.1/xero/callback');

    $connect = diagnostics()->preflight()['connect'];

    expect($connect['ok'])->toBeFalse()
        ->and($connect['severity'])->toBe('fail')
        ->and($connect['type'])->toBe('XeroConfigurationException');
});

it('warns rather than fails when the cache store supports no locks', function () {
    Cache::extend('nolock', fn ($app) => new Repository(
        new class implements Store
        {
            public function get($key) {}

            public function many(array $keys)
            {
                return [];
            }

            public function put($key, $value, $seconds)
            {
                return true;
            }

            public function putMany(array $values, $seconds)
            {
                return true;
            }

            public function increment($key, $value = 1)
            {
                return 1;
            }

            public function decrement($key, $value = 1)
            {
                return 1;
            }

            public function forever($key, $value)
            {
                return true;
            }

            // Added to the Store contract in Laravel 13. Declared
            // unconditionally: an extra method is harmless on 11 and 12.
            public function touch($key, $seconds)
            {
                return true;
            }

            public function forget($key)
            {
                return true;
            }

            public function flush()
            {
                return true;
            }

            public function getPrefix()
            {
                return '';
            }
        }
    ));

    config()->set('cache.stores.nolock', ['driver' => 'nolock']);
    config()->set('xero-bridge.tokens.lock_store', 'nolock');

    $lock = diagnostics()->preflight()['lock'];

    // A warning, not a failure: the bridge logs and carries on unlocked, which
    // is fine in one process and loses rotated refresh tokens across several.
    expect($lock['ok'])->toBeFalse()
        ->and($lock['severity'])->toBe('warn')
        ->and($lock['message'])->toContain('XERO_LOCK_STORE');
});

it('leaves no lock behind after probing', function () {
    diagnostics()->preflight();

    expect(Cache::lock('xero-bridge:preflight-probe', 2)->get())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Environment and URLs
|--------------------------------------------------------------------------
*/

it('reports credentials as booleans and never as values', function () {
    $environment = diagnostics()->environment();

    expect($environment['client_id_set'])->toBeTrue()
        ->and($environment['client_secret_set'])->toBeTrue()
        ->and($environment)->not->toHaveKey('client_secret')
        ->and($environment)->not->toHaveKey('client_id')
        ->and($environment['has_offline_access'])->toBeTrue();

    expect(json_encode($environment))->not->toContain('test-client-secret');
});

it('reports the table as the database will see it', function () {
    // The package stores a bare name and lets the host connection apply its
    // own prefix, so the configured value alone would be misleading. The
    // Prefix suite covers the prefixed case.
    expect(diagnostics()->environment()['table'])->toBe('xero_connections');
});

it('never puts a token in the console snapshot', function () {
    connection();

    expect(json_encode(diagnostics()->snapshot()))
        ->not->toContain('access-token-1')
        ->not->toContain('refresh-token-1')
        ->not->toContain('test-client-secret');
});

it('returns null for a URL whose route is not registered', function () {
    // A host may set routes.enabled=false or webhooks.enabled=false. Without
    // the Route::has() guards, route() would throw and take the page with it.
    config()->set('xero-bridge.routes.name_prefix', 'nope.');

    $urls = diagnostics()->urls();

    expect($urls['callback'])->toBeNull()
        ->and($urls['webhook'])->toBeNull()
        // connectUrl() falls back to a plain path, so guidance stays useful.
        ->and($urls['connect'])->toBe('/xero/connect/default');
});

/*
|--------------------------------------------------------------------------
| MyInvois must stay invisible here
|--------------------------------------------------------------------------
|
| Diagnostics feeds both the console status panel and xero-bridge:status, and
| the command is what consumers wire to monitoring. A Malaysia-only module that
| most installations will never enable must not appear in either -- not as a
| missing-config entry, not as a warning, and above all not as a non-zero exit
| code that pages somebody.
*/

it('never reports MyInvois to a consumer who has not enabled it', function () {
    config()->set('myinvois.enabled', false);
    config()->set('myinvois.client_id', null);
    config()->set('myinvois.client_secret', null);

    $diagnostics = diagnostics();

    expect(json_encode($diagnostics->missingConfig()))->not->toContain('MYINVOIS')
        ->and(json_encode($diagnostics->warnings([])))->not->toContain('MYINVOIS')
        ->and(json_encode($diagnostics->environment()))->not->toContain('myinvois');
});

it('keeps xero-bridge:status silent and green about MyInvois', function () {
    config()->set('myinvois.enabled', false);
    config()->set('myinvois.client_id', null);
    config()->set('myinvois.client_secret', null);

    connection();

    Artisan::call('xero-bridge:status');

    expect(Artisan::output())->not->toContain('MyInvois')
        ->not->toContain('MYINVOIS');

    expect(Artisan::call('xero-bridge:status'))->toBe(0);
});

it('stays silent even when MyInvois is enabled but unconfigured', function () {
    // The Xero command reports on Xero. A broken LHDN configuration is the
    // console panel's business, not something that should turn a Xero health
    // check red.
    config()->set('myinvois.enabled', true);
    config()->set('myinvois.client_id', null);

    connection();

    expect(Artisan::call('xero-bridge:status'))->toBe(0);
    expect(Artisan::output())->not->toContain('MYINVOIS');
});
