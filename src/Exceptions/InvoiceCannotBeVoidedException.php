<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Exceptions;

/**
 * VOIDED is reachable from AUTHORISED, but only while no payment is applied.
 *
 * Xero's own error for this is opaque, so the package names the blocking
 * payment and the remedy -- which is why Payments::delete() has to exist.
 */
class InvoiceCannotBeVoidedException extends XeroBridgeException
{
    /** @param list<string> $paymentIds */
    public static function hasPayments(string $identifier, array $paymentIds): self
    {
        $ids = implode(', ', $paymentIds);

        return new self(
            "Invoice [{$identifier}] has payments applied ({$ids}) and cannot be voided. "
            .'Remove them first with payments()->delete($paymentId), then void the invoice.'
        );
    }
}
