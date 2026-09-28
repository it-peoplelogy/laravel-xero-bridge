<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Peoplelogy\XeroBridge\Exceptions\XeroConfigurationException;

it('registers the routes when enabled', function () {
    expect(Route::has('xero-bridge.connect'))->toBeTrue()
        ->and(Route::has('xero-bridge.callback'))->toBeTrue();
});

it('redirects to Xero with the state stored in the session', function () {
    $response = $this->get('/xero/connect');

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://login.xero.com/identity/connect/authorize')
        ->and($query['response_type'])->toBe('code')
        ->and($query['client_id'])->toBe('test-client-id')
        ->and($query['redirect_uri'])->toBe('https://example.test/xero/callback')
        ->and($query['state'])->not->toBeEmpty()
        ->and($query['scope'])->toContain('offline_access');

    // Scope separators must be %20, not '+': http_build_query's default
    // encoding would produce the latter and Xero wants the former.
    expect($location)->toContain('scope=openid%20profile')
        ->and($location)->not->toContain('scope=openid+profile');
});

it('treats a missing key as the default connection', function () {
    $this->get('/xero/connect');

    $entries = session('xero-bridge.oauth');

    expect($entries)->toHaveCount(1)
        ->and(reset($entries)['key'])->toBe('default');
});

it('stores the named key in the state entry', function () {
    // The key cannot travel in the callback URL -- Xero allows one registered
    // redirect_uri -- and must not travel in a query string an attacker could
    // set, so it rides in the session-side state entry.
    $this->get('/xero/connect/acme');

    $entries = session('xero-bridge.oauth');

    expect(reset($entries)['key'])->toBe('acme');
});

it('keeps both nonces when two tabs start the flow', function () {
    $this->get('/xero/connect/one');
    $this->get('/xero/connect/two');

    expect(session('xero-bridge.oauth'))->toHaveCount(2);
});

it('rejects a 127.0.0.1 redirect uri with the fix in the message', function () {
    config()->set('xero-bridge.redirect_uri', 'http://127.0.0.1:8000/xero/callback');

    // Xero's own error for this is uselessly vague.
    expect(fn () => $this->withoutExceptionHandling()->get('/xero/connect'))
        ->toThrow(XeroConfigurationException::class, 'http://localhost');
});

it('allows http://localhost for testing', function () {
    config()->set('xero-bridge.redirect_uri', 'http://localhost:8000/xero/callback');

    $this->get('/xero/connect')->assertRedirect();
});

it('refuses to start without offline_access', function () {
    config()->set('xero-bridge.scopes', 'openid profile accounting.invoices');

    // Without it Xero issues no refresh token and the connection dies 30
    // minutes later -- so fail now, not then.
    expect(fn () => $this->withoutExceptionHandling()->get('/xero/connect'))
        ->toThrow(XeroConfigurationException::class, 'offline_access');
});

it('refuses to start without a client id', function () {
    config()->set('xero-bridge.client_id', null);

    expect(fn () => $this->withoutExceptionHandling()->get('/xero/connect'))
        ->toThrow(XeroConfigurationException::class, 'XERO_CLIENT_ID');
});

it('never calls Xero while starting the flow', function () {
    $this->get('/xero/connect');

    Http::assertNothingSent();
});
