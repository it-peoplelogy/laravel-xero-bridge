<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
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
                skipUrls: (array) $config->get('xero-bridge.capture.skip_urls', []),
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
