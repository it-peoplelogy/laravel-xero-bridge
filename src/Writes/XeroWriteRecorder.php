<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Writes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Peoplelogy\XeroBridge\Exceptions\XeroWriteAlreadyClaimedException;
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
     */
    public function claim(
        string $connectionKey,
        string $operation,
        ?Model $owner = null,
        ?string $reference = null,
        ?string $idempotencyKey = null,
    ): WriteClaim {
        if (! $this->usable()) {
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
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                // A real storage failure. Recording must never take down the
                // write itself, so carry on unprotected and say so loudly.
                $this->logger->error(
                    'xero-bridge: could not claim a write, proceeding WITHOUT duplicate protection.',
                    ['operation' => $operation, 'exception' => $e->getMessage()],
                );

                return WriteClaim::none($operation);
            }

            // Somebody got there first. Which somebody decides what happens.
            throw $this->describeCollision($claimKey, $operation, $ownerType, $ownerId);
        } catch (Throwable $e) {
            $this->logger->error(
                'xero-bridge: could not claim a write, proceeding WITHOUT duplicate protection.',
                ['operation' => $operation, 'exception' => $e->getMessage()],
            );

            return WriteClaim::none($operation);
        }
    }

    /**
     * Xero accepted the write. Record what it produced.
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

    private function describeCollision(
        string $claimKey,
        string $operation,
        ?string $ownerType,
        ?string $ownerId,
    ): XeroWriteAlreadyClaimedException {
        $owner = $ownerType !== null ? $ownerType.'#'.$ownerId : null;

        try {
            $existing = XeroWriteRecord::query()->where('claim_key', $claimKey)->first();
        } catch (Throwable) {
            return XeroWriteAlreadyClaimedException::pending($operation, $owner);
        }

        if ($existing?->succeeded() && $existing->xero_id !== null) {
            return XeroWriteAlreadyClaimedException::confirmed($operation, $existing->xero_id, $owner);
        }

        return XeroWriteAlreadyClaimedException::pending($operation, $owner);
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

    private function isUniqueViolation(QueryException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());

        return $state === '23000' || $state === '23505';
    }

    private function usable(): bool
    {
        if (! $this->config->get('writes.enabled', false)) {
            return false;
        }

        // Guards against the surrounding-transaction mistake, which would make
        // the claim invisible to other workers and erasable by a rollback.
        if (DB::transactionLevel() > 0) {
            $this->logger->warning(
                'xero-bridge: a Xero write is inside a database transaction, so its claim cannot '
                .'protect against duplicates -- it is invisible to other workers until commit, and '
                .'a rollback would erase the record that the Xero write happened. Move the write '
                .'outside the transaction.',
            );
        }

        return $this->tables->has(
            $this->config->get('database.connection'),
            (string) $this->config->get('writes.table', 'xero_write_records'),
            'xero-bridge-migrations',
        );
    }
}
