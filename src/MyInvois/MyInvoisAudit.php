<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\MyInvois;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Peoplelogy\XeroBridge\MyInvois\Models\MyInvoisValidation;
use Peoplelogy\XeroBridge\Support\Clock;
use Peoplelogy\XeroBridge\Support\TableGuard;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records TIN verdicts, without recording the identifiers they are about.
 *
 * THE HASH IS KEYED, AND THAT IS THE WHOLE DESIGN. A Malaysian business
 * registration number is twelve digits; an NRIC is twelve digits of which the
 * first six are a date of birth and the middle two a state code, leaving well
 * under 10^8 possibilities. A plain sha256 of either is exhausted on a laptop
 * in under a second, so "we hashed it" would be security theatre: anyone with
 * the table would have the identifiers.
 *
 * An HMAC keyed off a sub-key derived from the application key raises a
 * database-only compromise -- a stolen backup, a replica left open, a SELECT in
 * a support tool -- from free to "you also need the application key".
 *
 * The cost is stated rather than hidden: rotate the application key and every
 * stored hash becomes unmatchable. Existing rows are then orphans, and the next
 * validation of the same buyer writes a new row. That is the price of the
 * identifier being genuinely unrecoverable from this table.
 */
final class MyInvoisAudit
{
    /**
     * The rules the CURRENT verdicts were produced under.
     *
     * 2 = from 1 August 2026, when LHDN began validating the TIN and the
     * identifier as a PAIR rather than loosely. Bump this when LHDN changes
     * what a validation means again, and every earlier verdict becomes
     * identifiably stale instead of quietly wrong.
     */
    public const CURRENT_RULES_VERSION = 2;

    public function __construct(
        private readonly MyInvoisConfig $config,
        private readonly TableGuard $tables,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Record a verdict. Upserts, so re-checking a buyer counts rather than
     * accumulating a row per call.
     */
    public function record(
        string $tin,
        IdType $idType,
        string $idValue,
        bool $verdict,
        ?int $httpStatus = null,
        ?string $correlationId = null,
        ?Model $owner = null,
    ): void {
        if (! $this->usable()) {
            return;
        }

        $now = Clock::now();
        $hash = $this->subjectHash($tin, $idType, $idValue);

        try {
            $existing = MyInvoisValidation::query()->where('subject_hash', $hash)->first();

            if ($existing !== null) {
                // Through the QUERY builder, not $model->update(): an Eloquent
                // update fills the attribute first, and the 'integer' cast on
                // check_count turns the Expression into 1 -- so the counter
                // would silently reset on every re-check instead of advancing.
                MyInvoisValidation::query()
                    ->whereKey($existing->getKey())
                    ->update([
                        'verdict' => $verdict,
                        'http_status' => $httpStatus,
                        'correlation_id' => $correlationId,
                        'rules_version' => self::CURRENT_RULES_VERSION,
                        'last_checked_at' => $now,
                        'check_count' => DB::raw('check_count + 1'),
                    ]);

                return;
            }

            MyInvoisValidation::create([
                'subject_hash' => $hash,
                'id_type' => $idType->value,
                'tin_last4' => $this->last4($tin),
                'verdict' => $verdict,
                'http_status' => $httpStatus,
                'correlation_id' => $correlationId,
                'environment' => $this->config->environment(),
                'rules_version' => self::CURRENT_RULES_VERSION,
                'owner_type' => $owner?->getMorphClass(),
                'owner_id' => $owner !== null ? (string) $owner->getKey() : null,
                'first_checked_at' => $now,
                'last_checked_at' => $now,
                'check_count' => 1,
            ]);
        } catch (QueryException $e) {
            // Another process recorded the same subject between the read and
            // the insert. The constraint did its job; nothing to repair.
            if (! $this->isUniqueViolation($e)) {
                $this->warn($e);
            }
        } catch (Throwable $e) {
            // Recording must never take down the validation it was recording.
            $this->warn($e);
        }
    }

    /**
     * Forget every verdict about one subject.
     *
     * The erasure entry point. A host handling a PDPA erasure request needs to
     * remove the row for a specific person, and only the caller still holds the
     * identifiers needed to compute the hash -- this table deliberately cannot.
     */
    public function forgetSubject(string $tin, IdType $idType, string $idValue): int
    {
        if (! $this->usable()) {
            return 0;
        }

        try {
            return MyInvoisValidation::query()
                ->where('subject_hash', $this->subjectHash($tin, $idType, $idValue))
                ->delete();
        } catch (Throwable $e) {
            $this->warn($e);

            return 0;
        }
    }

    /**
     * Forget every verdict attached to one of the host's records.
     *
     * The usual hook: call it from the buyer model's `deleting` event, so
     * erasing a customer erases what was checked about them.
     */
    public function forgetOwner(Model $owner): int
    {
        if (! $this->usable()) {
            return 0;
        }

        try {
            return MyInvoisValidation::query()
                ->forOwner($owner->getMorphClass(), (string) $owner->getKey())
                ->delete();
        } catch (Throwable $e) {
            $this->warn($e);

            return 0;
        }
    }

    /**
     * HMAC-SHA256, keyed with a sub-key derived from the application key.
     *
     * The application key is never used directly as the HMAC key: it is hashed
     * with a fixed domain-separation string first, so a leak of this sub-key
     * does not hand over the key itself, and the same application key used for
     * a different purpose produces unrelated output.
     */
    public function subjectHash(string $tin, IdType $idType, string $idValue): string
    {
        $subject = implode('|', [
            $this->config->baseUrl(),
            trim($tin),
            $idType->value,
            trim($idValue),
        ]);

        return hash_hmac('sha256', $subject, $this->subjectKey());
    }

    private function subjectKey(): string
    {
        $appKey = (string) config('app.key');

        return hash('sha256', 'xero-bridge:myinvois-subject:v1|'.$appKey);
    }

    /** Recognisable to someone already looking at the record; useless to anyone else. */
    private function last4(string $tin): ?string
    {
        $tin = trim($tin);

        return $tin === '' ? null : substr($tin, -4);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());

        return $state === '23000' || $state === '23505';
    }

    private function warn(Throwable $e): void
    {
        $this->logger->warning(
            'xero-bridge: could not record a MyInvois verdict.',
            ['exception' => $e->getMessage()],
        );
    }

    private function usable(): bool
    {
        return (bool) $this->config->get('audit.enabled', false)
            && $this->tables->has(
                $this->config->get('database.connection'),
                (string) $this->config->get('audit.table', 'myinvois_validations'),
                'myinvois-migrations',
            );
    }
}
