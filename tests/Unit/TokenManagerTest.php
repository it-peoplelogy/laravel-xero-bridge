<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Peoplelogy\XeroBridge\Events\ConnectionExpired;
use Peoplelogy\XeroBridge\Events\TokenRefreshed;
use Peoplelogy\XeroBridge\Exceptions\XeroBridgeException;
use Peoplelogy\XeroBridge\Exceptions\XeroConfigurationException;
use Peoplelogy\XeroBridge\Exceptions\XeroConnectionNotFoundException;
use Peoplelogy\XeroBridge\Exceptions\XeroIdentityUnavailableException;
use Peoplelogy\XeroBridge\Exceptions\XeroReauthorizationRequiredException;
use Peoplelogy\XeroBridge\Models\XeroConnection;
use Peoplelogy\XeroBridge\OAuth\TokenManager;

function tokens(): TokenManager
{
    return app(TokenManager::class);
}

function fakeRefresh(array $overrides = []): void
{
    Http::fake([
        'identity.xero.com/connect/token' => Http::response(array_merge([
            'access_token' => 'access-2',
            // Xero ROTATES: a successful refresh returns a NEW refresh token
            // and invalidates the one that was sent.
            'refresh_token' => 'refresh-2',
            'expires_in' => 1800,
            'token_type' => 'Bearer',
            'scope' => 'openid offline_access accounting.invoices',
        ], $overrides)),
    ]);
}

it('makes no network call when the token is still fresh', function () {
    connection(['expires_at' => now()->addMinutes(20)]);

    expect(tokens()->valid()->access_token)->toBe('access-token-1');

    Http::assertNothingSent();
});

it('refreshes inside the leeway window and saves the rotated refresh token', function () {
    Event::fake([TokenRefreshed::class]);
    // 30s left, and the leeway is 60s.
    connection(['expires_at' => now()->addSeconds(30)]);
    fakeRefresh();

    $connection = tokens()->valid();

    expect($connection->access_token)->toBe('access-2')
        // The whole point of rotation: losing this makes the next refresh fail.
        ->and($connection->refresh_token)->toBe('refresh-2')
        ->and($connection->expires_at->timestamp)->toBe(now()->addSeconds(1800)->timestamp)
        ->and($connection->failure_count)->toBe(0);

    Event::assertDispatched(TokenRefreshed::class);
});

it('sends the refresh grant with basic auth', function () {
    connection(['expires_at' => now()->subMinute()]);
    fakeRefresh();

    tokens()->valid();

    Http::assertSent(fn (Request $r) => $r['grant_type'] === 'refresh_token'
        && $r['refresh_token'] === 'refresh-token-1'
        && $r->hasHeader('Authorization', 'Basic '.base64_encode('test-client-id:test-client-secret')));
});

it('keeps the existing refresh token when Xero returns none', function () {
    connection(['expires_at' => now()->subMinute()]);
    fakeRefresh(['refresh_token' => null]);

    expect(tokens()->valid()->refresh_token)->toBe('refresh-token-1');
});

/*
|--------------------------------------------------------------------------
| The regression that matters
|--------------------------------------------------------------------------
|
| An integration that clears its stored tokens on ANY failed refresh turns one
| transient 502 into a destroyed connection that needs a human with a browser
| to recover it. These tests pin the opposite behaviour.
|
*/

it('never destroys a connection on a transient 5xx', function () {
    Event::fake([ConnectionExpired::class]);
    connection(['expires_at' => now()->subMinute()]);

    Http::fake(['identity.xero.com/connect/token' => Http::response('gateway error', 502)]);

    expect(fn () => tokens()->valid())->toThrow(XeroIdentityUnavailableException::class);

    $connection = XeroConnection::sole();

    expect(XeroConnection::count())->toBe(1)
        ->and($connection->refresh_token)->toBe('refresh-token-1')
        ->and($connection->access_token)->toBe('access-token-1')
        ->and($connection->invalidated_at)->toBeNull()
        // A counter is the ONLY thing that changes.
        ->and($connection->failure_count)->toBe(1);

    Event::assertNotDispatched(ConnectionExpired::class);
});

