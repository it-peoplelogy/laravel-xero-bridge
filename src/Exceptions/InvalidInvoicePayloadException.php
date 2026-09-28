<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Exceptions;

class InvalidInvoicePayloadException extends XeroBridgeException
{
    /**
     * Type is never defaulted. Guessing ACCREC would, on the one occasion
     * someone meant ACCPAY, post a bill as a sale -- a wrong-direction ledger
     * entry Finance has to journal back out.
     */
    public static function missingType(): self
    {
        return new self(
            'An invoice needs an explicit Type of ACCREC (a sale) or ACCPAY (a bill). '
            .'The package will not guess: posting a bill as a sale is a ledger error.'
        );
    }

    public static function missingContact(): self
    {
        return new self('An invoice needs a Contact. Supply ContactID, or a Name to create one.');
    }

    /**
     * On update, omitting LineItemID deletes and recreates the line, and
     * omitting a line entirely deletes it -- so "partial update" is a lie for
     * LineItems, and the surprise is destructive.
     */
    public static function unsafeLineItems(): self
    {
        return new self(
            'Updating an invoice with LineItems is destructive: Xero deletes and recreates any '
            .'line missing a LineItemID, and deletes any line you leave out entirely. Include '
            .'LineItemID on every line, or call replacingLineItems() to confirm you intend to '
            .'replace them all.'
        );
    }
}
