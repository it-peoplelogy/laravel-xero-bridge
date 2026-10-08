<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Peoplelogy\XeroBridge\Events\XeroWebhookSignatureFailed;
use Peoplelogy\XeroBridge\Support\Diagnostics;
use Peoplelogy\XeroBridge\Support\XeroConfig;
use Peoplelogy\XeroBridge\Tests\Support\SignedWebhook;

/*
| Booted with no XERO_WEBHOOK_KEY and webhooks.enabled at its shipped default,
| on (tests/Support/WebhookKeyUnsetTestCase.php): an application that only
| calls Xero, with no webhook setting at all. No key, no route -- so nothing
| here may serve a webhook endpoint, warn about one, or fail --strict over it.
*/

it('registers no webhook route when no key is set', function () {
    expect(config('xero-bridge.webhook_key'))->toBeNull()
        ->and(config('xero-bridge.webhooks.enabled'))->toBeTrue()
        ->and(app(XeroConfig::class)->webhooksActive())->toBeFalse()
        ->and(Route::has('xero-bridge.webhook'))->toBeFalse();
});

it('answers a delivery 404, as for any path the application does not serve', function () {
    Queue::fake();
    Event::fake([XeroWebhookSignatureFailed::class]);

    // Signed with the key the suite's environment still carries, so the 404
    // is the missing route answering, not the signature check.
    SignedWebhook::post('/xero/webhook')->assertNotFound();
    $this->call('POST', '/xero/webhook')->assertNotFound();

    Queue::assertNothingPushed();
    Event::assertNotDispatched(XeroWebhookSignatureFailed::class);
});

it('leaves connect and callback registered', function () {
    // routes.enabled is a separate flag, in a separate route file.
    expect(Route::has('xero-bridge.connect'))->toBeTrue()
        ->and(Route::has('xero-bridge.callback'))->toBeTrue();
});

it('decides at boot, so a key set afterwards serves nothing until the next boot', function () {
    // A route:cache built without a key behaves the same once one is set,
    // until it is rebuilt.
    Queue::fake();
    config()->set('xero-bridge.webhook_key', SignedWebhook::KEY);

    expect(app(XeroConfig::class)->webhooksActive())->toBeTrue()
        ->and(Route::has('xero-bridge.webhook'))->toBeFalse();

    SignedWebhook::post('/xero/webhook')->assertNotFound();

    Queue::assertNothingPushed();
});

it('reports no webhook URL, and the key and the flag as they are set', function () {
    $diagnostics = app(Diagnostics::class);

    expect($diagnostics->urls()['webhook'])->toBeNull()
        ->and($diagnostics->environment()['webhook_key_set'])->toBeFalse()
        // The flag as set, not whether a route is served.
        ->and($diagnostics->environment()['webhooks_enabled'])->toBeTrue();
});

it('says nothing about the key in xero-bridge:status, and --strict passes', function () {
    healthyLockStore();
    connection();

    $exit = Artisan::call('xero-bridge:status', ['--strict' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->not->toContain('XERO_WEBHOOK_KEY')
        ->not->toContain('WARN');

    Artisan::call('xero-bridge:status', ['--json' => true]);
    $json = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    // Not moved into a note either: webhooks off is not something to report.
    expect($json['warnings'])->toBe([])
        ->and($json['notes'])->toBe([]);
});

it('says on install that webhooks are off, and how to receive them', function () {
    URL::forceRootUrl('https://app.example.test');
    URL::forceScheme('https');

    expect(installCommandOutput())
        ->toContain(
            "\nWebhooks are off: no XERO_WEBHOOK_KEY is set, so no webhook route is served. "
            ."An application that only calls Xero needs nothing more.\n"
            .'  To receive webhooks, register https://app.example.test/xero/webhook in your Xero app\'s '
            .'Webhooks tab, put the key Xero shows into XERO_WEBHOOK_KEY, rebuild config:cache and '
            ."route:cache, then press Send \"Intent to receive\".\n"
        )
        ->not->toContain('Webhook URL (paste into')
        ->not->toContain('Webhooks are disabled')
        // https already: nothing to say about the scheme.
        ->not->toContain('Xero delivers only to https');
});

it('builds the URL to register by the route file\'s rule, as there is no route to ask', function () {
    URL::forceRootUrl('https://app.example.test');
    URL::forceScheme('https');
    config()->set('xero-bridge.webhooks.prefix', 'api/hooks');

    expect(installCommandOutput())
        ->toContain('register https://app.example.test/api/hooks/webhook in your Xero app')
        ->not->toContain('/xero/webhook');
});

it('notes in a plain line, not a warning, that the URL to register needs https', function () {
    // With a key this URL would draw the warning; without one nothing is
    // wrong, but whoever goes on to register it needs to know Xero refuses it.
    URL::forceRootUrl('http://app.example.test');
    URL::forceScheme('http');

    expect(installCommandOutput())
        ->toContain('register http://app.example.test/xero/webhook in your Xero app')
        ->toContain("\n  Xero delivers only to https on port 443: set APP_URL to the public https address first.\n")
        // Not the warning box a key's URL draws. (Other parts of install may
        // still warn on an http root -- the redirect URI does.)
        ->not->toContain('Xero only delivers webhooks to https');
});

it('warns, and fails --strict, when a key is set but no route was registered for it', function () {
    // A route:cache built before the key, or a key put into config after the
    // package booted: Xero is meant to reach this application and gets a 404.
    healthyLockStore();
    connection();
    config()->set('xero-bridge.webhook_key', SignedWebhook::KEY);

    $exit = Artisan::call('xero-bridge:status', ['--strict' => true]);

    Artisan::call('xero-bridge:status', ['--json' => true]);
    $json = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($json['warnings'])->toBe([
            'XERO_WEBHOOK_KEY is set but no webhook route is registered, so Xero gets a 404. '
            .'Rebuild route:cache, and put the key in config before the package boots.',
        ])
        // The console's payload: the key set, no URL to show for it.
        ->and(app(Diagnostics::class)->environment()['webhook_key_set'])->toBeTrue()
        ->and(app(Diagnostics::class)->urls()['webhook'])->toBeNull();
});

it('tells install the URL has no route yet when the key came after the routes', function () {
    URL::forceRootUrl('https://app.example.test');
    URL::forceScheme('https');
    config()->set('xero-bridge.webhook_key', SignedWebhook::KEY);

    expect(installCommandOutput())
        ->toContain(
            "Webhook URL (paste into the Xero app's Webhooks tab):\n"
            ."  https://app.example.test/xero/webhook\n"
            .'  No webhook route is registered yet, so Xero would get a 404: rebuild route:cache '
            ."before pressing Send \"Intent to receive\".\n"
        )
        ->not->toContain('Webhooks are off');
});