it('never destroys a connection when the network fails', function () {
    connection(['expires_at' => now()->subMinute()]);

    Http::fake(['identity.xero.com/connect/token' => fn () => throw new ConnectionException('timeout')]);

    expect(fn () => tokens()->valid())->toThrow(XeroIdentityUnavailableException::class);

    expect(XeroConnection::sole()->refresh_token)->toBe('refresh-token-1')
        ->and(XeroConnection::sole()->invalidated_at)->toBeNull();
});

it('treats a 429 from the identity host as transient', function () {
    connection(['expires_at' => now()->subMinute()]);

    Http::fake(['identity.xero.com/connect/token' => Http::response('', 429)]);

    expect(fn () => tokens()->valid())->toThrow(XeroIdentityUnavailableException::class);
    expect(XeroConnection::sole()->invalidated_at)->toBeNull();
});

it('recovers when a transient failure is followed by a success', function () {
    connection(['expires_at' => now()->subMinute()]);

    Http::fake([
        'identity.xero.com/connect/token' => Http::sequence()
            ->push('server error', 500)
            ->push([
                'access_token' => 'access-2',
                'refresh_token' => 'refresh-2',
                'expires_in' => 1800,
                'scope' => 'offline_access',
            ], 200),
    ]);

    expect(tokens()->valid()->access_token)->toBe('access-2');
});

it('marks the connection for re-authorisation only on invalid_grant', function () {
    Event::fake([ConnectionExpired::class]);
    connection(['expires_at' => now()->subMinute()]);

    Http::fake([
        'identity.xero.com/connect/token' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'refresh token expired',
        ], 400),
    ]);

    expect(fn () => tokens()->valid())
        ->toThrow(XeroReauthorizationRequiredException::class, '/xero/connect/default');

    $connection = XeroConnection::sole();

    // Marked, never deleted -- a reconnect clears this.
    expect($connection->invalidated_at)->not->toBeNull()
        ->and($connection->invalidated_reason)->toBe('invalid_grant')
        ->and(XeroConnection::count())->toBe(1);

    Event::assertDispatched(ConnectionExpired::class);
});

