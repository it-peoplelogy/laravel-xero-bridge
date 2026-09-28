<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Peoplelogy\XeroBridge\Models\XeroConnection;

it('creates the table with the bare name when the connection has no prefix', function () {
    expect(Schema::hasTable('xero_connections'))->toBeTrue()
        ->and(Schema::hasColumns('xero_connections', [
            'key', 'tenant_id', 'connection_id', 'tenant_name', 'tenant_type',
            'auth_event_id', 'access_token', 'refresh_token', 'expires_at', 'scopes',
            'last_refreshed_at', 'invalidated_at', 'invalidated_reason',
            'last_failure_at', 'failure_count',
        ]))->toBeTrue();
});

it('stores the Xero connection id separately from the tenant id', function () {
    // DELETE /connections/{id} takes the CONNECTION id, not the tenantId.
    // Conflating the two makes per-organisation disconnect impossible.
    $connection = connection(['tenant_id' => 'tenant-x', 'connection_id' => 'conn-x']);

    expect($connection->tenant_id)->not->toBe($connection->connection_id);
});

it('encrypts both tokens at rest', function () {
    connection(['access_token' => 'plain-access', 'refresh_token' => 'plain-refresh']);

    $raw = DB::table('xero_connections')->first();

    expect($raw->access_token)->not->toBe('plain-access')
        ->and($raw->refresh_token)->not->toBe('plain-refresh')
        ->and(XeroConnection::first()->access_token)->toBe('plain-access')
        ->and(XeroConnection::first()->refresh_token)->toBe('plain-refresh');
});

it('never serialises either token', function () {
    // Regression guard. The encrypted cast protects data at rest but does
    // nothing once hydrated: toArray(), a JSON response, an Inertia prop or
    // Log::info($model) would all emit the DECRYPTED token. This is the exact
    // leak live in pips today via LogsActivity->logAll() on XeroToken.
    $connection = connection();

    $array = $connection->toArray();
    $json = $connection->toJson();

    expect($array)->not->toHaveKey('access_token')
        ->and($array)->not->toHaveKey('refresh_token')
        ->and($json)->not->toContain('access-token-1')
        ->and($json)->not->toContain('refresh-token-1');
});

it('keeps both tokens in the hidden list', function () {
    // Belt and braces: asserting the mechanism as well as the behaviour, so
    // that removing $hidden fails loudly even if toArray() is overridden.
    expect((new XeroConnection)->getHidden())
        ->toContain('access_token')
        ->toContain('refresh_token');
});

it('treats a null expiry as expired', function () {
    // We cannot prove the token is good, and assuming it is sends a doomed
    // request to Xero.
    expect(connection(['expires_at' => null])->isExpired())->toBeTrue();
});

it('applies the leeway when deciding staleness', function () {
    $connection = connection(['expires_at' => now()->addSeconds(30)]);

    expect($connection->isExpired())->toBeFalse()
        ->and($connection->isExpired(60))->toBeTrue()
        ->and($connection->expiresWithin(60))->toBeTrue()
        ->and($connection->expiresWithin(10))->toBeFalse();
});

it('counts an exactly-expired token as expired', function () {
    expect(connection(['expires_at' => now()])->isExpired())->toBeTrue();
});

it('falls back to the tenant id when Xero sent no tenant name', function () {
    // Xero documents tenantName as nullable; it is null for PRACTICEMANAGER
    // in Xero's own example.
    expect(connection(['tenant_name' => null, 'tenant_id' => 'tenant-9'])->displayName())
        ->toBe('tenant-9');
});

it('is usable until it is invalidated', function () {
    $connection = connection();
    expect($connection->isUsable())->toBeTrue()
        ->and($connection->isInvalidated())->toBeFalse();

    $connection->update(['invalidated_at' => now(), 'invalidated_reason' => 'invalid_grant']);

    expect($connection->isInvalidated())->toBeTrue()
        ->and($connection->isUsable())->toBeFalse();
});

it('reads the granted scopes', function () {
    $connection = connection(['scopes' => 'openid  offline_access   accounting.invoices']);

    expect($connection->scopeList())->toBe(['openid', 'offline_access', 'accounting.invoices'])
        ->and($connection->hasScope('accounting.invoices'))->toBeTrue()
        ->and($connection->hasScope('accounting.payments'))->toBeFalse();
});

it('scopes queries by key, tenant, usability and expiry', function () {
    connection(['key' => 'a', 'tenant_id' => 't-a', 'expires_at' => now()->addMinutes(50)]);
    connection(['key' => 'b', 'tenant_id' => 't-b', 'expires_at' => now()->addSeconds(20)]);
    connection(['key' => 'c', 'tenant_id' => 't-c', 'invalidated_at' => now()]);

    expect(XeroConnection::forKey('a')->count())->toBe(1)
        ->and(XeroConnection::forTenant('t-b')->count())->toBe(1)
        ->and(XeroConnection::usable()->count())->toBe(2)
        ->and(XeroConnection::expiringWithin(60)->pluck('key')->all())->toBe(['b']);
});

it('enforces uniqueness on both key and tenant id', function () {
    connection(['key' => 'dup', 'tenant_id' => 't-1']);

    expect(fn () => connection(['key' => 'dup', 'tenant_id' => 't-2']))
        ->toThrow(QueryException::class);

    expect(fn () => connection(['key' => 'other', 'tenant_id' => 't-1']))
        ->toThrow(QueryException::class);
});
