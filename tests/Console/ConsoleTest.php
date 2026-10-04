<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Peoplelogy\XeroBridge\Commands\StatusCommand;
use Peoplelogy\XeroBridge\Support\Diagnostics;

/*
|--------------------------------------------------------------------------
| Registration and rendering
|--------------------------------------------------------------------------
*/

it('registers the console routes', function () {
    expect(Route::has('xero-bridge.console'))->toBeTrue()
        ->and(Route::has('xero-bridge.console.run'))->toBeTrue()
        ->and(route('xero-bridge.console'))->toContain('/xero/console');
});

it('renders the console page', function () {
    $this->get('/xero/console')
        ->assertOk()
        ->assertSee('Xero Bridge Test Console');
});

it('never calls Xero to render the page', function () {
    connection();

    $this->get('/xero/console')->assertOk();

    // preventStrayRequests() would already throw, but asserting it explicitly
    // documents the intent: the whole status panel is answerable from config
    // and the connections table.
    Http::assertNothingSent();
});

it('fills the status panels on first paint', function () {
    // Regression for a bug in an earlier version of this console: a leftover
    // listener for a deleted button threw before renderState(boot) ran, so
    // the panels came up empty and the boot payload was computed and thrown
    // away. Neither the dead button nor its route may come back.
    connection();

    $page = $this->get('/xero/console')->assertOk();

    $page->assertSee('"preflight"', false)
        ->assertSee('"connections"', false)
        ->assertSee('"urls"', false)
        ->assertDontSee('pdf-btn', false)
        ->assertDontSee('invoice-pdf', false);
});

it('reports a URL as unavailable rather than throwing when its route is gone', function () {
    // Route::has() guards. A host that sets XERO_ROUTES_ENABLED=false or
    // XERO_WEBHOOKS_ENABLED=false has no such named route, and an unguarded
    // route() would turn the console into a 500 on page load.
    //
    // Those flags are read at boot, so they cannot be flipped from here.
    // Renaming the lookup prefix reaches the same branch: no route answers to
    // the new name either. Diagnostics is exercised directly because the page
    // itself needs its own console.run route to keep existing.
    config()->set('xero-bridge.routes.name_prefix', 'nope.');

    $urls = app(Diagnostics::class)->urls();

    expect($urls['callback'])->toBeNull()
        ->and($urls['webhook'])->toBeNull()
        ->and($urls['console'])->toBeNull()
        // connectUrl() has its own fallback: guidance stays actionable.
        ->and($urls['connect'])->toBe('/xero/connect/default');
});

/*
|--------------------------------------------------------------------------
| Redaction -- the console must never surface a credential
|--------------------------------------------------------------------------
*/

it('never renders a token or the client secret', function () {
    connection();

    $page = $this->get('/xero/console')->assertOk();

    $page->assertDontSee('access-token-1', false)
        ->assertDontSee('refresh-token-1', false)
        ->assertDontSee('test-client-secret', false);
});

it('never returns a token or the client secret from the run endpoint', function () {
    connection();

    $response = $this->postJson('/xero/console/run', ['action' => 'status'])->assertOk();

    $body = $response->getContent();

    expect($body)->not->toContain('access-token-1')
        ->not->toContain('refresh-token-1')
        ->not->toContain('test-client-secret');

    // The secrets are reported as booleans and nothing else.
    $response->assertJsonPath('data.config.client_secret_set', true)
        ->assertJsonMissingPath('data.config.client_secret');
});

it('flags the lock store by how far its locks reach, never by its name', function () {
    // The pill used to match the store's NAME against array and file, so it
    // disagreed with xero-bridge:status: the null store -- which status warns
    // about -- got none, as did a file store under any other name, while a
    // file store XERO_LOCK_STORE chose, which status only notes, was flagged.
    config()->set('cache.stores.none', ['driver' => 'null']);
    config()->set('xero-bridge.tokens.lock_store', 'none');

    $page = $this->get('/xero/console')->assertOk();

    preg_match('~<script type="application/json" id="boot-data">(.*?)</script>~s', (string) $page->getContent(), $boot);

    expect(json_decode($boot[1], true, 512, JSON_THROW_ON_ERROR)['config'])->toMatchArray([
        'lock_store' => 'none',
        'lock_scope' => 'none',
        'lock_store_set' => true,
    ]);

    $page->assertDontSee("['array', 'file'].indexOf(c.lock_store)", false)
        ->assertSee('c.lock_scope', false);

    // "Re-read status" renders the panel again from the run endpoint's copy.
    $this->postJson('/xero/console/run', ['action' => 'status'])
        ->assertJsonPath('data.config.lock_scope', 'none')
        ->assertJsonPath('data.config.lock_store_set', true);
});

