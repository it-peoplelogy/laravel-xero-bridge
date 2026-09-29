<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Peoplelogy\XeroBridge\Support\Clock;

/**
 * One HTTP call to Xero or LHDN, request and response, already redacted.
 *
 * THIS MODEL IS THE SUPPORTED INTERFACE. The package ships no viewer -- a
 * consuming project renders this on its own dashboard, under its own roles.
 * Read it through here and through the scopes below rather than by writing SQL
 * against the table, so the columns underneath can change without breaking you.
 *
 * What is NOT here is as deliberate as what is. No bank account number, no
 * bearer token, no client secret, and the Malaysian TIN only as its last four
 * characters. Support\RedactionPolicy is where that is decided and the reasons
 * are written down beside each entry.
 *
 * @property int $id
 * @property string $channel
 * @property string|null $connection_key
 * @property string|null $tenant_id
 * @property string $logical_call_id
 * @property int $attempt
 * @property string $method
 * @property string $url
 * @property array<string, mixed>|null $request_headers
 * @property array<string, mixed>|null $request_body
 * @property int|null $status
 * @property array<string, mixed>|null $response_headers
 * @property array<string, mixed>|null $response_body
 * @property string|null $content_type
 * @property int|null $response_bytes
 * @property int|null $duration_ms
 * @property string|null $error
 * @property array<int, string>|null $redacted_keys
 * @property string|null $idempotency_key
 * @property string|null $correlation_id
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property CarbonImmutable $created_at
 */
class XeroApiCall extends Model
{
    use MassPrunable;

    /**
     * Only created_at. A captured call is a fact about a moment; there is no
     * such thing as updating one, and a second timestamp column on the highest
     * volume table in the package would be pure cost.
     */
    public const UPDATED_AT = null;

    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        // Set here rather than via $table, so a host that renames the table in
        // config is honoured even when the model is resolved before boot.
        $this->setTable(config('xero-bridge.capture.table', 'xero_api_calls'));

        if ($connection = config('xero-bridge.database.connection')) {
            $this->setConnection($connection);
        }
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'status' => 'integer',
            'response_bytes' => 'integer',
            'duration_ms' => 'integer',
            'request_headers' => 'array',
            'request_body' => 'array',
            'response_headers' => 'array',
            'response_body' => 'array',
            'redacted_keys' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * Rows older than the retention window.
     *
     * Unlike the write ledger, every row here is prunable at its age. Nothing
     * in this table protects against anything -- it is a record of what
     * happened, so keeping it past the window it is useful for is a liability
     * rather than a safeguard.
     */
    public function prunable(): Builder
    {
        $days = max(1, (int) config('xero-bridge.capture.retain_days', 90));

        return static::query()->where('created_at', '<', Clock::now()->subDays($days));
    }

    /**
     * The calls made for one of the consuming project's own records.
     *
     * This is the join a dashboard is built on: $order->xeroCalls() in the
     * host, or XeroApiCall::forOwner($order)->latest()->get() here.
     */
    public function scopeForOwner(Builder $query, Model $owner): Builder
    {
        return $query
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', (string) $owner->getKey());
    }

    /** Everything that went wrong: a non-2xx, or a call that never answered. */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where(
            fn (Builder $q) => $q->whereNull('status')->orWhere('status', '>=', 400),
        );
    }

    /** Every wire attempt belonging to one logical call, oldest first. */
    public function scopeLogicalCall(Builder $query, string $id): Builder
    {
        return $query->where('logical_call_id', $id)->orderBy('attempt');
    }

    public function scopeChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    /** True when the response body was dropped rather than never present. */
    public function bodyWasDropped(): bool
    {
        return $this->response_body === null
            && $this->response_bytes !== null
            && $this->response_bytes > 0;
    }
}
