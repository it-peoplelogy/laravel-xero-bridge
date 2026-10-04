<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Exceptions;

/**
 * This write has already been made, or is being made right now.
 *
 * Thrown by the write ledger when a claim collides with an existing row. It
 * extends XeroBridgeException so an application's existing catch block sees
 * it, but it is emphatically NOT a failure: it is the package refusing to
 * create a second invoice for a record that already has one.
 *
 * It deliberately has NO retryAfter(). A queued job must STOP on this, not
 * release itself and come back -- retrying into a held claim is an infinite
 * loop, and retrying into a CONFIRMED one would be asking to duplicate the
 * very write the ledger just prevented.
 *
 * Two shapes, distinguished by xeroId():
 *
 *   CONFIRMED -- the write completed earlier and xeroId() is the id it
 *   produced. Read it and carry on; that is the "make the job a no-op once it
 *   is set" the docs have always asked consumers to implement themselves.
 *
 *   PENDING -- another worker sent something to Xero and never recorded the
 *   outcome. xeroId() is null. Nothing may re-send: the safe move is to leave
 *   it and let a human look, which is why this state blocks at any age rather
 *   than expiring.
 *
 * connectionKey() names the connection the write was for, and the
 * XeroWriteBlocked event is dispatched just before this is thrown -- with the
 * owner and, for a pending block, when the claim was taken -- so one listener
 * can tell a stuck claim (page on it) from two workers racing (nothing to do).
 *
 * Not to be confused with XeroWriteLedgerUnavailableException, which means the
 * ledger could NOT answer, nothing was sent, and retrying IS safe.
 */
class XeroWriteAlreadyClaimedException extends XeroBridgeException
{
    private ?string $xeroId = null;

    private bool $pending = false;

    public static function confirmed(string $operation, string $xeroId, ?string $owner): self
    {
        $exception = new self(sprintf(
            'A %s has already been created in Xero for %s (%s). Nothing was sent. Read the id '
            .'rather than writing again -- this is the duplicate protection working.',
            $operation,
            $owner ?? 'this claim',
            $xeroId,
        ));

        $exception->xeroId = $xeroId;

        return $exception;
    }

    public static function pending(string $operation, ?string $owner): self
    {
        $exception = new self(sprintf(
            'A %s for %s is already in flight, or a worker sent one to Xero and never recorded the '
            .'outcome. Nothing was sent, and nothing will re-send on its own: re-sending could '
            .'duplicate a record that already exists in Xero. Check Xero, then resolve the pending '
            .'row by hand.',
            $operation,
            $owner ?? 'this claim',
        ));

        $exception->pending = true;

        return $exception;
    }

    /** The Xero id of the record that already exists, when it is known. */
    public function xeroId(): ?string
    {
        return $this->xeroId;
    }

    /** True when the earlier attempt's outcome was never recorded. */
    public function isPending(): bool
    {
        return $this->pending;
    }
}