it('marks a connection whose tokens cannot be decrypted as unreadable, never healthy', function () {
    // An APP_KEY rotated without APP_PREVIOUS_KEYS: the token may be in date,
    // and every call through it still fails. Red, as xero-bridge:status
    // shows it -- the rotation is simulated by giving the encrypted cast
    // another key once the row is saved.
    connection();
    Model::encryptUsing(new Encrypter(random_bytes(32), 'AES-256-CBC'));

    try {
        $page = $this->get('/xero/console')->assertOk();
    } finally {
        // The static encrypter outlives the test otherwise.
        Model::encryptUsing(null);
    }

    preg_match('~<script type="application/json" id="boot-data">(.*?)</script>~s', (string) $page->getContent(), $boot);

    expect(json_decode($boot[1], true, 512, JSON_THROW_ON_ERROR)['connections'][0])
        ->toMatchArray(['key' => 'default', 'expired' => false, 'tokens_readable' => false]);

    $page->assertSee('c.tokens_readable === false', false)
        ->assertSee("pill('tokens unreadable', 'bad')", false);
});

it('cannot be broken out of by a hostile organisation name', function () {
    // tenant_name is Xero's data, not ours. The JSON_HEX_* flags on the boot
    // payload are what stop a </script> in it from closing the tag.
    connection(['tenant_name' => '</script><script>alert(1)</script>']);

    $this->get('/xero/console')
        ->assertOk()
        ->assertDontSee('</script><script>alert(1)</script>', false);
});

/*
|--------------------------------------------------------------------------
| The gate, both directions: off unless XERO_CONSOLE_ENABLED=true
|--------------------------------------------------------------------------
|
| Flipped per request: the routes were registered at boot with the console
| on, so these prove the request-time half of the gate. What the shipped
| default registers in the first place is tests/ConsoleUnset.
|
*/

it('404s at request time when the console is switched off', function () {
    // The route:cache hole-closer. The route IS registered here -- it was
    // registered at boot, when the console was on -- and the middleware is
    // what refuses the request.
    expect(Route::has('xero-bridge.console'))->toBeTrue();

    config()->set('xero-bridge.console.enabled', false);

    $this->get('/xero/console')->assertNotFound();
    $this->postJson('/xero/console/run', ['action' => 'status'])->assertNotFound();
});

it('is off in every environment when nothing is configured', function (?string $value, string $env) {
    // Before 1.5.0 an unset value meant "on unless APP_ENV is exactly
    // production" -- so a live host on `prod`, `live` or `Production`, or a
    // staging box cloned from an .env.example that says `local`, served the
    // console to every signed-in user. APP_ENV no longer enters into it.
    config()->set('xero-bridge.console.enabled', $value);
    $this->app['env'] = $env;

    $this->get('/xero/console')->assertNotFound();
    $this->postJson('/xero/console/run', ['action' => 'status'])->assertNotFound();
})->with(function (): array {
    $cases = [];

    // '' as well as null: what a bare XERO_CONSOLE_ENABLED= copied out of an
    // example file resolves to.
    foreach (['unset' => null, 'empty' => ''] as $label => $value) {
        foreach (['local', 'testing', 'staging', 'uat', 'prod', 'Production', 'production'] as $env) {
            $cases["{$label} in {$env}"] = [$value, $env];
        }
    }

    return $cases;
});

it('fails closed on a value that does not read as true', function (string $value) {
    // A plain (bool) cast reads every one of these as true. They reach the gate
    // as strings from a hand-edited config or a runtime config()->set().
    config()->set('xero-bridge.console.enabled', $value);
    $this->app['env'] = 'local';

    $this->get('/xero/console')->assertNotFound();
    $this->postJson('/xero/console/run', ['action' => 'status'])->assertNotFound();
})->with(['false', 'off', 'no', 'nonsense']);

