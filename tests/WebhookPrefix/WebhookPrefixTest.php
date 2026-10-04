<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Queue;
use Peoplelogy\XeroBridge\Jobs\ProcessXeroWebhook;
use Peoplelogy\XeroBridge\Support\Diagnostics;
use Peoplelogy\XeroBridge\Tests\Support\SignedWebhook;

/*
| Boots with routes.prefix = 'admin/xero' and webhooks.prefix = 'api/v1/xero'
| (tests/Support/WebhookPrefixTestCase.php): the webhook moves on its own,
| everything else stays under the route prefix.
*/

it('serves the webhook under its own prefix and nothing else with it', function () {
    expect(route('xero-bridge.webhook'))->toEndWith('/api/v1/xero/webhook')
        ->and(route('xero-bridge.connect', ['key' => 'default']))->toEndWith('/admin/xero/connect/default')
        ->and(route('xero-bridge.callback'))->toEndWith('/admin/xero/callback')
        ->and(route('xero-bridge.console'))->toEndWith('/admin/xero/console');
});

it('accepts a signed delivery at the new path', function () {
    Queue::fake();

    SignedWebhook::post('/api/v1/xero/webhook')->assertOk();

    Queue::assertPushed(ProcessXeroWebhook::class, 1);
});

it('keeps the cookieless stack on the moved route', function () {
    // The stack is what Xero's intent-to-receive check actually tests: ANY
    // cookie in the response fails it. A cookie queued by the application must
    // still be stripped -- the route has to take its middleware with it.
    Queue::fake();
    Cookie::queue('some_app_cookie', 'value');

    $response = SignedWebhook::post('/api/v1/xero/webhook');

    $response->assertOk()->assertHeaderMissing('Set-Cookie');
    expect($response->baseResponse->headers->getCookies())->toBeEmpty();
});

it('no longer answers under the route prefix', function () {
    Queue::fake();

    SignedWebhook::post('/admin/xero/webhook')->assertNotFound();
    SignedWebhook::post('/xero/webhook')->assertNotFound();

    Queue::assertNothingPushed();
});

it('reports the moved URL in diagnostics', function () {
    // What the console shows as the URL to paste into the Xero app.
    expect(app(Diagnostics::class)->urls()['webhook'])->toEndWith('/api/v1/xero/webhook');
});

it('prints the moved webhook URL on install, and keeps the rest under the route prefix', function () {
    expect(installCommandOutput())
        ->toContain("/api/v1/xero/webhook\n")
        ->not->toContain('/admin/xero/webhook')
        ->toContain('/admin/xero/connect/default')
        ->toContain('/admin/xero/console');
});
