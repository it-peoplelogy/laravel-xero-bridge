<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\OAuth;

use Closure;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use Peoplelogy\XeroBridge\Contracts\ConnectionRepository;
use Peoplelogy\XeroBridge\Events\ConnectionExpired;
use Peoplelogy\XeroBridge\Events\TokenRefreshed;
use Peoplelogy\XeroBridge\Exceptions\XeroBridgeException;
use Peoplelogy\XeroBridge\Exceptions\XeroConfigurationException;
use Peoplelogy\XeroBridge\Exceptions\XeroConnectionNotFoundException;
use Peoplelogy\XeroBridge\Exceptions\XeroIdentityUnavailableException;
use Peoplelogy\XeroBridge\Exceptions\XeroReauthorizationRequiredException;
use Peoplelogy\XeroBridge\Models\XeroConnection;
use Peoplelogy\XeroBridge\Support\XeroConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Owns the token lifecycle: staleness, locked rotation, and the classification
 * of refresh failures.
 *
 * TWO INVARIANTS, both load-bearing:
 *
 *  1. Nothing here ever deletes a connection row. The most destructive thing
 *     it can do is set invalidated_at, which a reconnect clears.
 *  2. Only a genuine invalid_grant is terminal. A 5xx, a timeout, or a failed
 *     save leave the stored tokens exactly as they were.
 *
 * Both exist because the common failure mode is the opposite: clearing the
 * stored tokens on ANY failed refresh, so one transient 502 destroys the
 * connection and needs a human with a browser to recover it.
 */
final class TokenManager
{
    private bool $warnedAboutLocking = false;

    public function __construct(
        private readonly ConnectionRepository $connections,
        private readonly IdentityClient $identity,
        private readonly CacheFactory $cache,
        private readonly Dispatcher $events,
        private readonly LoggerInterface $logger,
        private readonly XeroConfig $config,
    ) {}

    public function resolveKey(?string $key = null): string
    {
        return $key ?: $this->config->defaultConnection();
    }

    public function find(?string $key = null): ?XeroConnection
    {
        return $this->connections->findByKey($this->resolveKey($key));
    }

    /** @return Collection<int, XeroConnection> */
    public function all(): Collection
    {
        return $this->connections->all();
    }

    public function connection(?string $key = null): XeroConnection
    {
        $key = $this->resolveKey($key);

        return $this->connections->findByKey($key)
            ?? throw XeroConnectionNotFoundException::forKey($key, $this->config->connectUrl($key));
    }

    public function needsRefresh(XeroConnection $connection): bool
    {
        return $connection->isExpired((int) $this->config->get('tokens.refresh_leeway', 60));
    }

    /**
     * The hot path: a connection guaranteed to hold a usable access token.
     */
    public function valid(?string $key = null): XeroConnection
    {
        $connection = $this->connection($key);

        // Short-circuit WITHOUT touching the network. Otherwise a five-minute
        // cron over a dead connection hammers identity.xero.com forever.
        if ($connection->isInvalidated()) {
            throw XeroReauthorizationRequiredException::for(
                (string) $connection->key,
                $this->config->connectUrl((string) $connection->key),
                (string) $connection->invalidated_reason,
            );
        }

        if (! $this->needsRefresh($connection)) {
            return $connection;
        }

        return $this->refresh($connection);
    }

    public function accessToken(?string $key = null): string
    {
        return $this->readToken($this->valid($key), 'access_token');
    }

    /**
     * Refresh because the clock says so.
     *
     * $minimumLifetime is how much validity the CALLER needs, in seconds,
     * defaulting to the configured leeway. A scheduled refresh passes a much
     * larger window than a web request would: without it, the re-check
     * inside the lock would apply the 60-second leeway and decide a token
     * expiring in five minutes needs nothing doing -- so the scheduled job
     * would quietly never refresh anything.
     */
    public function refresh(
        XeroConnection $connection,
        ?int $minimumLifetime = null,
        bool $force = false,
    ): XeroConnection {
        $lifetime = $minimumLifetime ?? (int) $this->config->get('tokens.refresh_leeway', 60);

        return $this->withLock(
            (string) $connection->key,
            function (XeroConnection $fresh) use ($lifetime, $force) {
                // Re-checked INSIDE the lock: whoever held it before us may
                // have just done the work.
                if (! $force && ! $fresh->isExpired($lifetime) && ! $fresh->isInvalidated()) {
                    return $fresh;
                }

                return $this->performRefresh($fresh);
            },
            $lifetime,
        );
    }

    /**
     * Refresh because a request 401'd on a token we believed was valid.
     *
     * The guard is the specific token that failed, not a boolean "force".
     * Under a thundering herd of 401s a boolean would burn one rotation per
     * caller; comparing tokens costs one string comparison and is race-free.
     */
    public function refreshBecauseOf(XeroConnection $connection, string $staleAccessToken): XeroConnection
    {
        return $this->withLock((string) $connection->key, function (XeroConnection $fresh) use ($staleAccessToken) {
            if (! $this->needsRefresh($fresh) && $this->readToken($fresh, 'access_token') !== $staleAccessToken) {
                // Someone else already rotated; reuse their token.
                return $fresh;
            }

            return $this->performRefresh($fresh);
        });
    }

