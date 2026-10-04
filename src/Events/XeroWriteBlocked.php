<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The write ledger refused a write because it has already been made, or is
 * being made right now.
 *
 * Dispatched immediately before XeroWriteAlreadyClaimedException is thrown.
 * The exception tells the caller to stop; this tells everyone else, from one
 * listener for invoices, contacts and payments alike, without parsing a
 * message. Read it by its shape:
 *
 *   pending false -- CONFIRMED. The write completed earlier and $xeroId is
 *   the id it produced. This is the ledger working: count it, do not page.
 *
 *   pending true, $claimedAt more than a few minutes old -- a STUCK claim. A
 *   worker sent something to Xero and never recorded the outcome, and every
 *   later attempt for this record will be refused until somebody checks Xero
 *   and resolves the row. This is the one to page on. A caller following the
 *   documented catch recipe returns quietly on a pending block, so without
 *   this event nobody hears about it.
 *
 *   pending true, $claimedAt seconds old -- two workers raced for the same
 *   write and the loser was stopped. Correct, and usually nothing to do.
 *
 * $claimedAt is null when the existing row could not be read; treat that as
 * pending of unknown age.
 *
 * A listener that throws is logged and ignored. It can never replace the
 * exception the caller is waiting to catch, because the documented way to
 * handle that exception -- read xeroId() and carry on -- would otherwise turn
 * into a failed job retrying into the same block.
 */
class XeroWriteBlocked
{
    use Dispatchable;

    public function __construct(
        public readonly string $connectionKey,
        /** 'invoice.create', 'contact.create' or 'payment.create'. */
        public readonly string $operation,
        /** The morph class of the record named with for(). */
        public readonly ?string $ownerType,
        public readonly ?string $ownerId,
        /** The reference passed to for() or reference(), if any. */
        public readonly ?string $reference,
        public readonly bool $pending,
        /** The id the earlier write produced; null while it is pending. */
        public readonly ?string $xeroId,
        /** When the existing claim was taken; null if it could not be read. */
        public readonly ?CarbonImmutable $claimedAt,
    ) {}
}
