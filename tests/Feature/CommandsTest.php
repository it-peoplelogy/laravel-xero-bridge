<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Peoplelogy\XeroBridge\Commands\RefreshTokensCommand;
use Peoplelogy\XeroBridge\Commands\StatusCommand;
use Peoplelogy\XeroBridge\Events\ConnectionExpired;
use Peoplelogy\XeroBridge\Models\XeroConnection;

afterEach(function () {
    // xero-bridge:install really publishes, and under Testbench config_path()
    // and database_path() point into the shared skeleton in vendor/. A copy
    // left there is loaded by every later boot on this machine -- CI starts
    // from a fresh vendor/ and never sees it -- so the local suite would
    // quietly run against a published config of whatever age instead of the
    // shipped one. Remove everything the install tests can have written.
    File::delete([config_path('xero-bridge.php'), config_path('myinvois.php')]);

    foreach (['*_create_xero_*_table.php', '*_create_myinvois_validations_table.php'] as $migration) {
        File::delete(File::glob(database_path('migrations/'.$migration)) ?: []);
    }
});

/*
|--------------------------------------------------------------------------
| xero-bridge:status
|--------------------------------------------------------------------------
*/

it('reports a healthy connection', function () {
    connection();

    $this->artisan('xero-bridge:status')
        ->expectsOutputToContain('Acme Sdn Bhd')
        ->assertExitCode(0);
});

it('names the env var for missing configuration', function () {
    config()->set('xero-bridge.client_secret', null);
    connection();

    $this->artisan('xero-bridge:status')
        ->expectsOutputToContain('XERO_CLIENT_SECRET')
        ->assertExitCode(StatusCommand::EXIT_CONFIG_INCOMPLETE);
});

it('flags a scope set with no offline_access', function () {
    config()->set('xero-bridge.scopes', 'openid accounting.invoices');
    connection();

    $this->artisan('xero-bridge:status')
        ->expectsOutputToContain('offline_access')
        ->assertExitCode(StatusCommand::EXIT_CONFIG_INCOMPLETE);
});

it('exits 2 when a connection needs re-authorising', function () {
    connection(['invalidated_at' => now(), 'invalidated_reason' => 'invalid_grant']);

    $this->artisan('xero-bridge:status')
        ->expectsOutputToContain('/xero/connect/default')
        ->assertExitCode(StatusCommand::EXIT_NEEDS_REAUTH);
});

it('never calls Xero', function () {
    connection();

    // Http::preventStrayRequests() makes this fail loudly if it ever does.
    $this->artisan('xero-bridge:status')->run();

    Http::assertNothingSent();
});

it('says so when nothing is connected', function () {
    $this->artisan('xero-bridge:status')
        ->expectsOutputToContain('No Xero organisations are connected')
        ->assertExitCode(0);
});

it('emits parseable json', function () {
    connection();

    // Artisan::call(), not the artisan() test helper: the helper mocks the
    // console output, so Artisan::output() would come back empty.
    Artisan::call('xero-bridge:status', ['--json' => true]);

    $output = Artisan::output();
    $decoded = json_decode($output, true);

    expect($decoded)->toBeArray()
        ->and($decoded['connections'][0]['key'])->toBe('default')
        ->and($decoded['connections'][0]['organisation'])->toBe('Acme Sdn Bhd')
        // A status dump must never carry credentials.
        ->and($output)->not->toContain('access-token-1')
        ->and($output)->not->toContain('refresh-token-1');
});

/*
|--------------------------------------------------------------------------
| xero-bridge:refresh-tokens
|--------------------------------------------------------------------------
*/

it('refreshes a token that is close to expiry', function () {
    connection(['expires_at' => now()->addMinutes(5)]);

    Http::fake(['identity.xero.com/*' => Http::response([
        'access_token' => 'access-2',
        'refresh_token' => 'refresh-2',
        'expires_in' => 1800,
        'scope' => 'offline_access',
    ])]);

    $this->artisan('xero-bridge:refresh-tokens')->assertExitCode(0);

    expect(XeroConnection::sole()->refresh_token)->toBe('refresh-2');
});

