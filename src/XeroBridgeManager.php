<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge;

use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Peoplelogy\XeroBridge\Client\XeroHttpClient;
use Peoplelogy\XeroBridge\Models\XeroConnection;
use Peoplelogy\XeroBridge\OAuth\TokenManager;
use Peoplelogy\XeroBridge\Resources\Contacts;
use Peoplelogy\XeroBridge\Resources\Invoices;
use Peoplelogy\XeroBridge\Resources\Payments;
use Peoplelogy\XeroBridge\Resources\Settings;
use Peoplelogy\XeroBridge\Support\ClientRegistry;
use Peoplelogy\XeroBridge\Support\ConnectionDefaults;
use Peoplelogy\XeroBridge\Support\XeroConfig;

/**
 * The public entry point, reached through the XeroBridge facade.
 */
class XeroBridgeManager
{
    protected ?string $connectionKey = null;

    /** @var array<string, mixed> */
    protected array $overrideDefaults = [];

    protected static ?Closure $afterConnect = null;

    public function __construct(
        protected readonly TokenManager $tokens,
        protected readonly ClientRegistry $registry,
        protected readonly XeroConfig $config,
    ) {}

    /**
     * Scope to a connection.
     *
     * Returns a CLONE, never $this. The manager is a container singleton
     * behind a facade, so a mutating setter would leak the key into every
     * later call in the same request -- a cross-tenant data bug of exactly
     * the kind this package exists to prevent. With a clone,
     * XeroBridge::connection('acme')->invoices() is scoped to that
     * expression alone, and XeroBridge::invoices() always means the default.
     */
    public function connection(?string $key = null): static
    {
        $clone = clone $this;
        $clone->connectionKey = $key;

        return $clone;
    }

    public function key(): string
    {
        return $this->connectionKey ?: $this->config->defaultConnection();
    }

    public function client(): XeroHttpClient
    {
        return $this->registry->client($this->key());
    }

    public function tokens(): TokenManager
    {
        return $this->tokens;
    }

    public function invoices(): Invoices
    {
        return $this->resource(Invoices::class);
    }

    public function contacts(): Contacts
    {
        return $this->resource(Contacts::class);
    }

    public function payments(): Payments
    {
        return $this->resource(Payments::class);
    }

    /**
     * Read-only organisation configuration. Usually the first thing a
     * consumer needs: account codes and tax types differ per organisation.
     */
    public function settings(): Settings
    {
        return $this->resource(Settings::class);
    }

    /** @return Collection<int, XeroConnection> */
    public function connections(): Collection
    {
        return $this->tokens->all();
    }

    public function connectUrl(?string $key = null): string
    {
        return $this->config->connectUrl($key ?: $this->key());
    }

    /**
     * Any endpoint the package has not wrapped yet.
     *
     * @param  array<mixed>  $payload
     * @param  array<string, string>  $headers
     * @return array<mixed>
     */
    public function request(string $method, string $uri, array $payload = [], array $headers = []): array
    {
        return $this->client()->request($method, $uri, $payload, $headers);
    }

    /**
     * The undecoded Response -- status, headers and body -- for an endpoint the
     * package has not wrapped. It is still requested as JSON: the client always
     * sends Accept: application/json, so an invoice PDF comes from
     * invoices()->pdf($id), not from here.
     */
    public function raw(string $method, string $uri, array $payload = [], array $headers = []): Response
    {
        return $this->client()->send($method, $uri, $payload, $headers);
    }

    public function defaults(): ConnectionDefaults
    {
        return ConnectionDefaults::for($this->key(), (array) $this->config->get('connections', []))
            ->merge($this->overrideDefaults);
    }

    /** @param array<string, mixed> $overrides */
    public function withDefaults(array $overrides): static
    {
        $clone = clone $this;
        $clone->overrideDefaults = array_replace($this->overrideDefaults, $overrides);

        return $clone;
    }

    /**
     * Where the OAuth callback should send the user. Registered as a closure
     * because a config file cannot hold one once the config is cached.
     */
    public static function redirectAfterConnectUsing(Closure $callback): void
    {
        static::$afterConnect = $callback;
    }

    public static function afterConnectCallback(): ?Closure
    {
        return static::$afterConnect;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    protected function resource(string $class): object
    {
        $key = $this->key();

        // Resources are memoised per connection with their defaults baked in
        // at construction. That is correct for the plain case, and WRONG the
        // moment withDefaults() is in play: the memo key does not include the
        // overrides, so the cached instance would either ignore them or -- far
        // worse -- keep serving them to every later plain call on the same
        // connection. Build a throwaway instance instead, so an override is
        // scoped to the expression that asked for it and cannot leak.
        if ($this->overrideDefaults !== []) {
            return new $class($this->client(), $this->defaults(), $key);
        }

        return $this->registry->resource($key, $class, fn () => new $class(
            $this->client(),
            $this->defaults(),
            $key,
        ));
    }
}
