<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Writes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Peoplelogy\XeroBridge\Events\XeroWriteBlocked;
use Peoplelogy\XeroBridge\Exceptions\XeroWriteAlreadyClaimedException;
use Peoplelogy\XeroBridge\Exceptions\XeroWriteLedgerUnavailableException;
use Peoplelogy\XeroBridge\Models\XeroWriteRecord;
use Peoplelogy\XeroBridge\Support\Clock;
use Peoplelogy\XeroBridge\Support\TableGuard;
use Peoplelogy\XeroBridge\Support\XeroConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The claim protocol: the only class that writes the ledger.
 *
 * THE ORDER IS THE DESIGN. The row is INSERTED BEFORE the request leaves for
 * Xero, not after. That is the opposite of the webhook recorder, and both are
 * right for their own reason.
 *
 * Insert first, and the dangerous window stops being "Xero created it and we
 * failed to record" -- which would let the next run create a second one -- and
 * becomes "we sent something and never learned the outcome". That is what a
 * `pending` row means, and it BLOCKS at any age. Nothing re-sends on its own.
 *
 * So the failure mode is redirected, deliberately:
 *
 *      from  a duplicate invoice in a customer's ledger
 *            -- money, unrecoverable, found by the customer
 *
 *        to  one stuck row a human clears
 *            -- visible, recoverable, surfaced by xero-bridge:status
 *
 * Only a PROVEN non-creation releases a claim. A 400 from Xero means the
 * payload was rejected and nothing exists, so the row is deleted and the slot
 * is free for a corrected retry. A timeout, a 5xx, a 429 or a killed worker
 * all leave it pending, because none of them proves nothing was created.
 *
 * WHEN THE LEDGER ITSELF FAILS -- its table is missing, the database is
 * unreachable, an insert fails for any reason other than a duplicate -- the
 * default is to say so loudly and send the write unprotected, because
 * recording must never take down the write it records. With writes.strict on,
 * a write that names an owner is refused instead, before anything is sent
 * (XeroWriteLedgerUnavailableException). Only owned writes: an unowned one is
 * never deduplicated, so refusing it would add an outage and no protection.
 *
 * DO NOT WRAP A XERO WRITE IN A DATABASE TRANSACTION. If you do, the claim is
 * invisible to other workers until you commit, and rolling back after Xero
 * accepted the invoice erases the only record that it exists. The claim must
 * be committed before the HTTP call, which a surrounding transaction prevents.
 */
