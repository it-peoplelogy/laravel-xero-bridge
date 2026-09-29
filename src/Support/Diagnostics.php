<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Support;

use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Route;
use Peoplelogy\XeroBridge\Models\XeroConnection;
use Peoplelogy\XeroBridge\OAuth\TokenManager;
use Throwable;

/**
 * Everything that can be said about the state of the integration WITHOUT
 * calling Xero.
 *
 * Shared by `xero-bridge:status` and the test console so the two can never
 * disagree about what "healthy" means. Nothing here performs an API request:
 * the most it does is read the connections table and take a cache lock.
 *
 * No method on this class may return an access or refresh token. The model
 * hides them, and a status dump is the obvious place for one to leak.
 */
final class Diagnostics
{
    public function __construct(
        private readonly XeroConfig $config,
        private readonly TokenManager $tokens,
        private readonly CacheFactory $cache,
    ) {}

    /**
     * Configuration that is absent or wrong, keyed by config key, valued with
     * the environment variable that sets it.
     *
     * @return array<string, string>
     */
    public function missingConfig(): array
    {
        $missing = [];

        if (($this->config->get('client_id') ?? '') === '') {
            $missing['client_id'] = 'XERO_CLIENT_ID';
        }

        if (($this->config->get('client_secret') ?? '') === '') {
            $missing['client_secret'] = 'XERO_CLIENT_SECRET';
        }

        if (! Scopes::has($this->config->get('scopes'), Scopes::OFFLINE_ACCESS)) {
            $missing['scopes'] = 'XERO_SCOPES (must include offline_access)';
        }

        return $missing;
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(XeroConnection $connection): array
    {
        return [
            'key' => (string) $connection->key,
            'organisation' => $connection->displayName(),
            'tenant_id' => (string) $connection->tenant_id,
            'tenant_type' => $connection->tenant_type,
            'expires_at' => $connection->expires_at?->toIso8601String(),
            'expires_in' => $connection->expires_at
                ? (int) now()->diffInSeconds($connection->expires_at, false)
                : null,
            'expired' => $connection->isExpired(),
            'usable' => $connection->isUsable(),
            'needs_reauthorisation' => $connection->isInvalidated(),
            'invalidated_reason' => $connection->invalidated_reason,
            'failure_count' => $connection->failure_count,
            'last_refreshed_at' => $connection->last_refreshed_at?->toIso8601String(),
            'last_failure_at' => $connection->last_failure_at?->toIso8601String(),
            'scopes' => $connection->scopeList(),
            'connect_url' => $this->config->connectUrl((string) $connection->key),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function connections(): array
    {
        return $this->tokens->all()
            ->map(fn (XeroConnection $c): array => $this->describe($c))
            ->values()
            ->all();
    }

    /**
     * Things that are not broken yet but will be.
     *
     * @param  list<array<string, mixed>>  $rows  the output of connections()
     * @return list<string>
     */
    public function warnings(array $rows): array
    {
        $warnings = [];

        if ($this->config->webhookKey() === null && $this->config->get('webhooks.enabled', true)) {
            $warnings[] = 'No XERO_WEBHOOK_KEY is set, so every webhook will be rejected with a 401.';
        }

        if (($this->config->get('tokens.lock_store') ?? '') === ''
            && in_array((string) config('cache.default'), ['array', 'file'], true)
        ) {
            $warnings[] = 'The cache store does not lock across processes, so two concurrent token '
                .'refreshes would invalidate each other. Set XERO_LOCK_STORE.';
        }

        foreach ($rows as $row) {
            if ($row['failure_count'] > 0 && ! $row['needs_reauthorisation']) {
                $warnings[] = sprintf(
                    'Connection [%s] has %d recent transient failure(s).',
                    $row['key'],
                    $row['failure_count'],
                );
            }
        }

        return $warnings;
    }

    /**
     * The two things that fail late and confusingly if they are wrong, so they
     * are proven up front instead: the consent flow refuses to start on a bad
     * redirect URI, and a token refresh dies inside the lock if the configured
     * cache store cannot be reached.
     *
     * @return array<string, array<string, mixed>>
     */
    public function preflight(): array
    {
        return [
            'connect' => $this->probe('Consent flow can start', function (): ?string {
                $this->config->assertReadyToConnect();

                return null;
            }),
            'lock' => $this->probe('Token-refresh lock is usable', fn (): ?string => $this->probeLock()),
        ];
    }

    /**
     * What the application is configured to do. The app keys are reported as
     * booleans only -- the client secret must never reach a browser or a log.
     *
     * @return array<string, mixed>
     */
    public function environment(): array
    {
        $scopes = (string) $this->config->get('scopes');

        return [
            'client_id_set' => ($this->config->get('client_id') ?? '') !== '',
            'client_secret_set' => ($this->config->get('client_secret') ?? '') !== '',
            'webhook_key_set' => $this->config->webhookKey() !== null,
            'redirect_uri' => $this->config->get('redirect_uri')
                ?: '(null - falls back to the callback route)',
            'scopes' => Scopes::parse($scopes),
            'has_offline_access' => Scopes::has($scopes, Scopes::OFFLINE_ACCESS),
            'default_connection' => $this->config->defaultConnection(),
            'table' => $this->tableName(),
            'routes_enabled' => (bool) $this->config->get('routes.enabled', true),
            'webhooks_enabled' => (bool) $this->config->get('webhooks.enabled', true),
            'lock_store' => $this->lockStoreName(),
            'idempotency' => (bool) $this->config->get('http.idempotency', true),
        ];
    }

    /**
     * Every URL the integration exposes.
     *
     * Route::has() guarded throughout: a host that sets routes.enabled=false or
     * webhooks.enabled=false has no such named route, and an unguarded route()
     * would turn a diagnostics page into a 500.
     *
     * @return array<string, string|null>
     */
    public function urls(): array
    {
        return [
            'connect' => $this->config->connectUrl($this->config->defaultConnection()),
            'callback' => $this->urlFor('callback'),
            'webhook' => $this->urlFor('webhook'),
            'console' => $this->urlFor('console'),
        ];
    }

    /**
     * The whole picture, as the console's boot payload.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $connections = $this->connections();

        return [
            'config' => $this->environment(),
            'preflight' => $this->preflight(),
            'urls' => $this->urls(),
            'connections' => $connections,
            'warnings' => $this->warnings($connections),
        ];
    }

    private function urlFor(string $suffix): ?string
    {
        $name = $this->config->routeName($suffix);

        return Route::has($name) ? route($name) : null;
    }

    /**
     * The table as the database will actually see it, prefix included. The
     * package stores a BARE name and lets the host connection apply its own
     * prefix, so the configured value alone is misleading.
     */
    private function tableName(): string
    {
        $connection = $this->config->get('database.connection')
            ?: config('database.default');

        $prefix = (string) config('database.connections.'.$connection.'.prefix');

        return $prefix.(string) $this->config->get('database.table', 'xero_connections');
    }

    private function lockStoreName(): string
    {
        return (string) ($this->config->get('tokens.lock_store') ?: config('cache.default'));
    }

    /**
     * Mirrors TokenManager::lockFor() exactly, so this proves the same thing a
     * real refresh would hit.
     *
     * A store with no lock provider is a warning, not a failure: the bridge
     * logs and carries on unlocked, which is fine in one process and loses
     * rotated refresh tokens across several.
     */
    private function probeLock(): ?string
    {
        $name = $this->lockStoreName();

        /** @var string|null $store */
        $store = $this->config->get('tokens.lock_store');

        $repository = $this->cache->store($store);

        if (! $repository instanceof CacheRepository) {
            return "The [{$name}] cache store is not a standard cache repository, so its locking "
                .'behaviour cannot be verified from here.';
        }

        $driver = $repository->getStore();

        if (! $driver instanceof LockProvider) {
            return "The [{$name}] cache store supports no locks, so token refreshes run "
                .'unsynchronised. Fine in a single process; across several, two refreshes will '
                .'invalidate each other. Set XERO_LOCK_STORE to a redis, memcached or database store.';
        }

        $lock = $driver->lock('xero-bridge:preflight-probe', 2);

        if ($lock->get()) {
            $lock->release();
        }

        return null;
    }

    /**
     * Runs one check. The callable returns null when all is well, or a string
     * to report as a warning; anything thrown is a failure.
     *
     * @param  callable(): (string|null)  $check
     * @return array{label: string, ok: bool, severity?: string, type?: string, message?: string}
     */
    private function probe(string $label, callable $check): array
    {
        try {
            $warning = $check();

            if ($warning !== null) {
                return [
                    'label' => $label,
                    'ok' => false,
                    'severity' => 'warn',
                    'message' => $warning,
                ];
            }

            return ['label' => $label, 'ok' => true];
        } catch (Throwable $e) {
            return [
                'label' => $label,
                'ok' => false,
                'severity' => 'fail',
                'type' => class_basename($e),
                'message' => $e->getMessage(),
            ];
        }
    }
}
