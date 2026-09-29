<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Peoplelogy\XeroBridge\Support\Clock;

/**
 * One write into Xero: claimed before it is sent, confirmed after it lands.
 *
 * @property int $id
 * @property string $claim_key
 * @property string $connection_key
 * @property string $operation
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string|null $reference
 * @property string $status
 * @property string|null $xero_id
 * @property string|null $xero_number
 * @property string|null $xero_status
 * @property string|null $idempotency_key
 * @property CarbonImmutable $claimed_at
 * @property CarbonImmutable|null $succeeded_at
 */
class XeroWriteRecord extends Model
{
    use MassPrunable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCEEDED = 'succeeded';

    public $timestamps = false;

    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('xero-bridge.writes.table', 'xero_write_records'));

        if ($connection = config('xero-bridge.database.connection')) {
            $this->setConnection($connection);
        }
    }

    protected function casts(): array
    {
        return [
            'claimed_at' => 'immutable_datetime',
            'succeeded_at' => 'immutable_datetime',
        ];
    }

    /** The consuming application's own record, whatever it is. */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function succeeded(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }

    /**
     * Only SUCCEEDED rows are ever pruned.
     *
     * A pending row is an unresolved question -- did that invoice reach Xero?
     * -- and deleting it would free the claim, allowing exactly the duplicate
     * write the ledger exists to prevent. They accumulate visibly instead,
     * which is the intended pressure to go and look at them.
     */
    public function prunable(): Builder
    {
        $days = max(1, (int) config('xero-bridge.writes.retain_days', 90));

        return static::query()
            ->where('status', self::STATUS_SUCCEEDED)
            ->where('claimed_at', '<', Clock::now()->subDays($days));
    }

    public function scopeForOwner(Builder $query, string $type, string $id): Builder
    {
        return $query->where('owner_type', $type)->where('owner_id', $id);
    }

    public function scopeStuck(Builder $query, int $minutes = 60): Builder
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where('claimed_at', '<', Clock::now()->subMinutes($minutes));
    }
}
