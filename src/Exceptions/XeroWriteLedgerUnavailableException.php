<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Exceptions;

use Throwable;

/**
 * The write ledger could not record a claim, so the write was REFUSED and
 * nothing was sent to Xero.
 *
 * Thrown only with XERO_WRITES_STRICT on (writes.strict), and only for a write
 * that names an owner with for() -- the only kind the ledger can deduplicate.
 * Without strict mode the same failure is logged and the write goes out
 * unprotected, as it did before strict mode existed.
 *
 * It is the opposite of XeroWriteAlreadyClaimedException, and a queued job
 * must treat the two in opposite ways:
 *
 *   XeroWriteAlreadyClaimedException -- the ledger WORKED. This write has
 *   already been made, or is in flight. STOP: retrying into it either loops
 *   forever or asks for the very duplicate the ledger just prevented.
 *
 *   XeroWriteLedgerUnavailableException -- the ledger could not answer: its
 *   table is not migrated, the database is unreachable, or the insert failed
 *   for any reason other than a duplicate. Nothing reached Xero, so RETRYING
 *   IS SAFE, and is the right move once the table is migrated and the
 *   database is reachable. There is deliberately no retryAfter(): the package
 *   cannot know when that will be, so the job's own backoff applies.
 *
 * Both extend XeroBridgeException, so a catch for that class sees this one
 * too. A job that marks its record as failed on any XeroBridgeException should
 * single this class out as retryable. getPrevious() is the failure that
 * stopped the claim -- usually a QueryException.
 */
class XeroWriteLedgerUnavailableException extends XeroBridgeException
{
    /**
     * @param  string  $operation  'invoice.create', 'contact.create', ...
     * @param  string|null  $owner  the record named with for(), as 'App\Models\Order#42'
     * @param  Throwable  $cause  what stopped the claim; becomes getPrevious()
     */
    public static function forClaim(string $operation, ?string $owner, Throwable $cause): self
    {
        return new self(sprintf(
            'The write ledger could not record the claim for %s, so the write was REFUSED and '
            .'nothing was sent to Xero (XERO_WRITES_STRICT is on). It is safe to retry once the '
            .'ledger table is migrated and the database is reachable.',
            $owner !== null ? "{$operation} ({$owner})" : $operation,
        ), 0, $cause);
    }
}
