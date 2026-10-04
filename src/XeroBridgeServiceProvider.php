<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\Client\Factory as HttpFactory;
use Peoplelogy\XeroBridge\Capture\ApiCallRecorder;
use Peoplelogy\XeroBridge\Client\RetryPolicy;
use Peoplelogy\XeroBridge\Commands\InstallCommand;
use Peoplelogy\XeroBridge\Commands\PruneCommand;
use Peoplelogy\XeroBridge\Commands\RefreshTokensCommand;
use Peoplelogy\XeroBridge\Commands\StatusCommand;
use Peoplelogy\XeroBridge\Contracts\ConnectionRepository;
use Peoplelogy\XeroBridge\Http\Middleware\EnsureConsoleEnabled;
use Peoplelogy\XeroBridge\MyInvois\MyInvoisAudit;
use Peoplelogy\XeroBridge\MyInvois\MyInvoisClient;
use Peoplelogy\XeroBridge\MyInvois\MyInvoisConfig;
use Peoplelogy\XeroBridge\OAuth\AuthorizationUrlBuilder;
use Peoplelogy\XeroBridge\OAuth\IdentityClient;
use Peoplelogy\XeroBridge\OAuth\OAuthStateStore;
use Peoplelogy\XeroBridge\OAuth\TokenManager;
use Peoplelogy\XeroBridge\Repositories\EloquentConnectionRepository;
use Peoplelogy\XeroBridge\Support\ClientRegistry;
use Peoplelogy\XeroBridge\Support\Diagnostics;
use Peoplelogy\XeroBridge\Support\RedactionPolicy;
use Peoplelogy\XeroBridge\Support\TableGuard;
use Peoplelogy\XeroBridge\Support\XeroConfig;
use Peoplelogy\XeroBridge\Webhooks\WebhookEventRecorder;
use Peoplelogy\XeroBridge\Writes\XeroWriteRecorder;
use Psr\Log\LoggerInterface;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Throwable;

class XeroBridgeServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-xero-bridge')
            ->hasConfigFile()
            // Namespace `xero-bridge::`, publish tag `xero-bridge-views`. The
            // only view is the test console, which needs no publishing to work.
            ->hasViews()
            // Each ships as a .php.stub and is published deliberately. A
            // consumer who upgrades and never runs vendor:publish gets no new
            // tables and no behaviour change -- TableGuard keeps the recorders
            // silent until the table actually exists.
            ->hasMigration('create_xero_connections_table')
            ->hasMigration('create_xero_webhook_events_table')
            ->hasMigration('create_xero_write_records_table')
            ->hasMigration('create_xero_api_calls_table')
            ->hasCommands([
                InstallCommand::class,
                StatusCommand::class,
                RefreshTokensCommand::class,
                PruneCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        // spatie merged config/xero-bridge.php one level deep just before this
        // ran (PackageServiceProvider::register()); this fills the levels
        // below. `connections` is keyed by the host's own connection names, so
        // it is never filled key by key -- see fillMissingConfig().
        $this->fillMissingConfig('xero-bridge', __DIR__.'/../config/xero-bridge.php', opaque: ['connections']);

        $this->app->singleton(XeroConfig::class, fn ($app) => new XeroConfig(
            $app->make(ConfigRepository::class),
        ));

        $this->app->singleton(ConnectionRepository::class, fn ($app) => new EloquentConnectionRepository(
            $app->make(XeroConfig::class),
            $app->make(ConnectionResolverInterface::class),
        ));

        $this->app->singleton(IdentityClient::class, fn ($app) => new IdentityClient(
            $app->make(HttpFactory::class),
            $app->make(XeroConfig::class),
        ));

        $this->app->singleton(AuthorizationUrlBuilder::class, fn ($app) => new AuthorizationUrlBuilder(
            $app->make(XeroConfig::class),
        ));

        // Not a singleton: it reads the CURRENT request's session.
        $this->app->bind(OAuthStateStore::class, fn ($app) => new OAuthStateStore(
            $app->make('session.store'),
        ));

        $this->app->singleton(TokenManager::class, fn ($app) => new TokenManager(
            $app->make(ConnectionRepository::class),
            $app->make(IdentityClient::class),
            $app->make(CacheFactory::class),
            $app->make(Dispatcher::class),
            $app->make(LoggerInterface::class),
            $app->make(XeroConfig::class),
        ));

        $this->app->singleton(RetryPolicy::class, fn ($app) => new RetryPolicy(
            $app->make(XeroConfig::class),
        ));

        // A singleton so its memoisation survives XeroBridgeManager::connection()
        // returning a clone.
        $this->app->singleton(ClientRegistry::class, fn ($app) => new ClientRegistry(
            $app->make(TokenManager::class),
            $app->make(HttpFactory::class),
            $app->make(RetryPolicy::class),
            $app->make(XeroConfig::class),
            $app->make(LoggerInterface::class),
        ));

        $this->app->singleton(XeroBridgeManager::class, fn ($app) => new XeroBridgeManager(
            $app->make(TokenManager::class),
            $app->make(ClientRegistry::class),
            $app->make(XeroConfig::class),
        ));

        // One memoised hasTable() per table per process. Every recorder goes
        // through it, which is what lets a consumer upgrade without migrating.
        $this->app->singleton(TableGuard::class, fn ($app) => new TableGuard(
            $app->make(LoggerInterface::class),
        ));

        // Built from ConfigRepository rather than XeroConfig, so the
        // MyInvois module can depend on the recorder without breaching the
        // arch rule that keeps LHDN and Xero apart.
        //
        // `capture` is a TOP-LEVEL key, which is what makes it safe to add in a
        // point release: mergeConfigFrom merges one level deep, so a consumer
        // running a config file published before this version still receives
        // the whole block from the package.
        $this->app->singleton(RedactionPolicy::class, function ($app) {
            $config = $app->make(ConfigRepository::class);

            return new RedactionPolicy(
                add: (array) $config->get('xero-bridge.capture.redact.add', []),
                keep: (array) $config->get('xero-bridge.capture.redact.keep', []),
                placeholder: (string) $config->get('xero-bridge.capture.redact.placeholder', '[redacted]'),
                // The fallback is the pattern the config file ships, so a
                // capture block that lost the line still never stores the
                // OnlineInvoice capability link.
                skipUrls: (array) $config->get('xero-bridge.capture.skip_urls', ['#/OnlineInvoice(\?|$)#i']),
                maxDepth: (int) $config->get('xero-bridge.capture.redact.max_depth', 24),
                maxNodes: (int) $config->get('xero-bridge.capture.redact.max_nodes', 20000),
            );
        });

        $this->app->singleton(ApiCallRecorder::class, fn ($app) => new ApiCallRecorder(
            $app->make(ConfigRepository::class),
            $app->make(RedactionPolicy::class),
            $app->make(TableGuard::class),
            $app->make(LoggerInterface::class),
        ));

        $this->app->singleton(XeroWriteRecorder::class, fn ($app) => new XeroWriteRecorder(
            $app->make(XeroConfig::class),
            $app->make(TableGuard::class),
            $app->make(LoggerInterface::class),
        ));

        $this->app->singleton(WebhookEventRecorder::class, fn ($app) => new WebhookEventRecorder(
            $app->make(XeroConfig::class),
            $app->make(TableGuard::class),
            $app->make(LoggerInterface::class),
        ));

        // Shared by xero-bridge:status and the test console, so the two can
        // never disagree about what "healthy" means.
        $this->app->singleton(Diagnostics::class, fn ($app) => new Diagnostics(
            $app->make(XeroConfig::class),
            $app->make(TokenManager::class),
            $app->make(CacheFactory::class),
            $app->make(ConnectionRepository::class),
        ));

        $this->app->alias(XeroBridgeManager::class, 'xero-bridge');

        $this->registerMyInvois();
    }

    /**
     * The MyInvois module: LHDN Malaysia taxpayer TIN validation.
     *
     * A separate concern from Xero and kept at arm's length -- its own config
     * file, its own namespace, its own exception, and no Xero class touches it.
     *
     * The config is merged HERE rather than through spatie's hasConfigFile(),
     * which would publish it under the shared `xero-bridge-config` tag. Sharing
     * that tag means `vendor:publish --tag=xero-bridge-config --force` silently
     * overwrites this file too, and a stale published config is exactly how a
     * renamed key goes unnoticed.
     *
     * Both bindings are registered even when the module is DISABLED, on
     * purpose. Gating them would turn "MyInvois is off" into a
     * BindingResolutionException thrown from deep inside the container; leaving
     * them bound means the client throws one line naming MYINVOIS_ENABLED.
     */
    private function registerMyInvois(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/myinvois.php', 'myinvois');
        $this->fillMissingConfig('myinvois', __DIR__.'/../config/myinvois.php');

        $this->app->singleton(MyInvoisConfig::class, fn ($app) => new MyInvoisConfig(
            $app->make(ConfigRepository::class),
        ));

        $this->app->singleton(MyInvoisAudit::class, fn ($app) => new MyInvoisAudit(
            $app->make(MyInvoisConfig::class),
            $app->make(TableGuard::class),
            $app->make(LoggerInterface::class),
        ));

        $this->app->singleton(MyInvoisClient::class, fn ($app) => new MyInvoisClient(
            $app->make(MyInvoisConfig::class),
            $app->make(HttpFactory::class),
            $app->make(CacheFactory::class),
            $app->make(MyInvoisAudit::class),
        ));
    }

    /**
     * Give the application's config every key it lacks, at any depth, from the
     * package's own file -- so that `composer update` alone delivers a setting
     * added by a later release.
     *
     * mergeConfigFrom(), which runs first, merges ONE level deep. A published
     * config/xero-bridge.php keeps its own copy of every block it has, so a key
     * a later release adds INSIDE a block (webhooks.prefix, writes.strict)
     * would never reach it, and the env variable documented for that key would
     * silently do nothing. This walks the package file and adds whatever is
     * missing.
     *
     * A key the application already has is never touched, whatever its value:
     * null, false, '' and [] are all decisions. Only associative arrays are
     * walked into. A list -- a middleware stack, skip_urls -- is one value, so a
     * shorter list stays exactly as the host wrote it. The top-level blocks in
     * $opaque are not walked into at all: `connections` is keyed by the host's
     * own connection names, and a 'default' entry added under hosts that have
     * none would hand every named connection a currency, or an account code
     * from XERO_ACCOUNT_CODE, that it never had.
     *
     * Every key a published file can lack without having been edited is one a
     * later release added, and the code reads each of those with the shipped
     * default as its fallback. Filling one therefore changes nothing until its
     * env variable is set. A line a host DELETED comes back with the shipped
     * default; switching something off means setting it, not removing it.
     *
     * Skipped when the configuration is cached, exactly as mergeConfigFrom() is:
     * a cache built after the update already holds the filled result, and one
     * built before it keeps running on those fallbacks.
     *
     * It never throws. `composer update` runs package:discover, which boots the
     * application, so an exception here would fail the very command that
     * installs the release. A failure leaves the configuration as
     * mergeConfigFrom() left it, which is how every earlier release ran.
     *
     * @param  list<string>  $opaque  top-level blocks never filled key by key
     */
    private function fillMissingConfig(string $key, string $path, array $opaque = []): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        try {
            // Checked rather than caught: requiring a missing file is a fatal
            // error, which no catch block sees.
            if (! is_file($path)) {
                return;
            }

            $config = $this->app->make('config');
            $current = $config->get($key);
            $shipped = require $path;

            if (is_array($current) && is_array($shipped)) {
                $config->set($key, self::fillMissingKeys(
                    $current,
                    array_diff_key($shipped, array_flip($opaque)),
                ));
            }
        } catch (Throwable) {
            // Silent on purpose. Nothing is lost -- what is left is exactly how
            // every release before 1.5.0 ran -- and a log call made while the
            // providers are still registering can itself be what fails.
        }
    }

    /**
     * The walk behind fillMissingConfig(), kept pure so it can be tested on its
     * own: every key of $shipped that $config lacks is added, and a key $config
     * has is kept -- walked into only when both sides are associative arrays.
     *
     * @internal
     *
     * @param  array<array-key, mixed>  $config
     * @param  array<array-key, mixed>  $shipped
     * @return array<array-key, mixed>
     */
    public static function fillMissingKeys(array $config, array $shipped): array
    {
        foreach ($shipped as $name => $default) {
            if (! array_key_exists($name, $config)) {
                $config[$name] = $default;
            } elseif (self::isBlock($default) && self::isBlock($config[$name])) {
                $config[$name] = self::fillMissingKeys($config[$name], $default);
            }
        }

        return $config;
    }

    /**
     * A block of settings, as opposed to a scalar or a list. [] counts as a
     * list: an empty array in a published file is a value, not a gap.
     *
     * @phpstan-assert-if-true array<array-key, mixed> $value
     */
    private static function isBlock(mixed $value): bool
    {
        return is_array($value) && ! array_is_list($value);
    }

    public function packageBooted(): void
    {
        // Loaded here rather than via ->hasRoute(), because spatie calls
        // configurePackage() BEFORE the package config is merged, so the
        // enabled flag is not readable at that point.
        if ($this->app['config']->get('xero-bridge.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        // Separate file and separate flag: the webhook route must carry no
        // session and no cookies, which the connect/callback routes require.
        if ($this->app['config']->get('xero-bridge.webhooks.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/webhook.php');
        }

        // Third flag, third file, deliberately independent of routes.enabled:
        // a host may register its own connect/callback and still want the
        // console. The same gate runs again per request as middleware, because
        // route:cache would otherwise carry the route past this check.
        if (EnsureConsoleEnabled::enabled($this->app)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/console.php');
        }

        // Its OWN tag, so republishing the Xero config can never overwrite this
        // file and vice versa. spatie's hasConfigFile() would put both under
        // `xero-bridge-config`, which is one --force away from losing one of
        // them. Registered regardless of whether the module is enabled: a host
        // switching it on should not also have to guess that the config exists.
        if ($this->app->runningInConsole()) {
            $this->publishes(
                [__DIR__.'/../config/myinvois.php' => config_path('myinvois.php')],
                'myinvois-config',
            );

            // Its own tag as well, so publishing the Xero migrations never
            // drags a Malaysian tax table into a database that has no use
            // for one.
            $this->publishes(
                [__DIR__.'/../database/migrations/create_myinvois_validations_table.php.stub' => $this->generateMigrationName(
                    'create_myinvois_validations_table',
                    now()->addSecond(),
                )],
                'myinvois-migrations',
            );
        }
    }
}
