<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\MyInvois;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Peoplelogy\XeroBridge\MyInvois\Models\MyInvoisValidationHistory;
use Peoplelogy\XeroBridge\OAuth\Actor;
use Peoplelogy\XeroBridge\Support\TableGuard;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records every TIN validation attempt, one row each, never updated.
 *
 * The verdict record (MyInvoisAudit) answers "what does LHDN say about this
 * pair?" and so keeps one row per pair. This answers "who checked what, when,
 * and what happened each time?", so it keeps them all -- the checks LHDN never
 * answered included, because "we tried and the credentials were refused" is
 * exactly what somebody asks about later.
 *
 * WHAT A ROW KEEPS OF THE NUMBERS IS FIXED BY THE ID TYPE. A BRN check keeps the
 * full TIN and BRN: a company's SSM registration is public, and it is what the
 * host matches against its own customer record. An NRIC, PASSPORT or ARMY check
 * keeps neither the TIN nor the ID number -- not even the last four characters
 * -- because both identify a person and are personal data under the PDPA. Its
 * row is still written, and the stored error message has those numbers
 * replaced as well, since an HTTP client exception quotes the URL it failed on:
 * the TIN in its path, the ID number in its query string.
 *
 * Every row carries the verdict record's keyed subject hash, so "every check of
 * this pair" is answerable for a caller who holds the numbers, and erasure works
 * the same way.
 */
final class MyInvoisHistory
{
    /** A stored error is a message for a human, not a dump. */
    private const ERROR_MAX_LENGTH = 1000;

    /** What a number the row does not keep becomes inside an error message. */
    private const WITHHELD = '[redacted]';

