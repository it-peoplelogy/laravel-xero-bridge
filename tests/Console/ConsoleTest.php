<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
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
    // Regression for the bug this console shipped with in pips: a leftover
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
| The environment gate, both directions
|--------------------------------------------------------------------------
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

it('is off in production when nothing is configured', function () {
    config()->set('xero-bridge.console.enabled', null);
    $this->app['env'] = 'production';

    $this->get('/xero/console')->assertNotFound();
});

it('is on outside production when nothing is configured', function () {
    config()->set('xero-bridge.console.enabled', null);
    $this->app['env'] = 'local';

    $this->get('/xero/console')->assertOk();
});

it('treats an empty value as unconfigured rather than as off', function () {
    // A bare XERO_CONSOLE_ENABLED= copied out of an example file must not
    // silently switch the console off in local.
    config()->set('xero-bridge.console.enabled', '');
    $this->app['env'] = 'local';

    $this->get('/xero/console')->assertOk();
});

it('is on in production when explicitly enabled', function () {
    config()->set('xero-bridge.console.enabled', true);
    $this->app['env'] = 'production';

    $this->get('/xero/console')
        ->assertOk()
        ->assertSee('This is a production host');
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
