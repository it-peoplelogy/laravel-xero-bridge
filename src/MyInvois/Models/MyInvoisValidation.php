<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\MyInvois\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Peoplelogy\XeroBridge\Support\Clock;

/**
 * One LHDN taxpayer TIN verdict.
 *
 * SECURITY. This row is ABOUT personal data without containing any: the TIN and
 * the identifier are present only as a keyed hash, and only the last four
 * characters of the TIN are readable. Do not add an activity-log trait, a
 * generic auditor, or anything else that snapshots attributes wholesale -- the
 * point of the design is that this table is not worth stealing, and a
 * well-meaning audit trail elsewhere would undo that.
 *
 * Do not add the raw TIN or identifier as columns. If you need them, they are
 * on the buyer record where they belong and where the host already governs
 * their retention.
 *
 * @property int $id
 * @property string $subject_hash
 * @property string $id_type
 * @property string|null $tin_last4
 * @property bool $verdict
 * @property int|null $http_status
 * @property string|null $correlation_id
 * @property string $environment
 * @property int $rules_version
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property CarbonImmutable $first_checked_at
 * @property CarbonImmutable $last_checked_at
 * @property int $check_count
 */
class MyInvoisValidation extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('myinvois.audit.table', 'myinvois_validations'));

        if ($connection = config('myinvois.database.connection')) {
            $this->setConnection($connection);
        }
    }

    protected function casts(): array
    {
        return [
            'verdict' => 'boolean',
            'http_status' => 'integer',
            'rules_version' => 'integer',
            'check_count' => 'integer',
            'first_checked_at' => 'immutable_datetime',
            'last_checked_at' => 'immutable_datetime',
        ];
    }

    /** The buyer record this verdict belongs to, if one was named. */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * True when this verdict predates the rules currently in force.
     *
     * A "valid" recorded before 1 August 2026 answered a weaker question --
     * LHDN did not yet check the TIN and the identifier as a pair -- so it is
     * not evidence of what it appears to say.
     */
    public function isStale(int $currentRulesVersion): bool
    {
        return $this->rules_version < $currentRulesVersion;
    }

    /**
     * Rows nobody has re-checked within the retention window.
     *
     * Data minimisation rather than volume control: the unique subject already
     * bounds how many rows can exist, so this exists to stop verdicts about
     * people you no longer deal with accumulating for ever.
     */
    public function prunable(): Builder
    {
        $days = max(1, (int) config('myinvois.audit.retain_days', 400));

        return static::query()->where('last_checked_at', '<', Clock::now()->subDays($days));
    }

    public function scopeForOwner(Builder $query, string $type, string $id): Builder
    {
        return $query->where('owner_type', $type)->where('owner_id', $id);
    }
}