final class XeroWriteRecorder
{
    public function __construct(
        private readonly XeroConfig $config,
        private readonly TableGuard $tables,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Claim the right to make this write.
     *
     * @param  string  $operation  'invoice.create', 'contact.create', ...
     * @param  Model|null  $owner  the consumer's own record, when they named one
     *
     * @throws XeroWriteAlreadyClaimedException when this write already happened
     *                                          or is in flight
     * @throws XeroWriteLedgerUnavailableException in strict mode, when the claim
     *                                             for an owned write cannot be
     *                                             recorded; nothing was sent
     */
    public function claim(
        string $connectionKey,
        string $operation,
        ?Model $owner = null,
        ?string $reference = null,
        ?string $idempotencyKey = null,
    ): WriteClaim {
        if (! $this->enabled()) {
            return WriteClaim::none($operation);
        }

        $this->warnIfInsideTransaction();

        // Strict mode only ever refuses a write the ledger could have
        // protected, which means one that names an owner. Read with the
        // shipped default, so a config published or cached before the key
        // existed keeps today's behaviour.
        $strict = $owner !== null && (bool) $this->config->get('writes.strict', false);

        // Strict mode does not ask the TableGuard, and lets the insert decide.
        // The guard remembers a no-table answer for the life of the process,
        // and a failed check for a minute: through it, a table migrated under
        // a running worker would go on refusing every owned write until the
        // worker restarts, and one failed check would refuse them all for a
        // minute after the database came back. A missing table fails the
        // insert like any other storage failure, and is refused as one.
        if (! $strict && ! $this->tablePresent()) {
            return WriteClaim::none($operation);
        }

        $ownerType = $owner !== null ? $owner->getMorphClass() : null;
        $ownerId = $owner !== null ? (string) $owner->getKey() : null;

        $claimKey = $this->claimKey($connectionKey, $operation, $ownerType, $ownerId, $reference);

        try {
            $record = XeroWriteRecord::create([
                'claim_key' => $claimKey,
                'connection_key' => $connectionKey,
                'operation' => $operation,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'reference' => $reference,
                'status' => XeroWriteRecord::STATUS_PENDING,
                'idempotency_key' => $idempotencyKey,
                'claimed_at' => Clock::now(),
            ]);

            return new WriteClaim($record->id, $operation, $claimKey);
        } catch (UniqueConstraintViolationException) {
            // Somebody got there first. Which somebody decides what happens.
            //
            // The framework's own classification, not "SQLSTATE 23000": that
            // code also covers NOT NULL, foreign key and CHECK failures, which
            // used to surface here as a "pending" duplicate -- and the
            // documented catch recipe drops a pending block without a word.
            throw $this->collision(
                $connectionKey, $operation, $ownerType, $ownerId, $reference, $this->findClaim($claimKey),
            );
        } catch (Throwable $e) {
            // Not a duplicate. But an integrity failure of another kind --
            // NOT NULL, a foreign key, CHECK -- can be raised before the
            // unique index is ever consulted (SQLite checks NOT NULL first),
            // so it says nothing about whether this record's claim is already
            // held. If it is, refuse the write as the duplicate it would be;
            // carrying on would send it again.
            if ($owner !== null && $this->isIntegrityFailure($e)
                && ($held = $this->findClaim($claimKey)) !== null) {
                throw $this->collision($connectionKey, $operation, $ownerType, $ownerId, $reference, $held);
            }

            return $this->unrecorded($connectionKey, $operation, $this->ownerLabel($ownerType, $ownerId), $strict, $e);
        }
    }

    /**
     * Xero accepted the write. Record what it produced.
     *
     * Never throws, in strict mode too. By now the write EXISTS in Xero, so
     * throwing would report a created invoice as a failure, and the caller's
     * retry would be refused by the claim this failed to update anyway. The
     * pending row left behind is already the safe state.
     *
     * @param  array<string, mixed>  $resource  Xero's response body
     */
    public function succeed(WriteClaim $claim, array $resource): void
    {
        if (! $claim->isHeld()) {
            return;
        }

        try {
            XeroWriteRecord::query()->whereKey($claim->id)->update([
                'status' => XeroWriteRecord::STATUS_SUCCEEDED,
                'xero_id' => $this->identifierFrom($resource),
                'xero_number' => $this->stringFrom($resource, ['InvoiceNumber', 'CreditNoteNumber']),
                'xero_status' => $this->stringFrom($resource, ['Status']),
                'succeeded_at' => Clock::now(),
            ]);
        } catch (Throwable $e) {
            // The row stays pending, which is the safe state: it blocks any
            // further write for this owner until somebody looks. Loud, because
            // that is a stuck row somebody has to clear.
            $this->logger->error(
                'xero-bridge: a Xero write SUCCEEDED but could not be recorded. The claim is left '
                .'pending and will block further writes for this record until it is resolved.',
                ['claim_id' => $claim->id, 'exception' => $e->getMessage()],
            );
        }
    }

    /**
     * Xero refused the payload outright, so nothing was created.
     *
     * ONLY call this where non-creation is PROVEN -- a 400 rejecting the
     * request. A timeout or a 5xx proves nothing and must leave the claim
     * standing, because the write may well have landed.
     *
     * Never throws, in strict mode too: the caller is already handling Xero's
     * rejection, and a release that fails leaves the row pending, which blocks
     * -- the safe direction.
     */
    public function releaseProvenFailure(WriteClaim $claim): void
    {
        if (! $claim->isHeld()) {
            return;
        }

        try {
            XeroWriteRecord::query()->whereKey($claim->id)->delete();
        } catch (Throwable $e) {
            $this->logger->warning(
                'xero-bridge: could not release a claim after a rejected write.',
                ['claim_id' => $claim->id, 'exception' => $e->getMessage()],
            );
        }
    }

    /**
     * The Xero id already written for this owner, if any.
     *
     * The read side of the ledger: "has this order been invoiced yet?"
     *
     * Never throws, in strict mode too. It is a read: an unreadable ledger
     * answers "not known" with null, and strict mode does its work on the
     * write that usually follows, which is refused if the claim cannot be
     * recorded.
     */
    public function existingId(string $connectionKey, string $operation, Model $owner, ?string $reference = null): ?string
    {
        if (! $this->usable()) {
            return null;
        }

        try {
            $record = XeroWriteRecord::query()
                ->where('claim_key', $this->claimKey(
                    $connectionKey,
                    $operation,
                    $owner->getMorphClass(),
                    (string) $owner->getKey(),
                    $reference,
                ))
                ->first();

            return $record?->succeeded() ? $record->xero_id : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * An UNOWNED write gets a random key, so it is recorded but never
     * deduplicated. A nullable unique column would not do: every engine here
     * treats NULLs as distinct, so it would silently permit duplicates while
     * looking like it prevented them.
     */
    private function claimKey(
        string $connectionKey,
        string $operation,
        ?string $ownerType,
        ?string $ownerId,
        ?string $reference,
    ): string {
        if ($ownerType === null || $ownerId === null) {
            return hash('sha256', 'unowned|'.Str::uuid()->toString());
        }

        return hash('sha256', implode('|', [
            $connectionKey,
            $operation,
            $ownerType,
            $ownerId,
            $reference ?? '',
        ]));
    }

    /**
     * The row holding this claim, or null when there is none or it cannot be
     * read. Both mean the same to a caller: nothing proves a write completed.
     */
    private function findClaim(string $claimKey): ?XeroWriteRecord
    {
        try {
            return XeroWriteRecord::query()->where('claim_key', $claimKey)->first();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Somebody holds this claim already: announce it, and hand back the
     * exception that stops the caller.
     *
     * A row that cannot be found or read is treated as in flight -- the state
     * that never re-sends -- with no claimedAt.
     */
    private function collision(
        string $connectionKey,
        string $operation,
        ?string $ownerType,
        ?string $ownerId,
        ?string $reference,
        ?XeroWriteRecord $existing,
    ): XeroWriteAlreadyClaimedException {
        $owner = $this->ownerLabel($ownerType, $ownerId);

        $exception = $existing?->succeeded() && $existing->xero_id !== null
            ? XeroWriteAlreadyClaimedException::confirmed($operation, $existing->xero_id, $owner)
            : XeroWriteAlreadyClaimedException::pending($operation, $owner);

        // Built AND dispatched inside the try, because nothing about the event
        // may replace the exception: it is the duplicate protection, and the
        // documented way to handle it -- read xeroId() and carry on -- only
        // works if it arrives.
        //
        // Constructed with named arguments and handed to event(), rather than
        // XeroWriteBlocked::dispatch(...): on Laravel 11 and early 12,
        // dispatch() declares no parameters and reads func_get_args(), so a
        // named argument throws there.
        try {
            event(new XeroWriteBlocked(
                connectionKey: $connectionKey,
                operation: $operation,
                ownerType: $ownerType,
                ownerId: $ownerId,
                reference: $reference,
                pending: $exception->isPending(),
                xeroId: $exception->xeroId(),
                claimedAt: $existing?->claimed_at,
            ));
        } catch (Throwable $e) {
            $this->logger->warning(
                'xero-bridge: a XeroWriteBlocked listener failed. The write is still refused as a duplicate.',
                ['operation' => $operation, 'exception' => $e->getMessage()],
            );
        }

        return $exception->withConnectionKey($connectionKey);
    }

    /**
     * The claim could not be recorded, and nothing says the write already
     * happened.
     *
     * The one place both modes decide, so the default path stays exactly what
     * it always was: say so loudly and carry on unprotected, because recording
     * must never take down the write itself. Strict mode refuses instead --
     * before the request, so nothing reached Xero and a retry is safe.
     *
     * @throws XeroWriteLedgerUnavailableException in strict mode
     */
    private function unrecorded(
        string $connectionKey,
        string $operation,
        ?string $owner,
        bool $strict,
        Throwable $e,
    ): WriteClaim {
        if ($strict) {
            throw XeroWriteLedgerUnavailableException::forClaim($operation, $owner, $e)
                ->withConnectionKey($connectionKey);
        }

        $this->logger->error(
            'xero-bridge: could not claim a write, proceeding WITHOUT duplicate protection.',
            ['operation' => $operation, 'exception' => $e->getMessage()],
        );

        return WriteClaim::none($operation);
    }

    /**
     * SQLSTATE class 23, "integrity constraint violation": the database was
     * reachable and refused this row, so asking it who holds the claim is
     * cheap and meaningful. Not consulted for a connection failure, where the
     * question would only wait for a second timeout.
     */
    private function isIntegrityFailure(Throwable $e): bool
    {
        return $e instanceof QueryException
            && str_starts_with((string) ($e->errorInfo[0] ?? $e->getCode()), '23');
    }

    /** How an owner is named in a message: 'App\Models\Order#42'. */
    private function ownerLabel(?string $ownerType, ?string $ownerId): ?string
    {
        return $ownerType !== null ? $ownerType.'#'.$ownerId : null;
    }

    /** @param array<string, mixed> $resource */
    private function identifierFrom(array $resource): ?string
    {
        foreach (['InvoiceID', 'ContactID', 'PaymentID', 'CreditNoteID'] as $key) {
            if (isset($resource[$key]) && is_scalar($resource[$key])) {
                return (string) $resource[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $resource
     * @param  list<string>  $keys
     */
    private function stringFrom(array $resource, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($resource[$key]) && is_scalar($resource[$key])) {
                return (string) $resource[$key];
            }
        }

        return null;
    }

    private function enabled(): bool
    {
        return (bool) $this->config->get('writes.enabled', false);
    }

    private function tablePresent(): bool
    {
        return $this->tables->has(
            $this->config->get('database.connection'),
            (string) $this->config->get('writes.table', 'xero_write_records'),
            'xero-bridge-migrations',
        );
    }

    /** The read path's gate: on, and the table is there. */
    private function usable(): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $this->warnIfInsideTransaction();

        return $this->tablePresent();
    }

    /**
     * Guards against the surrounding-transaction mistake, which would make
     * the claim invisible to other workers and erasable by a rollback.
     *
     * Reads the LEDGER's connection, the one the claim is written through. A
     * transaction open only on another connection cannot hide the claim, and
     * one on the ledger's connection can, wherever XERO_DB_CONNECTION points.
     *
     * A warning in every mode, strict included: a consumer's own test suite
     * runs inside a RefreshDatabase transaction and must keep working.
     */
    private function warnIfInsideTransaction(): void
    {
        try {
            $level = DB::connection($this->config->get('database.connection'))->transactionLevel();
        } catch (Throwable) {
            // A connection that cannot even be resolved is for the claim to
            // report, through the same path as any other storage failure.
            return;
        }

        if ($level > 0) {
            $this->logger->warning(
                'xero-bridge: a Xero write is inside a database transaction, so its claim cannot '
                .'protect against duplicates -- it is invisible to other workers until commit, and '
                .'a rollback would erase the record that the Xero write happened. Move the write '
                .'outside the transaction.',
            );
        }
    }
}
