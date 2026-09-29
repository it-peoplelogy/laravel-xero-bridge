<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Peoplelogy\XeroBridge\Support\Clock;

/**
 * One webhook event this application has already dispatched.
 *
 * Exists so a replay inside Xero's 31-day window is a cheap no-op. Holds no
 * payload: the resource has almost certainly changed again since, so storing
 * the old body would only invite someone to act on stale data. The row is an
 * INDEX, not an archive.
 *
 * @property int $id
 * @property string $dedupe_key
 * @property string $tenant_id
 * @property string $resource_id
 * @property string $event_type
 * @property string $event_category
 * @property string $event_date_utc
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 * @property int $delivery_count
 */
class XeroWebhookEvent extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        // Set here rather than via $table, so a host that renames the table in
        // config is honoured even when the model is resolved before boot.
        $this->setTable(config('xero-bridge.webhooks.dedupe.table', 'xero_webhook_events'));

        if ($connection = config('xero-bridge.database.connection')) {
            $this->setConnection($connection);
        }
    }

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'delivery_count' => 'integer',
        ];
    }

    /**
     * Rows older than the retention window.
     *
     * The window is CLAMPED to a minimum of 32 days, because pruning inside
     * Xero's 31-day replay window would delete exactly the rows that make a
     * late replay detectable -- which is the only thing this table is for.
     */
    public function prunable(): Builder
    {
        $days = max(32, (int) config('xero-bridge.webhooks.dedupe.retain_days', 45));

        return static::query()->where('first_seen_at', '<', Clock::now()->subDays($days));
    }

    /** True once the same logical event has arrived more than once. */
    public function isReplay(): bool
    {
        return $this->delivery_count > 1;
    }

    public function scopeForResource(Builder $query, string $tenantId, string $resourceId): Builder
    {
        return $query->where('tenant_id', $tenantId)->where('resource_id', $resourceId);
    }
}
