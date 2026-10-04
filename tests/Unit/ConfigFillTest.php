<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Peoplelogy\XeroBridge\Models\XeroConnection;
use Peoplelogy\XeroBridge\Support\XeroConfig;
use Peoplelogy\XeroBridge\XeroBridgeServiceProvider;

/**
 * A setting added by a later release reaches a published config without
 * anyone editing it -- XeroBridgeServiceProvider::fillMissingConfig().
 *
 * mergeConfigFrom() merges one level deep, so on its own a published
 * config/xero-bridge.php never receives a key a release adds INSIDE a block.
 *
 * The boot-level tests put a published file's contents in front of the
 * provider and run its register() again: spatie's shallow merge, then the
 * fill, in the order a real boot runs them. Testbench applies a test case's
 * own config only AFTER the providers have registered, so a fresh register()
 * is the way to show the provider a config it has not seen yet -- without
 * writing a file into the shared skeleton under vendor/.
 */

/**
 * config/xero-bridge.php exactly as v1.0.6 published it -- no console, writes
 * or capture block, a webhooks block with four keys -- with the kind of edits a
 * host makes to its copy.
 *
 * @return array<string, mixed>
 */
function publishedAtV106(): array
{
    return [
        'client_id' => 'host-client-id',
        'client_secret' => 'host-client-secret',
        'webhook_key' => 'host-webhook-key',
        'redirect_uri' => 'https://host.example/xero/callback',
        'scopes' => 'offline_access accounting.invoices accounting.contacts accounting.settings',
        'default_connection' => 'default',
        'endpoints' => [
            'authorize' => 'https://login.xero.com/identity/connect/authorize',
            'token' => 'https://identity.xero.com/connect/token',
            'revocation' => 'https://identity.xero.com/connect/revocation',
            'connections' => 'https://api.xero.com/connections',
            'api' => 'https://api.xero.com/api.xro/2.0',
        ],
        'routes' => [
            'enabled' => true,
            'prefix' => 'xero',
            'name_prefix' => 'xero-bridge.',
            // Shorter than the shipped web,auth: the host's decision.
            'middleware' => ['web'],
            'after_connect_route' => null,
            'after_connect_redirect' => '/settings/xero',
            'allow_return_to' => false,
        ],
        'webhooks' => [
            'enabled' => true,
            'path' => 'hooks/xero',
            'queue' => 'webhooks',
            'connection' => null,
        ],
        'http' => [
            'timeout' => 30,
            'connect_timeout' => 10,
            'retries' => 3,
            // A list, and shorter than the shipped one.
            'retry_status' => [429, 503],
            'retry_base_ms' => 1000,
            'retry_max_ms' => 30000,
            'concurrency_backoff_ms' => 250,
            'offline_retry_after' => 300,
            'idempotency' => true,
            'user_agent' => null,
        ],
        'tokens' => [
            'refresh_leeway' => 60,
            'lock_store' => 'redis',
            'lock_ttl' => 10,
            'lock_wait' => 12,
            'http_timeout' => 8,
            'http_retries' => 2,
        ],
        'database' => [
            'connection' => null,
            'table' => 'xero_connections',
        ],
        'model' => XeroConnection::class,
        'on_key_conflict' => 'replace',
        'on_tenant_conflict' => 'error',
        'connections' => [
            'default' => [
                'account_code' => '200',
                'tax_type' => null,
                'currency' => 'MYR',
                'branding_theme_id' => null,
            ],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function shippedXeroBridgeConfig(): array
{
    return require __DIR__.'/../../config/xero-bridge.php';
}

/**
 * Shows the provider $published under $key, then registers it again as a boot
 * would: spatie's shallow merge first, the fill after it.
 *
 * @param  array<string, mixed>  $published
 */
function registerOverPublished(string $key, array $published): void
{
    config()->set($key, $published);

    (new XeroBridgeServiceProvider(app()))->register();
}

/*
|--------------------------------------------------------------------------
| A published config, through the provider
|--------------------------------------------------------------------------
*/

it('fills the keys a published config never received', function () {
    registerOverPublished('xero-bridge', publishedAtV106());

    $shipped = shippedXeroBridgeConfig();
    $webhooks = config('xero-bridge.webhooks');

    // The nested keys a shallow merge never delivered...
    expect($webhooks['unique_for'])->toBe($shipped['webhooks']['unique_for'])
        ->and($webhooks['tries'])->toBe($shipped['webhooks']['tries'])
        ->and($webhooks['dedupe'])->toBe($shipped['webhooks']['dedupe'])
        ->and($webhooks['unknown_tenants'])->toBe($shipped['webhooks']['unknown_tenants'])
        ->and($webhooks)->toHaveKey('prefix')
        ->and($webhooks['prefix'])->toBe($shipped['webhooks']['prefix'])
        // ...and the blocks it did, whole, plus a key inside one of them.
        ->and(config('xero-bridge.console'))->toBe($shipped['console'])
        ->and(config('xero-bridge.capture'))->toBe($shipped['capture'])
        ->and(config('xero-bridge.writes.strict'))->toBe($shipped['writes']['strict']);
});

it('keeps every value the host already had, at every depth', function () {
    $published = publishedAtV106();

    registerOverPublished('xero-bridge', $published);

    foreach (Arr::dot($published) as $key => $value) {
        expect(config("xero-bridge.{$key}"))->toBe($value, "xero-bridge.{$key} was changed");
    }
});

it('keeps a shorter list instead of topping it up', function () {
    registerOverPublished('xero-bridge', publishedAtV106());

    // A list is one value. Merging it index by index would hand back the
    // shipped `auth` the host deliberately left out.
    expect(config('xero-bridge.routes.middleware'))->toBe(['web'])
        ->and(config('xero-bridge.http.retry_status'))->toBe([429, 503]);
});

it('keeps an empty array, and a null, as the host wrote them', function () {
    $published = publishedAtV106();
    $published['webhooks']['queue'] = null;
    $published['capture'] = [
        'enabled' => true,
        'skip_urls' => [],
        'channels' => [],
        'redact' => ['add' => []],
    ];

    registerOverPublished('xero-bridge', $published);

    $shipped = shippedXeroBridgeConfig();

    // [] is a decision, not a gap -- not even where a block ships.
    expect(config('xero-bridge.capture.skip_urls'))->toBe([])
        ->and(config('xero-bridge.capture.channels'))->toBe([])
        ->and(config('xero-bridge.capture.redact.add'))->toBe([])
        ->and(config('xero-bridge.webhooks.queue'))->toBeNull()
        // While the keys that block was missing still arrive.
        ->and(config('xero-bridge.capture.mode'))->toBe($shipped['capture']['mode'])
        ->and(config('xero-bridge.capture.redact.placeholder'))->toBe($shipped['capture']['redact']['placeholder'])
        ->and(config('xero-bridge.capture.redact.keep'))->toBe([]);
});

it('never touches the connections block', function () {
    // Keyed by the host's own connection names. A shipped 'default' added
    // under a host that has none would hand every named connection the
    // shipped currency -- and XERO_ACCOUNT_CODE, where that is set -- by
    // inheritance.
    $published = publishedAtV106();
    $published['connections'] = [
        'acme' => ['account_code' => '4000'],
        'globex' => ['account_code' => '410002-001', 'currency' => 'SGD'],
    ];

    registerOverPublished('xero-bridge', $published);

    expect(config('xero-bridge.connections'))->toBe($published['connections'])
        ->and(app(XeroConfig::class)->connectionDefaults('acme'))->toBe(['account_code' => '4000']);
});

it('leaves a default connection missing a key as it is', function () {
    // Even inside 'default': a currency the host removed stays removed rather
    // than coming back as the shipped MYR.
    $published = publishedAtV106();
    $published['connections'] = ['default' => ['account_code' => '200']];

    registerOverPublished('xero-bridge', $published);

    expect(config('xero-bridge.connections'))->toBe(['default' => ['account_code' => '200']]);
});

it('fills a published myinvois config the same way', function () {
    $shipped = require __DIR__.'/../../config/myinvois.php';

    $published = $shipped;
    $published['http']['timeout'] = 45;
    unset($published['http']['retry_base_ms'], $published['audit']['retain_days']);

    registerOverPublished('myinvois', $published);

    expect(config('myinvois.http.retry_base_ms'))->toBe($shipped['http']['retry_base_ms'])
        ->and(config('myinvois.audit.retain_days'))->toBe($shipped['audit']['retain_days'])
        ->and(config('myinvois.http.timeout'))->toBe(45);
});

it('leaves a cached configuration alone', function () {
    // A cache built after the update already holds the filled result; one
    // built before it is what the in-code fallbacks are for. Either way the
    // provider must not rewrite it, exactly as mergeConfigFrom() does not.
    //
    // Both switches, because the framework answers the question two ways:
    // Laravel 11 looks for the cache file on every call, while later versions
    // remember the first answer in the container.
    $cache = (string) tempnam(sys_get_temp_dir(), 'xero-bridge-config-cache');
    $_SERVER['APP_CONFIG_CACHE'] = $cache;
    app()->instance('config_loaded_from_cache', true);

    try {
        expect(app()->configurationIsCached())->toBeTrue();

        registerOverPublished('xero-bridge', publishedAtV106());

        expect(config('xero-bridge'))->toBe(publishedAtV106());
    } finally {
        unset($_SERVER['APP_CONFIG_CACHE']);
        app()->instance('config_loaded_from_cache', false);
        File::delete($cache);
    }
});

it('never throws, because composer update boots the application through it', function () {
    // package:discover runs inside `composer update`, so an exception here
    // would fail the command that installs the release. A config store that
    // fails on the one read the fill makes must leave the config as it was.
    $original = app('config');
    $before = $original->all();

    app()->instance('config', new class($before) extends Repository
    {
        public function get($key, $default = null)
        {
            if ($key === 'xero-bridge') {
                throw new RuntimeException('The configuration store is unavailable.');
            }

            return parent::get($key, $default);
        }
    });

    try {
        $fill = new ReflectionMethod(XeroBridgeServiceProvider::class, 'fillMissingConfig');
        $provider = new XeroBridgeServiceProvider(app());

        $fill->invoke($provider, 'xero-bridge', __DIR__.'/../../config/xero-bridge.php', ['connections']);
        // A path that is not there: requiring it would be a fatal error, which
        // no catch block can see.
        $fill->invoke($provider, 'xero-bridge', __DIR__.'/../../config/no-such-file.php', []);

        expect(app('config')->all())->toBe($before);
    } finally {
        app()->instance('config', $original);
    }
});

/*
|--------------------------------------------------------------------------
| The walk itself
|--------------------------------------------------------------------------
*/

it('adds a missing key at any depth and never changes one that exists', function () {
    $filled = XeroBridgeServiceProvider::fillMissingKeys(
        ['block' => ['kept' => 'host', 'nulled' => null], 'list' => ['web']],
        [
            'block' => ['kept' => 'shipped', 'nulled' => 'shipped', 'inner' => ['deep' => 1]],
            'list' => ['web', 'auth'],
            'new' => false,
        ],
    );

    expect($filled)->toBe([
        'block' => ['kept' => 'host', 'nulled' => null, 'inner' => ['deep' => 1]],
        'list' => ['web'],
        'new' => false,
    ]);
});

it('keeps a host value whose shape differs from the shipped one', function () {
    // false where a block ships, a block where a scalar ships: kept, never
    // merged into, never replaced.
    $filled = XeroBridgeServiceProvider::fillMissingKeys(
        ['dedupe' => false, 'path' => ['odd' => true]],
        ['dedupe' => ['enabled' => false, 'table' => 'xero_webhook_events'], 'path' => 'webhook'],
    );

    expect($filled)->toBe(['dedupe' => false, 'path' => ['odd' => true]]);
});

/*
|--------------------------------------------------------------------------
| What the fill delivers
|--------------------------------------------------------------------------
*/

it('ships every 1.5.0 key with the behaviour hosts already had', function (string $line) {
    // A filled key changes nothing until its variable is set, which is only
    // true while each one defaults to what the code did before it existed.
    expect(file_get_contents(__DIR__.'/../../config/xero-bridge.php'))->toContain($line);
})->with([
    'writes.strict' => ["'strict' => (bool) env('XERO_WRITES_STRICT', false),"],
    'webhooks.prefix' => ["'prefix' => env('XERO_WEBHOOK_PREFIX'),"],
    'webhooks.unknown_tenants' => ["'unknown_tenants' => env('XERO_WEBHOOK_UNKNOWN_TENANTS', 'dispatch'),"],
]);
