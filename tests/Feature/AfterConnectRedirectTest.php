<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Peoplelogy\XeroBridge\Models\XeroConnection;
use Peoplelogy\XeroBridge\XeroBridgeManager;

/**
 * Regression tests for a hook that existed but was never wired up.
 *
 * XeroBridgeManager::redirectAfterConnectUsing() was public and documented,
 * and the callback controller never consulted it -- so registering a callback
 * did nothing at all, silently. A config file cannot hold a closure once
 * cached, which is the only reason this hook exists, so "it is there but does
 * nothing" is the worst possible state for it.
 */
afterEach(function () {
    // Static state on the manager would otherwise bleed into later tests.
    XeroBridgeManager::redirectAfterConnectUsing(fn () => null);
});

function completeConnectFlow(): void
{
    $response = test()->get('/xero/connect/default');
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    Http::fake([
        'identity.xero.com/connect/token' => Http::response([
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'expires_in' => 1800,
            'scope' => 'offline_access',
        ]),
        'api.xero.com/connections' => Http::response([[
            'id' => 'conn-1',
            'tenantId' => 'tenant-1',
            'tenantType' => 'ORGANISATION',
            'tenantName' => 'Acme Sdn Bhd',
        ]]),
    ]);

    test()->get("/xero/callback?code=the-code&state={$query['state']}");
}

it('sends the user where the registered callback says', function () {
    XeroBridgeManager::redirectAfterConnectUsing(
        fn (?XeroConnection $connection) => '/accounting/connected/'.$connection?->key
    );

    completeConnectFlow();

    expect(session('xero-bridge.status'))->toContain('Acme Sdn Bhd')
        ->and(XeroConnection::count())->toBe(1);
});

it('passes the stored connection to the callback', function () {
    $seen = null;

    XeroBridgeManager::redirectAfterConnectUsing(function (?XeroConnection $connection) use (&$seen) {
        $seen = $connection;

        return '/done';
    });

    completeConnectFlow();

    expect($seen)->toBeInstanceOf(XeroConnection::class)
        ->and($seen->tenant_id)->toBe('tenant-1')
        ->and($seen->displayName())->toBe('Acme Sdn Bhd');
});

it('takes precedence over the configured path', function () {
    config()->set('xero-bridge.routes.after_connect_redirect', '/from-config');
    XeroBridgeManager::redirectAfterConnectUsing(fn () => '/from-callback');

    $response = test()->get('/xero/connect/default');
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    Http::fake([
        'identity.xero.com/connect/token' => Http::response([
            'access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 1800, 'scope' => 'offline_access',
        ]),
        'api.xero.com/connections' => Http::response([[
            'id' => 'c', 'tenantId' => 't', 'tenantType' => 'ORGANISATION', 'tenantName' => 'Acme',
        ]]),
    ]);

    $this->get("/xero/callback?code=x&state={$query['state']}")->assertRedirect('/from-callback');
});

it('falls back to the configured path when the callback returns nothing usable', function () {
    config()->set('xero-bridge.routes.after_connect_redirect', '/from-config');
    // A callback that returns null must not produce a redirect to "".
    XeroBridgeManager::redirectAfterConnectUsing(fn () => null);

    $response = test()->get('/xero/connect/default');
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    Http::fake([
        'identity.xero.com/connect/token' => Http::response([
            'access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 1800, 'scope' => 'offline_access',
        ]),
        'api.xero.com/connections' => Http::response([[
            'id' => 'c', 'tenantId' => 't', 'tenantType' => 'ORGANISATION', 'tenantName' => 'Acme',
        ]]),
    ]);

    $this->get("/xero/callback?code=x&state={$query['state']}")->assertRedirect('/from-config');
});

it('also applies on the failure path', function () {
    XeroBridgeManager::redirectAfterConnectUsing(fn (?XeroConnection $c) => $c === null ? '/failed' : '/ok');

    // No state was ever issued, so this fails verification.
    $this->get('/xero/callback?code=x&state=forged')->assertRedirect('/failed');

    expect(Route::has('xero-bridge.callback'))->toBeTrue();
});
