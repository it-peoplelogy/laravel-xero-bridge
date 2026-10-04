<?php

declare(strict_types=1);

use Peoplelogy\XeroBridge\Support\XeroConfig;

/**
 * XeroConfig::webhooksActive(), the one rule the webhook route is registered
 * by and everything that reports on it reads.
 *
 * It reads config when it is called, so config()->set() inside a test is
 * enough here. That the route really is, or is not, registered at boot is
 * tests/Feature/WebhookTest.php, tests/WebhookKeyUnset and
 * tests/WebhooksDisabled.
 */
it('is active only while webhooks are enabled AND a key is set', function (bool $enabled, ?string $key, bool $active) {
    config()->set('xero-bridge.webhooks.enabled', $enabled);
    config()->set('xero-bridge.webhook_key', $key);

    expect(app(XeroConfig::class)->webhooksActive())->toBe($active);
})->with([
    'enabled, with a key' => [true, 'x', true],
    // No key, no route: the endpoint could only answer 401.
    'enabled, no key' => [true, null, false],
    // A bare XERO_WEBHOOK_KEY= copied out of an example file is no key.
    'enabled, an empty key' => [true, '', false],
    // The kill switch outranks a key.
    'disabled, with a key' => [false, 'x', false],
    'disabled, no key' => [false, null, false],
    'disabled, an empty key' => [false, '', false],
]);

it('reads XERO_WEBHOOK_KEY=false as no key, so it serves no route', function () {
    // env() turns the literal false into bool false, and (string) false is ''.
    config()->set('xero-bridge.webhooks.enabled', true);
    config()->set('xero-bridge.webhook_key', false);

    expect(app(XeroConfig::class)->webhookKey())->toBeNull()
        ->and(app(XeroConfig::class)->webhooksActive())->toBeFalse();
});

it('reads a webhooks block without the flag as enabled, as the provider always has', function () {
    config()->set('xero-bridge.webhooks', ['path' => 'webhook']);
    config()->set('xero-bridge.webhook_key', 'x');

    expect(app(XeroConfig::class)->webhooksActive())->toBeTrue();
});