    public function connectUrl(?string $key = null): string
    {
        return $this->config->connectUrl($this->resolveKey($key));
    }

    /**
     * Read an encrypted token, turning an APP_KEY rotation into a diagnosable
     * error instead of a bare DecryptException from inside Eloquent.
     */
    public function readToken(XeroConnection $connection, string $attribute): string
    {
        try {
            return (string) $connection->{$attribute};
        } catch (DecryptException) {
            throw XeroConfigurationException::unreadableTokens(
                (string) $connection->key,
                $this->config->connectUrl((string) $connection->key),
            );
        }
    }

    /**
     * @param  Closure(XeroConnection): XeroConnection  $callback
     * @param  int|null  $minimumLifetime  the validity the caller needs, used
     *                                     when deciding whether losing the
     *                                     race actually mattered
     */
    private function withLock(string $key, Closure $callback, ?int $minimumLifetime = null): XeroConnection
    {
        $lock = $this->lockFor($key);

        if ($lock === null) {
            // No lock provider available. Already warned; proceed rather than
            // fail, because a single-process app is still perfectly usable.
            return $callback($this->connection($key));
        }

        try {
            return $lock->block(
                (int) $this->config->get('tokens.lock_wait', 12),
                fn () => $callback($this->connection($key)),
            );
        } catch (LockTimeoutException) {
            // We lost the race. The winner has very likely just refreshed, so
            // re-read before concluding anything.
            //
            // Deliberately NOT falling back to an unlocked refresh: that is
            // precisely what rotates a refresh token out from under the
            // process that legitimately holds it.
            $fresh = $this->connection($key);

            $lifetime = $minimumLifetime ?? (int) $this->config->get('tokens.refresh_leeway', 60);

            if (! $fresh->isExpired($lifetime) && ! $fresh->isInvalidated()) {
                return $fresh;
            }

            throw (new XeroBridgeException(
                "Timed out waiting to refresh the Xero token for connection [{$key}]. "
                .'Another process is refreshing it; try again shortly.'
            ))->withConnectionKey($key);
        }
    }

    private function lockFor(string $key): ?Lock
    {
        $store = $this->cache->store($this->config->lockStore())->getStore();

        // The null store counts as lock-less. It does hand out locks, but
        // every one is granted at once and excludes nothing -- refreshing
        // under one is refreshing unlocked, so it is said, like any other
        // store that cannot lock. Its NoLock would behave exactly as the
        // unlocked path below does, so nothing else changes.
        if (! $store instanceof LockProvider || $store instanceof NullStore) {
            if (! $this->warnedAboutLocking) {
                $this->warnedAboutLocking = true;

                // Silent no-locking is how rotated refresh tokens get lost in
                // production, so say so loudly, once.
                $this->logger->warning(
                    'xero-bridge: the configured cache store cannot lock -- it supports no locks, or, like '
                    .'the null store, grants every one at once -- so Xero token refreshes are unsynchronised. '
                    .'Two concurrent refreshes will invalidate each other. Set XERO_LOCK_STORE to a redis, '
                    .'memcached or database store.'
                );
            }

            return null;
        }

        // Keyed on `key`, not tenant_id: under on_key_conflict=replace the
        // tenant behind a key can change, but the key is what identifies the
        // row being written.
        return $store->lock(
            "xero-bridge:refresh:{$key}",
            (int) $this->config->get('tokens.lock_ttl', 10),
        );
    }

    private function performRefresh(XeroConnection $connection): XeroConnection
    {
        $key = (string) $connection->key;
        $refreshToken = $this->readToken($connection, 'refresh_token');

        try {
            $tokens = $this->identity->refresh($refreshToken, $key);
        } catch (XeroIdentityUnavailableException $e) {
            // TRANSIENT. Counters only -- the tokens are untouched, no event
            // is fired and nothing is invalidated.
            $this->connections->recordTransientFailure($connection, $e->getMessage());

            throw $e;
        } catch (XeroReauthorizationRequiredException $e) {
            // TERMINAL, and the only path that reaches here.
            $alreadyInvalid = $connection->isInvalidated();

            $connection = $this->connections->markInvalidated($connection, 'invalid_grant');

            if (! $alreadyInvalid) {
                // Only on the valid -> invalidated transition, so a nightly
                // cron cannot spam listeners.
                $this->events->dispatch(new ConnectionExpired(
                    $connection,
                    'invalid_grant',
                    $this->config->connectUrl($key),
                ));
            }

            throw $e;
        }

        try {
            $connection = $this->connections->persistRefreshedTokens($connection, $tokens);
        } catch (Throwable $e) {
            // We hold tokens we could not save. Xero keeps the PREVIOUS
            // refresh token usable for a 30-minute grace window precisely for
            // this, so the correct response is to shout and retry -- never to
            // invalidate.
            $this->logger->critical(
                "xero-bridge: rotated Xero tokens for connection [{$key}] could not be saved. "
                .'The previous refresh token remains valid for roughly 30 minutes (Xero grace '
                .'window), so retry soon.',
                ['exception' => $e->getMessage()]
            );

            throw $e;
        }

        $this->events->dispatch(new TokenRefreshed($connection, $connection->expires_at));

        return $connection;
    }
}
