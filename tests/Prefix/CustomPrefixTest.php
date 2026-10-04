<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Peoplelogy\XeroBridge\Jobs\ProcessXeroWebhook;
use Peoplelogy\XeroBridge\Tests\Support\SignedWebhook;

/*
| Boots with xero-bridge.routes.prefix = 'accounting' (see
| tests/Support/CustomPrefixTestCase.php) to prove the prefix is genuinely
| read from config rather than hardcoded anywhere.
*/

it('registers the routes under the configured prefix', function () {
    expect(route('xero-bridge.connect', ['key' => 'default']))
        ->toContain('/accounting/connect/default');

    $this->get('/accounting/connect')->assertRedirect();

    // And the default prefix is gone.
    $this->get('/xero/connect')->assertNotFound();
});

/*
| With no webhooks.prefix the webhook follows routes.prefix. Every host that
| upgrades stays on this path -- a published config gets the key filled in as
| null, a config cached before the key existed (this suite) has none -- so it
| is the one URL that must not move.
*/

it('puts the webhook under the configured route prefix', function () {
    expect(config('xero-bridge.webhooks'))->not->toHaveKey('prefix')
        ->and(route('xero-bridge.webhook'))->toEndWith('/accounting/webhook');
});

it('accepts a signed delivery under the configured route prefix', function () {
    Queue::fake();

    SignedWebhook::post('/accounting/webhook')->assertOk();

    Queue::assertPushed(ProcessXeroWebhook::class, 1);
});

it('no longer answers the webhook under the default prefix', function () {
    Queue::fake();

    SignedWebhook::post('/xero/webhook')->assertNotFound();

    Queue::assertNothingPushed();
});

/*
| xero-bridge:install has to print the URLs this host actually serves, not the
| defaults: they are what an administrator types into the Xero app.
*/

it('prints the prefixed webhook and console URLs on install', function () {
    expect(installCommandOutput())
        ->toContain("/accounting/webhook\n")
        ->toContain('/accounting/console')
        ->not->toContain('/xero/webhook')
        ->not->toContain('/xero/console');
});

it('falls back to the prefixed callback on install, and says why', function () {
    // Xero rejects 127.0.0.1 outright. The fallback has to follow the route
    // prefix -- before 1.5.0 it was a hard-coded xero/callback -- and the
    // reason has to be printed, not swallowed.
    URL::forceRootUrl('https://app.example.test');
    URL::forceScheme('https');
    config()->set('xero-bridge.redirect_uri', 'http://127.0.0.1/accounting/callback');

    expect(installCommandOutput())
        ->toContain("\n     https://app.example.test/accounting/callback\n")
        // The only place this can come from, with every URL on app.example.test.
        ->toContain('http://localhost')
        ->not->toContain('/xero/callback');
});
