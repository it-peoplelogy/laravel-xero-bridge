<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Peoplelogy\XeroBridge\Support\Diagnostics;
use Peoplelogy\XeroBridge\Support\XeroConfig;
use Peoplelogy\XeroBridge\Tests\Support\SignedWebhook;

/*
| Booted with XERO_WEBHOOKS_ENABLED=false and the base TestCase's signing key
| (tests/Support/WebhooksDisabledTestCase.php): the kill switch outranks the
| key, so a key left in place cannot bring the route back.
*/

it('registers no webhook route, even with a key set', function () {
    expect(config('xero-bridge.webhook_key'))->toBe(SignedWebhook::KEY)
        ->and(app(XeroConfig::class)->webhooksActive())->toBeFalse()
        ->and(Route::has('xero-bridge.webhook'))->toBeFalse();
});

it('answers a correctly signed delivery 404', function () {
    Queue::fake();

    SignedWebhook::post('/xero/webhook')->assertNotFound();

    Queue::assertNothingPushed();
});

it('reports no webhook URL, and the key and the flag as they are set', function () {
    $diagnostics = app(Diagnostics::class);

    expect($diagnostics->urls()['webhook'])->toBeNull()
        ->and($diagnostics->environment()['webhook_key_set'])->toBeTrue()
        ->and($diagnostics->environment()['webhooks_enabled'])->toBeFalse();
});

it('says on install that webhooks are disabled, and offers no URL', function () {
    expect(installCommandOutput())
        ->toContain("\nWebhooks are disabled (XERO_WEBHOOKS_ENABLED=false), so there is no webhook URL to register.\n")
        ->not->toContain('/xero/webhook')
        ->not->toContain('Webhooks are off')
        ->not->toContain('Xero only delivers webhooks to https');
});
