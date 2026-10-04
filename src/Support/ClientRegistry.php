<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Support;

use Closure;
use Illuminate\Http\Client\Factory as HttpFactory;
use Peoplelogy\XeroBridge\Client\RetryPolicy;
use Peoplelogy\XeroBridge\Client\XeroHttpClient;
use Peoplelogy\XeroBridge\OAuth\TokenManager;
use Psr\Log\LoggerInterface;

/**
 * Memoises clients and resources per connection key.
 *
 * This lives in a container singleton rather than on the manager because
 * XeroBridgeManager::connection() returns a CLONE -- if the caches lived on
 * the manager, clone would copy them by value and every connection() call
 * would rebuild a client from scratch.
 */
final class ClientRegistry
{
    /** @var array<string, XeroHttpClient> */
    private array $clients = [];

    /** @var array<string, array<class-string, object>> */
    private array $resources = [];

    public function __construct(
        private readonly TokenManager $tokens,
        private readonly HttpFactory $http,
        private readonly RetryPolicy $policy,
        private readonly XeroConfig $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function client(string $key): XeroHttpClient
    {
        return $this->clients[$key] ??= new XeroHttpClient(
            $key,
            $this->tokens,
            $this->http,
            $this->policy,
            $this->config,
            $this->logger,
        );
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @param  Closure(): T  $factory
     * @return T
     */
    public function resource(string $key, string $class, Closure $factory): object
    {
        /** @var T */
        return $this->resources[$key][$class] ??= $factory();
    }

    /**
     * Drop everything. Used by tests; nothing in the package calls it, so in a
     * long-lived process (a queue worker, Octane) memoised resources -- and the
     * connection defaults baked into them -- last until the process ends.
     */
    public function flush(): void
    {
        $this->clients = [];
        $this->resources = [];
    }
}
