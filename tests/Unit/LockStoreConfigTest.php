<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Peoplelogy\XeroBridge\OAuth\TokenManager;
use Peoplelogy\XeroBridge\Support\Diagnostics;
use Peoplelogy\XeroBridge\Support\XeroConfig;

/**
 * A blank XERO_LOCK_STORE means the default store, on every Laravel release.
 *
 * env() reads a bare `XERO_LOCK_STORE=` as '' and `XERO_LOCK_STORE=false` as
 * false. Laravel 11 and 12 before 12.43 hand either to the cache manager and
 * get the default store; 12.43+ and 13 look the store up by that name and throw
 * "Cache store [] is not defined" -- so a host that copied the key out of an
 * example file would have every token refresh fail. XeroConfig::lockStore()
 * is the one reading of the key, and these pin each place that relies on it.
 */
dataset('blank lock store values', [
    'a bare XERO_LOCK_STORE=' => [''],
    'XERO_LOCK_STORE=false' => [false],
    'unset' => [null],
]);

it('reads only a non-empty string as naming a store', function (mixed $value, ?string $expected) {
    config()->set('xero-bridge.tokens.lock_store', $value);

    expect(app(XeroConfig::class)->lockStore())->toBe($expected);
})->with([
    'unset' => [null, null],
    'a bare XERO_LOCK_STORE=' => ['', null],
    'XERO_LOCK_STORE=false' => [false, null],
    'a store name' => ['redis', 'redis'],
]);

it('refreshes a token on the default store when XERO_LOCK_STORE is blank', function (mixed $blank) {
    config()->set('xero-bridge.tokens.lock_store', $blank);

    // 30 seconds left inside a 60-second leeway, so valid() must refresh --
    // and refreshing is what takes the lock.
    connection(['expires_at' => now()->addSeconds(30)]);

    Http::fake([
        'identity.xero.com/connect/token' => Http::response([
            'access_token' => 'access-after-blank-store',
            'refresh_token' => 'refresh-after-blank-store',
            'expires_in' => 1800,
            'token_type' => 'Bearer',
            'scope' => 'openid offline_access accounting.invoices',
        ]),
    ]);

    expect(app(TokenManager::class)->valid()->access_token)->toBe('access-after-blank-store');
})->with('blank lock store values');

it('passes the lock pre-flight when XERO_LOCK_STORE is blank', function (mixed $blank) {
    config()->set('xero-bridge.tokens.lock_store', $blank);

    expect(app(Diagnostics::class)->preflight()['lock']['ok'])->toBeTrue();
})->with('blank lock store values');