it('leaves a still-fresh token alone', function () {
    connection(['expires_at' => now()->addHours(2)]);

    $this->artisan('xero-bridge:refresh-tokens')
        ->expectsOutputToContain('still fresh')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

it('refreshes a fresh token when forced', function () {
    connection(['expires_at' => now()->addHours(2)]);

    Http::fake(['identity.xero.com/*' => Http::response([
        'access_token' => 'access-2',
        'refresh_token' => 'refresh-2',
        'expires_in' => 1800,
    ])]);

    $this->artisan('xero-bridge:refresh-tokens', ['--force' => true])->assertExitCode(0);

    expect(XeroConnection::sole()->access_token)->toBe('access-2');
});

it('NEVER clears a token on a transient failure', function () {
    // Regression guard. An integration that clears its tokens on a transient
    // failure turns one bad night into an outage that lasts until a human
    // reconnects through a browser.
    Event::fake([ConnectionExpired::class]);
    connection(['expires_at' => now()->addMinutes(5)]);

    Http::fake(['identity.xero.com/*' => Http::response('', 503)]);

    $this->artisan('xero-bridge:refresh-tokens')
        ->assertExitCode(RefreshTokensCommand::EXIT_TRANSIENT_FAILURE);

    $connection = XeroConnection::sole();

    expect(XeroConnection::count())->toBe(1)
        ->and($connection->refresh_token)->toBe('refresh-token-1')
        ->and($connection->access_token)->toBe('access-token-1')
        ->and($connection->invalidated_at)->toBeNull();

    Event::assertNotDispatched(ConnectionExpired::class);
});

it('marks a connection for re-authorisation only on invalid_grant', function () {
    Event::fake([ConnectionExpired::class]);
    connection(['expires_at' => now()->addMinutes(5)]);

    Http::fake(['identity.xero.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);

    $this->artisan('xero-bridge:refresh-tokens')
        ->assertExitCode(RefreshTokensCommand::EXIT_NEEDS_REAUTH);

    expect(XeroConnection::sole()->invalidated_at)->not->toBeNull()
        // Marked, not deleted.
        ->and(XeroConnection::count())->toBe(1);

    Event::assertDispatched(ConnectionExpired::class);
});

it('reports an already invalidated connection without calling Xero', function () {
    connection(['invalidated_at' => now(), 'invalidated_reason' => 'invalid_grant']);

    $this->artisan('xero-bridge:refresh-tokens')
        ->assertExitCode(RefreshTokensCommand::EXIT_NEEDS_REAUTH);

    Http::assertNothingSent();
});

it('skips a connection that another process is already refreshing', function () {
    Carbon\Carbon::setTestNow();
    connection(['expires_at' => now()->addMinutes(5)]);

    Cache::lock('xero-bridge:refresh:default', 30)->get();
    config()->set('xero-bridge.tokens.lock_wait', 1);

    $this->artisan('xero-bridge:refresh-tokens')
        ->expectsOutputToContain('skipped (locked)')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

it('can target a single connection', function () {
    connection(['key' => 'a', 'tenant_id' => 't-a', 'expires_at' => now()->addHours(2)]);
    connection(['key' => 'b', 'tenant_id' => 't-b', 'expires_at' => now()->addHours(2)]);

    $this->artisan('xero-bridge:refresh-tokens', ['--connection' => 'a'])
        ->doesntExpectOutputToContain('b')
        ->assertExitCode(0);
});

it('errors on an unknown connection name', function () {
    $this->artisan('xero-bridge:refresh-tokens', ['--connection' => 'nope'])
        ->assertExitCode(1);
});

it('does nothing when there are no connections', function () {
    $this->artisan('xero-bridge:refresh-tokens')
        ->expectsOutputToContain('No Xero connections')
        ->assertExitCode(0);
});

/*
|--------------------------------------------------------------------------
| xero-bridge:install
|--------------------------------------------------------------------------
*/

it('prints every required env key and the webhook url', function () {
    $this->artisan('xero-bridge:install')
        ->expectsOutputToContain('XERO_CLIENT_ID')
        ->expectsOutputToContain('XERO_CLIENT_SECRET')
        ->expectsOutputToContain('XERO_REDIRECT_URI')
        ->expectsOutputToContain('offline_access')
        ->expectsOutputToContain('/xero/webhook')
        ->assertExitCode(0);
});

it('warns when the app url is not https', function () {
    // Through the URL generator: it took its root from APP_URL at boot, so
    // changing app.url now would move nothing.
    URL::forceRootUrl('http://app.example.test');
    URL::forceScheme('http');

    // The warning itself, not merely "https" -- every run prints
    // https://developer.xero.com/myapps. Whitespace collapsed, because the
    // warning box wraps at the terminal's width.
    $output = (string) preg_replace('/\s+/', ' ', installCommandOutput());

    expect($output)->toContain('http://app.example.test/xero/webhook')
        ->toContain('Xero only delivers webhooks to https on port 443, so this URL will not work as-is. '
            .'Set APP_URL to your public https address.');
});
