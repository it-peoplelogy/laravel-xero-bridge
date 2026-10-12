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
 * One LHDN taxpayer TIN validation attempt. Written once, never updated.
 *
 * Where MyInvoisValidation keeps the latest verdict per TIN/ID pair, this keeps
 * every attempt -- including the ones LHDN never answered -- so "who checked
 * this, when, and what happened each time" has an answer.
 *
 * SECURITY. `tin` and `id_value` are filled for a BRN check only -- a company's
 * public SSM registration. For NRIC, PASSPORT and ARMY they, and `tin_last4`,
 * are null: a person's numbers are not kept, not even in part. Do not add a
 * column, an activity-log trait or anything else that would put them back.
 *
 * @property int $id
 * @property string $subject_hash
 * @property string $id_type
 * @property string|null $tin
 * @property string|null $tin_last4
 * @property string|null $id_value
 * @property string $outcome
 * @property bool $from_cache
 * @property int|null $http_status
 * @property string|null $error_code
 * @property string|null $error
 * @property string|null $correlation_id
 * @property string $environment
 * @property int $rules_version
 * @property int|null $duration_ms
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string|null $actor_guard
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property CarbonImmutable $created_at
 */
class MyInvoisValidationHistory extends Model
{
    use MassPrunable;

    /** HASiL holds the pair. */
    public const MATCH = 'match';

    /** HASiL does not hold the pair (HTTP 404) -- an answer, not a failure. */
    public const NO_MATCH = 'no_match';

    /** No answer: a setting, the credentials, a rate limit or LHDN itself. */
    public const ERROR = 'error';

    public $timestamps = false;

    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('myinvois.history.table', 'myinvois_validation_history'));

        if ($connection = config('myinvois.database.connection')) {
            $this->setConnection($connection);
        }
    }

    protected function casts(): array
    {
        return [
            'from_cache' => 'boolean',
            'http_status' => 'integer',
            'rules_version' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** The record this check was about, if the caller named one with MyInvois::for(). */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /** The signed-in user who ran the check, if there was one. */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Rows older than the retention window, counted from the check itself:
     * unlike a verdict, an attempt is never re-checked.
     */
    public function prunable(): Builder
    {
        $days = max(1, (int) config('myinvois.history.retain_days', 400));

        return static::query()->where('created_at', '<', Clock::now()->subDays($days));
    }

    /**
     * Every attempt about one TIN/ID pair, by the keyed hash
     * MyInvoisHistory::subjectHash() (the same one the verdict record uses) --
     * the way to find a person's checks, whose numbers the rows do not hold.
     */
    public function scopeForSubject(Builder $query, string $subjectHash): Builder
    {
        return $query->where('subject_hash', $subjectHash);
    }

    public function scopeForOwner(Builder $query, string $type, string $id): Builder
    {
        return $query->where('owner_type', $type)->where('owner_id', $id);
    }

    public function scopeMatched(Builder $query): Builder
    {
        return $query->where('outcome', self::MATCH);
    }

    public function scopeUnmatched(Builder $query): Builder
    {
        return $query->where('outcome', self::NO_MATCH);
    }

    /** Attempts LHDN never answered. */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('outcome', self::ERROR);
    }
}
