<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Contracts;

use Illuminate\Support\Collection;
use Peoplelogy\XeroBridge\Models\XeroConnection;
use Peoplelogy\XeroBridge\OAuth\TenantInfo;
use Peoplelogy\XeroBridge\OAuth\TokenResponse;

/**
 * The persistence seam. Bound to EloquentConnectionRepository by default; a
 * host with unusual storage requirements can swap it without touching the
 * rest of the package.
 */
interface ConnectionRepository
{
    public function findByKey(string $key): ?XeroConnection;

    public function findByTenantId(string $tenantId): ?XeroConnection;

    /** @return Collection<int, XeroConnection> */
    public function all(): Collection;

    /**
     * Store the result of a completed consent flow, resolving any key or
     * tenant conflict according to the configured policy.
     */
    public function upsert(string $key, TenantInfo $tenant, TokenResponse $tokens): XeroConnection;

    /**
     * Persist rotated tokens ONLY. Deliberately narrow: a refresh must never
     * be able to mutate tenant identity.
     */
    public function persistRefreshedTokens(XeroConnection $connection, TokenResponse $tokens): XeroConnection;

    /** Record a transient failure. Never destructive. */
    public function recordTransientFailure(XeroConnection $connection, string $reason): XeroConnection;

    /** Mark terminally dead. Reversible by reconnecting; never deletes. */
    public function markInvalidated(XeroConnection $connection, string $reason): XeroConnection;
}
