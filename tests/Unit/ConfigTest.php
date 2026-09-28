<?php

declare(strict_types=1);

use Peoplelogy\XeroBridge\Models\XeroConnection;
use Peoplelogy\XeroBridge\Support\Scopes;

it('merges the package config under the xero-bridge key', function () {
    expect(config('xero-bridge'))->toBeArray()
        ->and(config('xero-bridge.default_connection'))->toBe('default');
});

it('ships granular scopes including offline_access', function () {
    $scopes = Scopes::parse(config('xero-bridge.scopes'));

    // Without offline_access Xero issues no refresh token at all and the
    // connection dies 30 minutes after it is made.
    expect($scopes)->toContain('offline_access')
        ->toContain('accounting.invoices')
        ->toContain('accounting.payments')
        ->toContain('accounting.contacts')
        ->toContain('accounting.settings')
        ->toContain('accounting.attachments')
        // The broad scope the granular ones replace.
        ->not->toContain('accounting.transactions');
});

it('gives no fallback default to any secret', function (string $envKey) {
    // A wrong-but-present default turns a misconfiguration into an opaque 401
    // from Xero hours later, instead of a clear failure at the point of use.
    //
    // This asserts on the config SOURCE rather than the resolved value,
    // because phpunit.xml.dist sets these env vars for the suite -- so the
    // resolved value is never null here even though there is no default.
    $source = file_get_contents(__DIR__.'/../../config/xero-bridge.php');

    expect($source)->toContain("env('{$envKey}')")
        ->and($source)->not->toContain("env('{$envKey}',");
})->with([
    'XERO_CLIENT_ID',
    'XERO_CLIENT_SECRET',
    'XERO_WEBHOOK_KEY',
    'XERO_REDIRECT_URI',
    'XERO_ACCOUNT_CODE',
    'XERO_TAX_TYPE',
    'XERO_BRANDING_THEME_ID',
]);

it('ships a currency default but never an account code or tax type', function () {
    // An account code is meaningless across organisations -- one org's sales
    // account may be '200', another's '410002-001'. A wrong-but-present default
    // would post to the wrong ledger account and Xero would accept it happily,
    // so there is deliberately none.
    $fresh = require __DIR__.'/../../config/xero-bridge.php';

    expect(data_get($fresh, 'connections.default.account_code'))->toBeNull()
        ->and(data_get($fresh, 'connections.default.currency'))->toBe('MYR')
        // Malaysian SST is per LINE: training 8%, education and rental 6%. A
        // connection-wide default would stamp the wrong rate on a recharge.
        ->and(data_get($fresh, 'connections.default.tax_type'))->toBeNull();
});

it('keeps the token lock timings interlocked', function () {
    $http = (int) config('xero-bridge.tokens.http_timeout');
    $ttl = (int) config('xero-bridge.tokens.lock_ttl');
    $wait = (int) config('xero-bridge.tokens.lock_wait');

    // A holder must never outlive its lock, and a waiter must never give up
    // before a dead holder's lock has expired.
    expect($http)->toBeLessThan($ttl)
        ->and($ttl)->toBeLessThan($wait);
});

it('parses the route middleware from a comma separated string', function () {
    expect(config('xero-bridge.routes.middleware'))->toBe(['web', 'auth']);
});

it('points the model config at the package model', function () {
    expect(config('xero-bridge.model'))->toBe(XeroConnection::class);
});
