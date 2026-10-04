<?php

declare(strict_types=1);

use Peoplelogy\XeroBridge\Support\XeroConfig;

/**
 * XeroConfig::webhookUri(), the one rule routes/webhook.php registers the
 * route with.
 *
 * It reads config when it is called, so config()->set() inside a test is
 * enough here. That the route really is registered where this says, at boot,
 * is tests/Prefix and tests/WebhookPrefix.
 */
function webhookUriFor(?string $webhookPrefix, ?string $routePrefix, ?string $path): string
{
    config()->set('xero-bridge.webhooks.prefix', $webhookPrefix);
    config()->set('xero-bridge.routes.prefix', $routePrefix);
    config()->set('xero-bridge.webhooks.path', $path);

    return app(XeroConfig::class)->webhookUri();
}

it('builds the webhook URI from the prefixes and the path', function (?string $webhookPrefix, ?string $routePrefix, ?string $path, string $expected) {
    expect(webhookUriFor($webhookPrefix, $routePrefix, $path))->toBe($expected);
})->with([
    'unset follows routes.prefix' => [null, 'xero', 'webhook', 'xero/webhook'],
    // A bare XERO_WEBHOOK_PREFIX= copied out of an example file must not move
    // a live endpoint to the site root.
    'empty counts as unset' => ['', 'xero', 'webhook', 'xero/webhook'],
    'a slash means the site root' => ['/', 'xero', 'webhook', 'webhook'],
    'its own prefix, slashes trimmed' => ['/api/v1/xero/', 'xero', 'webhook', 'api/v1/xero/webhook'],
    'a custom routes.prefix' => [null, 'accounting', 'webhook', 'accounting/webhook'],
    'routes.prefix slashes trimmed' => [null, '/admin/xero/', 'webhook', 'admin/xero/webhook'],
    'routes at the site root' => [null, '', 'webhook', 'webhook'],
    // As routes/web.php treats it: no prefix at all.
    'routes.prefix explicitly null' => [null, null, 'webhook', 'webhook'],
    'the path trimmed' => [null, 'xero', '/hooks/', 'xero/hooks'],
    // A leading slash has always been trimmed, so XERO_WEBHOOK_PATH=/webhook
    // means /xero/webhook. Reading it as absolute would silently move it.
    'a leading slash on the path is not absolute' => ['api', 'xero', '/xero-hooks', 'api/xero-hooks'],
    // The path's default applies only when the key is missing, exactly as the
    // route file always read it, so an explicitly empty path keeps the URL it
    // has always had.
    'an explicitly empty path keeps the bare prefix' => [null, 'xero', '', 'xero'],
]);

it('keeps the URL of a webhooks block published before the key existed', function () {
    // The shape every config published before 1.5.0 has, and what a config
    // cached before the update still holds: no `prefix` key at all.
    config()->set('xero-bridge.routes.prefix', 'xero');
    config()->set('xero-bridge.webhooks', [
        'enabled' => true,
        'path' => 'webhook',
        'queue' => null,
        'connection' => null,
    ]);

    expect(app(XeroConfig::class)->webhookUri())->toBe('xero/webhook');
});

it('falls back to the default path when the block has none', function () {
    config()->set('xero-bridge.routes.prefix', 'xero');
    config()->set('xero-bridge.webhooks', ['enabled' => true]);

    expect(app(XeroConfig::class)->webhookUri())->toBe('xero/webhook');
});

it('ships webhooks.prefix unset', function () {
    // Asserted on the SOURCE as well as the required file, as ConfigTest does:
    // no default means no host's webhook moves until it sets the variable.
    $source = file_get_contents(__DIR__.'/../../config/xero-bridge.php');
    $fresh = require __DIR__.'/../../config/xero-bridge.php';

    expect($source)->toContain("'prefix' => env('XERO_WEBHOOK_PREFIX'),")
        ->and($source)->not->toContain("env('XERO_WEBHOOK_PREFIX',")
        ->and($fresh['webhooks'])->toHaveKey('prefix')
        ->and($fresh['webhooks']['prefix'])->toBeNull();
});