it('is on in any environment once explicitly enabled', function (string $env) {
    config()->set('xero-bridge.console.enabled', true);
    $this->app['env'] = $env;

    $this->get('/xero/console')->assertOk();

    // Outside `testing` Laravel stops skipping the CSRF check, so post the
    // token the way the page itself does, in X-CSRF-TOKEN.
    $this->withSession(['_token' => 'console-csrf'])
        ->postJson('/xero/console/run', ['action' => 'status'], ['X-CSRF-TOKEN' => 'console-csrf'])
        ->assertOk();
})->with(['local', 'staging', 'production']);

it('marks the page as a production host when enabled there', function () {
    config()->set('xero-bridge.console.enabled', true);
    $this->app['env'] = 'production';

    $this->get('/xero/console')
        ->assertOk()
        ->assertSee('This is a production host');
});

/*
|--------------------------------------------------------------------------
| The healthy baseline: healthyLockStore()
|--------------------------------------------------------------------------
|
| The status action reports exactly what xero-bridge:status --strict judges.
| The suite's own `array` cache locks only inside one process, which those
| checks rightly flag, so a test that needs a HEALTHY result starts from the
| helper in tests/Pest.php instead.
|
*/

it('gives status --strict a clean baseline with healthyLockStore()', function () {
    connection();

    $this->artisan('xero-bridge:status', ['--strict' => true])
        ->assertExitCode(StatusCommand::EXIT_CONFIG_INCOMPLETE);

    healthyLockStore();

    $this->postJson('/xero/console/run', ['action' => 'status'])
        ->assertOk()
        ->assertJsonPath('data.warnings', [])
        ->assertJsonPath('data.config.lock_store', 'database')
        ->assertJsonPath('data.config.lock_scope', 'shared')
        // Takes and releases a real lock, so this would fail if the table the
        // database store locks in were missing.
        ->assertJsonPath('data.preflight.lock.ok', true);

    $this->artisan('xero-bridge:status', ['--strict' => true])->assertExitCode(0);
});

/*
|--------------------------------------------------------------------------
| The run envelope
|--------------------------------------------------------------------------
*/

it('rejects an action that is not on the allow-list', function () {
    $this->postJson('/xero/console/run', ['action' => 'settings.nuke'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('action');
});

it('rejects a malformed connection key', function () {
    $this->postJson('/xero/console/run', ['action' => 'status', 'connection' => 'bad key!'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('connection');
});

it('answers with a uniform envelope', function () {
    connection();

    $this->postJson('/xero/console/run', ['action' => 'status'])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('action', 'status')
        ->assertJsonPath('connection', 'default')
        ->assertJsonStructure(['ok', 'action', 'connection', 'duration_ms', 'data' => ['config', 'preflight', 'urls', 'connections']]);
});

it('reports a Xero failure as a 200 with ok false', function () {
    // Deliberate: a non-2xx would be swallowed by the browser's own error
    // handling, and the console renders the diagnosis itself.
    connection();

    Http::fake(['api.xero.com/*' => Http::response([
        'ErrorNumber' => 10,
        'Type' => 'ValidationException',
        'Message' => 'A validation exception occurred',
        'Elements' => [[
            'ValidationErrors' => [['Message' => 'Account code 999 is invalid']],
        ]],
    ], 400, xeroHeaders())]);

    $this->postJson('/xero/console/run', ['action' => 'settings.organisation'])
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.type', 'XeroValidationException')
        ->assertJsonPath('error.status', 400)
        ->assertJsonPath('error.hint', 'Xero rejected the request. See validation_errors for the element it objected to.');
});

it('reports the rate limit Xero returned', function () {
    connection();

    Http::fake(['api.xero.com/*' => Http::response(
        ['Organisations' => [['Name' => 'Demo Company (Global)']]],
        200,
        xeroHeaders(),
    )]);

    $this->postJson('/xero/console/run', ['action' => 'settings.organisation'])
        ->assertOk()
        ->assertJsonPath('rate_limit.minute_remaining', 59)
        ->assertJsonPath('rate_limit.day_remaining', 4999);
});

it('reports a missing connection with an actionable hint', function () {
    $this->postJson('/xero/console/run', ['action' => 'settings.organisation'])
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.type', 'XeroConnectionNotFoundException');
});
