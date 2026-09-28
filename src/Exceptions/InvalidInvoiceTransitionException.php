<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Exceptions;

use Peoplelogy\XeroBridge\Support\InvoiceTransitions;

/**
 * An illegal status change, caught locally so the message names the current
 * status and what is actually possible from it.
 */
class InvalidInvoiceTransitionException extends XeroBridgeException
{
    public static function make(string $identifier, string $from, string $to): self
    {
        $allowed = InvoiceTransitions::allowedFrom($from);
        $options = $allowed === [] ? 'nothing' : implode(', ', $allowed);

        return new self(
            "Invoice [{$identifier}] is {$from} and cannot become {$to}. "
            ."From {$from}, Xero allows: {$options}."
        );
    }
}
