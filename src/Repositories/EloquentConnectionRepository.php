<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Repositories;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Collection;
use Peoplelogy\XeroBridge\Contracts\ConnectionRepository;
use Peoplelogy\XeroBridge\Exceptions\ConnectionKeyConflictException;
use Peoplelogy\XeroBridge\Exceptions\TenantAlreadyConnectedException;
use Peoplelogy\XeroBridge\Models\XeroConnection;
use Peoplelogy\XeroBridge\OAuth\TenantInfo;
use Peoplelogy\XeroBridge\OAuth\TokenResponse;
use Peoplelogy\XeroBridge\Support\Clock;
use Peoplelogy\XeroBridge\Support\XeroConfig;

/**
 * Default persistence, and the home of the key/tenant conflict matrix.
 *
 * Both `key` and `tenant_id` are unique, which means a reconnect can collide
 * in four distinct ways. Leaving that undefined means a raw QueryException
 * surfacing as a 500 in the middle of the OAuth callback, so every case is
 * resolved explicitly, inside one transaction, with both candidate rows
 * locked (a double-clicked callback is a real race).
 */
final class EloquentConnectionRepository implements ConnectionRepository
{
    public function __construct(
        private readonly XeroConfig $config,
        private readonly ConnectionResolverInterface $db,
    ) {}

    public function findByKey(string $key): ?XeroConnection
    {
        return $this->query()->where('key', $key)->first();
    }

    public function findByTenantId(string $tenantId): ?XeroConnection
    {
        return $this->query()->where('tenant_id', $tenantId)->first();
    }

    /** @return Collection<int, XeroConnection> */
    public function all(): Collection
    {
        return $this->query()->orderBy('key')->get();
    }

    public function upsert(string $key, TenantInfo $tenant, TokenResponse $tokens): XeroConnection
    {
        $connection = $this->db->connection($this->config->get('database.connection'));

        return $connection->transaction(function () use ($key, $tenant, $tokens) {
            $byKey = $this->query()->where('key', $key)->lockForUpdate()->first();
            $byTenant = $this->query()->where('tenant_id', $tenant->tenantId)->lockForUpdate()->first();

            // Case D/E -- this organisation is already stored under another
            // key. Evaluated FIRST, because it is the more surprising of the
            // two conflicts and its default is to refuse.
            if ($byTenant !== null && ($byKey === null || $byTenant->getKey() !== $byKey->getKey())) {
                $policy = (string) $this->config->get('on_tenant_conflict', 'error');

                if ($policy !== 'rekey') {
                    throw TenantAlreadyConnectedException::make(
                        $tenant->displayName(),
                        (string) $byTenant->key,
                        $key,
                    );
                }

                // Re-key: the existing row moves to the new key. If that key
                // is occupied by a different row, that row is removed first
                // so the unique index still holds.
                if ($byKey !== null) {
                    $byKey->delete();
                }

                $byTenant->key = $key;
                $byKey = $byTenant;
            }

            // Case C -- the key exists but points at a different org.
            if ($byKey !== null && $byKey->tenant_id !== $tenant->tenantId) {
                $policy = (string) $this->config->get('on_key_conflict', 'replace');

                if ($policy !== 'replace') {
                    throw ConnectionKeyConflictException::make(
                        $key,
                        $byKey->displayName(),
                        $tenant->displayName(),
                    );
                }
            }

            $attributes = [
                'key' => $key,
                'tenant_id' => $tenant->tenantId,
                'connection_id' => $tenant->id,
                'tenant_name' => $tenant->tenantName,
                'tenant_type' => $tenant->tenantType,
                'auth_event_id' => $tenant->authEventId,
                'access_token' => $tokens->accessToken,
                'refresh_token' => (string) $tokens->refreshToken,
                'expires_at' => $tokens->expiresAt(),
                'scopes' => $tokens->scope,
                'last_refreshed_at' => Clock::now(),
                // A successful reconnect clears any previous failure state.
                'invalidated_at' => null,
                'invalidated_reason' => null,
                'last_failure_at' => null,
                'failure_count' => 0,
            ];

            if ($byKey === null) {
                $created = $this->newModel();
                $created->forceFill($attributes)->save();

                return $created;
            }

            $byKey->forceFill($attributes)->save();

            return $byKey->refresh();
        });
    }

    public function persistRefreshedTokens(XeroConnection $connection, TokenResponse $tokens): XeroConnection
    {
        $connection->forceFill([
            'access_token' => $tokens->accessToken,
            // Xero rotates refresh tokens, but only returns a new one when it
            // issued one -- never overwrite a good token with an empty string.
            'refresh_token' => $tokens->hasRefreshToken()
                ? (string) $tokens->refreshToken
                : $connection->refresh_token,
            'expires_at' => $tokens->expiresAt(),
            'scopes' => $tokens->scope !== '' ? $tokens->scope : $connection->scopes,
            'last_refreshed_at' => Clock::now(),
            'last_failure_at' => null,
            'failure_count' => 0,
        ])->save();

        return $connection;
    }

    public function recordTransientFailure(XeroConnection $connection, string $reason): XeroConnection
    {
        // Counters only. Nothing about the stored tokens changes, and the
        // connection is emphatically NOT invalidated.
        $connection->forceFill([
            'last_failure_at' => Clock::now(),
            'failure_count' => $connection->failure_count + 1,
        ])->save();

        return $connection;
    }

    public function markInvalidated(XeroConnection $connection, string $reason): XeroConnection
    {
        $connection->forceFill([
            'invalidated_at' => Clock::now(),
            'invalidated_reason' => mb_substr($reason, 0, 191),
        ])->save();

        return $connection;
    }

    private function newModel(): XeroConnection
    {
        $class = (string) $this->config->get('model', XeroConnection::class);

        return new $class;
    }

    private function query()
    {
        return $this->newModel()->newQuery();
    }
}
