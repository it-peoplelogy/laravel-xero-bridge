<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\Client\Factory as HttpFactory;
use Peoplelogy\XeroBridge\Client\RetryPolicy;
use Peoplelogy\XeroBridge\Commands\InstallCommand;
use Peoplelogy\XeroBridge\Commands\RefreshTokensCommand;
use Peoplelogy\XeroBridge\Commands\StatusCommand;
use Peoplelogy\XeroBridge\Contracts\ConnectionRepository;
use Peoplelogy\XeroBridge\OAuth\AuthorizationUrlBuilder;
use Peoplelogy\XeroBridge\OAuth\IdentityClient;
use Peoplelogy\XeroBridge\OAuth\OAuthStateStore;
use Peoplelogy\XeroBridge\OAuth\TokenManager;
use Peoplelogy\XeroBridge\Repositories\EloquentConnectionRepository;
use Peoplelogy\XeroBridge\Support\ClientRegistry;
use Peoplelogy\XeroBridge\Support\XeroConfig;
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
            ->hasMigration('create_xero_connections_table')
            ->hasCommands([
                InstallCommand::class,
                StatusCommand::class,
                RefreshTokensCommand::class,
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

        $this->app->alias(XeroBridgeManager::class, 'xero-bridge');
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
    }
}