it('fires ConnectionExpired only once across repeated failures', function () {
    Event::fake([ConnectionExpired::class]);
    connection(['expires_at' => now()->subMinute()]);

    Http::fake(['identity.xero.com/connect/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    // A nightly cron must not spam whoever is listening.
    expect(fn () => tokens()->valid())->toThrow(XeroReauthorizationRequiredException::class);
    expect(fn () => tokens()->valid())->toThrow(XeroReauthorizationRequiredException::class);
    expect(fn () => tokens()->valid())->toThrow(XeroReauthorizationRequiredException::class);

    Event::assertDispatchedTimes(ConnectionExpired::class, 1);
});

it('short-circuits an already invalidated connection without calling Xero', function () {
    connection(['expires_at' => now()->subMinute(), 'invalidated_at' => now()->subDay()]);

    expect(fn () => tokens()->valid())->toThrow(XeroReauthorizationRequiredException::class);

    // Otherwise a five-minute cron hammers identity.xero.com forever.
    Http::assertNothingSent();
});

it('treats invalid_client as a configuration error, not a dead connection', function () {
    Event::fake([ConnectionExpired::class]);
    connection(['expires_at' => now()->subMinute()]);

    Http::fake([
        'identity.xero.com/connect/token' => Http::response(['error' => 'invalid_client'], 400),
    ]);

    // A typo'd secret must not mark every connection expired and demand that
    // every user re-consents; the fix is one line of .env.
    expect(fn () => tokens()->valid())
        ->toThrow(XeroConfigurationException::class, 'XERO_CLIENT_SECRET');

    expect(XeroConnection::sole()->invalidated_at)->toBeNull();
    Event::assertNotDispatched(ConnectionExpired::class);
});

/*
|--------------------------------------------------------------------------
| Locking
|--------------------------------------------------------------------------
*/

/*
| NOTE: both contention tests unfreeze the clock first. Laravel's
| Lock::block() measures its timeout with Carbon::now(), so under the suite's
| frozen clock it can never time out and the test hangs forever rather than
| failing. The rest of the file keeps the frozen clock, because token expiry
| maths is unreadable against a moving one.
*/

it('returns the winner\'s token instead of refreshing again', function () {
    Carbon::setTestNow();

    connection(['expires_at' => now()->subMinute()]);

    // Another process holds the lock and has already rotated the tokens.
    Cache::lock('xero-bridge:refresh:default', 10)->get();
    XeroConnection::sole()->forceFill([
        'access_token' => 'access-from-winner',
        'refresh_token' => 'refresh-from-winner',
        'expires_at' => now()->addMinutes(30),
    ])->save();

    config()->set('xero-bridge.tokens.lock_wait', 1);

    expect(tokens()->valid()->access_token)->toBe('access-from-winner');

    // Crucially it did NOT refresh unlocked -- doing so is exactly what
    // rotates a refresh token out from under the process holding the lock.
    Http::assertNothingSent();
});

it('throws rather than refreshing unlocked when the holder never finishes', function () {
    Carbon::setTestNow();

    Event::fake([ConnectionExpired::class]);
    connection(['expires_at' => now()->subMinute()]);

    Cache::lock('xero-bridge:refresh:default', 30)->get();
    config()->set('xero-bridge.tokens.lock_wait', 1);

    expect(fn () => tokens()->valid())->toThrow(XeroBridgeException::class, 'Timed out');

    Http::assertNothingSent();
    Event::assertNotDispatched(ConnectionExpired::class);
    expect(XeroConnection::sole()->invalidated_at)->toBeNull();
});

it('says once that the null store cannot lock, and refreshes regardless', function () {
    // The null store is a LockProvider, but every lock it hands out is
    // granted at once: refreshing under one is refreshing unlocked, so it
    // gets the same one-time warning as a store with no locks at all.
    config()->set('cache.stores.none', ['driver' => 'null']);
    config()->set('xero-bridge.tokens.lock_store', 'none');

    Log::spy();
    // Rebuilt with the spy: the singleton holds the logger it was made with.
    app()->forgetInstance(TokenManager::class);

    connection(['expires_at' => now()->subMinute()]);
    fakeRefresh();

    tokens()->valid();

    XeroConnection::sole()->forceFill(['expires_at' => now()->subMinute()])->save();

    tokens()->valid();

    // Both refreshes ran, exactly as they would have under its NoLock.
    Http::assertSentCount(2);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'cannot lock'))
        ->once();
});

it('does not rotate again when another process already refreshed after a 401', function () {
    connection(['expires_at' => now()->addMinutes(20), 'access_token' => 'current-token']);
    fakeRefresh();

    // The token that 401'd is no longer the stored one, so there is nothing
    // to do. A boolean "force" flag would burn a rotation here.
    $connection = tokens()->refreshBecauseOf(XeroConnection::sole(), 'the-old-token');

    expect($connection->access_token)->toBe('current-token');
    Http::assertNothingSent();
});

it('rotates when the token that failed is still the stored one', function () {
    connection(['expires_at' => now()->addMinutes(20), 'access_token' => 'current-token']);
    fakeRefresh();

    $connection = tokens()->refreshBecauseOf(XeroConnection::sole(), 'current-token');

    expect($connection->access_token)->toBe('access-2');
});

/*
|--------------------------------------------------------------------------
| Lookup and decryption
|--------------------------------------------------------------------------
*/

it('names the connect url when no connection is stored', function () {
    expect(fn () => tokens()->valid())
        ->toThrow(XeroConnectionNotFoundException::class, '/xero/connect/default');
});

it('resolves the default connection when no key is given', function () {
    config()->set('xero-bridge.default_connection', 'primary');
    connection(['key' => 'primary']);

    expect(tokens()->valid()->key)->toBe('primary');
});

it('turns an APP_KEY rotation into a diagnosable error', function () {
    connection();

    // Simulate ciphertext written under a different APP_KEY.
    DB::table('xero_connections')->update(['access_token' => 'not-decryptable']);

    expect(fn () => tokens()->accessToken())
        ->toThrow(XeroConfigurationException::class, 'APP_KEY');
});
