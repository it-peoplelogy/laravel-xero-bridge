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

it('reads every attempt to switch webhooks off as no key, and trims a real one', function (mixed $raw, ?string $key) {
    config()->set('xero-bridge.webhooks.enabled', true);
    config()->set('xero-bridge.webhook_key', $raw);

    expect(app(XeroConfig::class)->webhookKey())->toBe($key)
        ->and(app(XeroConfig::class)->webhooksActive())->toBe($key !== null);
})->with([
    'unset' => [null, null],
    'blank' => ['', null],
    'whitespace' => ["  \t", null],
    // env() turns the literal words into booleans; (string) false is ''.
    'XERO_WEBHOOK_KEY=false' => [false, null],
    'XERO_WEBHOOK_KEY=true' => [true, null],
    // Written to mean "off", as for the switch -- never a real key.
    '"0"' => ['0', null],
    'int 0, from a hand-edited config' => [0, null],
    'a key' => ['abc', 'abc'],
    'a key with stray whitespace from a copy-paste' => ["  abc \n", 'abc'],
]);

it('reads a webhooks block without the flag as enabled, as the provider always has', function () {
    config()->set('xero-bridge.webhooks', ['path' => 'webhook']);
    config()->set('xero-bridge.webhook_key', 'x');

    expect(app(XeroConfig::class)->webhooksActive())->toBeTrue();
});
