<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Peoplelogy\XeroBridge\Support\Clock;
use Peoplelogy\XeroBridge\Support\Scopes;

/**
 * One connected Xero organisation.
 *
 * SECURITY -- do not add an activity-log trait to this model.
 *
 * `access_token` and `refresh_token` use the `encrypted` cast, which protects
 * them at rest. It does NOT protect them once the model is hydrated: an
 * activity log, toArray(), a JSON response or Log::info($model) all emit the
 * DECRYPTED value. That is a live credential leak in the host project today
 * (pips/app/Models/Entities/XeroToken.php logs both tokens in clear into an
 * activity log retained for 365 days). $hidden below closes the serialisation
 * half of that hole, and there is a test asserting it stays closed.
 *
 * @property string $key
 * @property string $tenant_id
 * @property string|null $connection_id
 * @property string|null $tenant_name
 * @property string $tenant_type
 * @property string|null $auth_event_id
 * @property string $access_token
 * @property string $refresh_token
 * @property CarbonImmutable|null $expires_at
 * @property string|null $scopes
 * @property CarbonImmutable|null $last_refreshed_at
 * @property CarbonImmutable|null $invalidated_at
 * @property string|null $invalidated_reason
 * @property CarbonImmutable|null $last_failure_at
 * @property int $failure_count
 */
class XeroConnection extends Model
{
    protected $fillable = [
        'key',
        'tenant_id',
        'connection_id',
        'tenant_name',
        'tenant_type',
        'auth_event_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'scopes',
        'last_refreshed_at',
        'invalidated_at',
        'invalidated_reason',
        'last_failure_at',
        'failure_count',
    ];

    /**
     * Keeps both tokens out of toArray(), toJson(), API resources, Inertia
     * props and anything that stringifies the model.
     *
     * @var list<string>
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('xero-bridge.database.table', 'xero_connections'));

        if ($connection = config('xero-bridge.database.connection')) {
            $this->setConnection($connection);
        }
    }

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'immutable_datetime',
            'last_refreshed_at' => 'immutable_datetime',
            'invalidated_at' => 'immutable_datetime',
            'last_failure_at' => 'immutable_datetime',
            'failure_count' => 'integer',
        ];
    }

    /**
     * A null expiry counts as expired: we cannot prove the token is good, and
     * treating it as valid would send a doomed request to Xero.
     */
    public function isExpired(int $leeway = 0): bool
    {
        if ($this->expires_at === null) {
            return true;
        }

        return Clock::now()->addSeconds($leeway)->greaterThanOrEqualTo($this->expires_at);
    }

    public function expiresWithin(int $seconds): bool
    {
        return $this->isExpired($seconds);
    }

    /** True only after a terminal invalid_grant. Reversible by reconnecting. */
    public function isInvalidated(): bool
    {
        return $this->invalidated_at !== null;
    }

    public function isUsable(): bool
    {
        return ! $this->isInvalidated() && $this->refresh_token !== '';
    }

    /** tenant_name is nullable in Xero's API, so never interpolate it raw. */
    public function displayName(): string
    {
        return $this->tenant_name ?: $this->tenant_id;
    }

    /** @return list<string> */
    public function scopeList(): array
    {
        return Scopes::parse($this->scopes);
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopeList(), true);
    }

    public function scopeForKey(Builder $query, string $key): Builder
    {
        return $query->where('key', $key);
    }

    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('invalidated_at');
    }

    public function scopeExpiringWithin(Builder $query, int $seconds): Builder
    {
        return $query->where('expires_at', '<=', Clock::now()->addSeconds($seconds));
    }
}