    public function __construct(
        private readonly MyInvoisConfig $config,
        private readonly MyInvoisAudit $audit,
        private readonly TableGuard $tables,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Record one attempt. Never throws: recording must never take down the
     * validation it was recording.
     *
     * @internal Called by MyInvoisClient. Hosts read the model.
     */
    public function record(
        string $tin,
        IdType $idType,
        string $idValue,
        string $outcome,
        ?int $httpStatus = null,
        ?string $correlationId = null,
        ?Throwable $error = null,
        bool $fromCache = false,
        ?int $durationMs = null,
        ?Model $owner = null,
    ): void {
        if (! $this->usable()) {
            return;
        }

        try {
            $tin = trim($tin);
            $idValue = trim($idValue);
            $keeps = self::keepsNumbers($idType);
            $actor = $this->actor();

            MyInvoisValidationHistory::create([
                'subject_hash' => $this->subjectHash($tin, $idType, $idValue),
                'id_type' => $idType->value,
                'tin' => $keeps ? $tin : null,
                'tin_last4' => ($keeps && $tin !== '') ? substr($tin, -4) : null,
                'id_value' => $keeps ? $idValue : null,
                'outcome' => $outcome,
                'from_cache' => $fromCache,
                'http_status' => $httpStatus,
                'error_code' => $error !== null ? $this->errorCode($error) : null,
                'error' => $error !== null ? $this->errorMessage($error, $keeps, $tin, $idValue) : null,
                'correlation_id' => $correlationId,
                'environment' => $this->environment(),
                'rules_version' => MyInvoisAudit::CURRENT_RULES_VERSION,
                'duration_ms' => $durationMs,
                'actor_type' => $actor?->type,
                'actor_id' => $actor !== null ? (string) $actor->id : null,
                'actor_guard' => $actor?->guard,
                'owner_type' => $owner?->getMorphClass(),
                'owner_id' => $owner !== null ? (string) $owner->getKey() : null,
            ]);
        } catch (Throwable $e) {
            $this->warn($e);
        }
    }

    /**
     * Does a row keep the TIN and the ID number for this id type? Only for a
     * company's BRN; NRIC, PASSPORT and ARMY identify a person.
     */
    public static function keepsNumbers(IdType $idType): bool
    {
        return $idType === IdType::BRN;
    }

    /**
     * The keyed hash a row is filed under -- the verdict record's own, so the
     * two tables join on it. Use it to read one pair's history:
     *
     *     MyInvoisValidationHistory::forSubject(
     *         app(MyInvoisHistory::class)->subjectHash($tin, IdType::BRN, $brn)
     *     )->latest('id')->get();
     */
    public function subjectHash(string $tin, IdType $idType, string $idValue): string
    {
        try {
            return $this->audit->subjectHash($tin, $idType, $idValue);
        } catch (Throwable) {
            // Only an unreadable MYINVOIS_ENVIRONMENT gets here -- usually the
            // very failure being recorded. Still keyed, under a scope no
            // readable environment can produce.
            return $this->audit->keyedHash(
                'environment:'.strtolower(trim((string) ($this->config->get('environment') ?? ''))),
                $tin,
                $idType,
                $idValue,
            );
        }
    }

    /**
     * Forget every attempt about one subject: the PDPA erasure entry point,
     * matching MyInvoisAudit::forgetSubject().
     */
    public function forgetSubject(string $tin, IdType $idType, string $idValue): int
    {
        if (! $this->usable()) {
            return 0;
        }

        try {
            return MyInvoisValidationHistory::query()
                ->forSubject($this->subjectHash(trim($tin), $idType, trim($idValue)))
                ->delete();
        } catch (Throwable $e) {
            $this->warn($e);

            return 0;
        }
    }

    /**
     * Forget every attempt attached to one of the host's records -- call it
     * from the buyer model's `deleting` event, next to
     * MyInvoisAudit::forgetOwner().
     */
    public function forgetOwner(Model $owner): int
    {
        if (! $this->usable()) {
            return 0;
        }

        try {
            return MyInvoisValidationHistory::query()
                ->forOwner($owner->getMorphClass(), (string) $owner->getKey())
                ->delete();
        } catch (Throwable $e) {
            $this->warn($e);

            return 0;
        }
    }

    /**
     * LHDN's own code when it sent one (invalid_client, BadArgument...),
     * otherwise the kind of failure: a connection that never got an answer is
     * not the same problem as a setting left blank.
     */
    private function errorCode(Throwable $error): ?string
    {
        if ($error instanceof MyInvoisException) {
            $code = $error->errorCode();

            if ($code !== null && $code !== '') {
                return substr($code, 0, 64);
            }

            return $error->isConfigurationProblem() ? 'configuration' : null;
        }

        return substr(class_basename($error), 0, 64);
    }

    /**
     * The failure in words, with every number the row does not keep replaced.
     *
     * Only values of five characters or more are replaced: real TINs, NRICs and
     * passport numbers are all longer, and replacing a one- or two-character
     * string throughout a message would mangle it ("HTTP 429") without
     * protecting anything.
     */
    private function errorMessage(Throwable $error, bool $keeps, string $tin, string $idValue): string
    {
        $message = $error->getMessage();

        if (! $keeps) {
            foreach ([$tin, $idValue] as $value) {
                if (strlen($value) < 5) {
                    continue;
                }

                $message = str_replace(
                    array_unique([$value, rawurlencode($value), urlencode($value)]),
                    self::WITHHELD,
                    $message,
                );
            }
        }

        return mb_substr($message, 0, self::ERROR_MAX_LENGTH);
    }

    /** sandbox or production, or what was set when it was neither. */
    private function environment(): string
    {
        try {
            return $this->config->environment();
        } catch (Throwable) {
            $raw = strtolower(trim((string) ($this->config->get('environment') ?? '')));

            return $raw === '' ? 'unset' : substr($raw, 0, 16);
        }
    }

    /**
     * The signed-in user, on the default guard -- the one the `auth` middleware
     * switched to for this request, as XeroConnected's actor is resolved.
     *
     * Worth recording, never worth failing over: a guard or user provider that
     * throws, a queued job or the scheduler all give null.
     */
    private function actor(): ?Actor
    {
        try {
            $auth = app('auth');
            $user = $auth->guard()->user();

            if (! $user instanceof Authenticatable) {
                return null;
            }

            return Actor::from($user, $auth->getDefaultDriver());
        } catch (Throwable) {
            return null;
        }
    }

    private function warn(Throwable $e): void
    {
        $this->logger->warning(
            'xero-bridge: could not record a MyInvois check in the history.',
            ['exception' => $e->getMessage()],
        );
    }

    private function usable(): bool
    {
        return (bool) $this->config->get('history.enabled', false)
            && $this->tables->has(
                $this->config->get('database.connection'),
                (string) $this->config->get('history.table', 'myinvois_validation_history'),
                'myinvois-migrations',
            );
    }
}
