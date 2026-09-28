<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Exceptions;

/**
 * An invoice payload carried a ContactID alongside other contact fields.
 *
 * Xero's documented behaviour: "Only send ContactID in the Contact element.
 * If you send other contact details, they update the contact record itself,
 * and any ContactPersons not included in the request are deleted."
 *
 * So creating an invoice would silently rewrite the customer record and
 * delete their contact people. It is irreversible and nobody notices for
 * weeks, which is why this is refused by default rather than merely
 * documented.
 */
class UnsafeContactPayloadException extends XeroBridgeException
{
    /** @param list<string> $extraFields */
    public static function make(array $extraFields): self
    {
        $fields = implode(', ', $extraFields);

        return new self(
            "The invoice Contact block carries a ContactID plus [{$fields}]. Xero would apply "
            .'those fields to the contact record itself and DELETE any ContactPersons not '
            .'included in the request. Send only ContactID, or call withContactMutation() to '
            .'confirm you intend to update the contact.'
        );
    }
}
