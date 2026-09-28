<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Exceptions;

/**
 * Terminal: Xero answered invalid_grant, or the connection is already marked
 * invalidated. Only a human going through the consent flow again can fix it.
 *
 * This is the ONLY failure mode that marks a connection invalidated. A
 * transient 5xx from identity.xero.com must never land here -- making that
 * mistake is what takes an integration offline for months without anyone
 * noticing.
 */
class XeroReauthorizationRequiredException extends XeroAuthenticationException
{
    public static function for(string $key, string $connectUrl, ?string $reason = null): self
    {
        $detail = ($reason !== null && $reason !== '') ? " ({$reason})" : '';

        return (new self(
            "The Xero connection [{$key}] must be authorised again{$detail}. "
            ."Re-authorise at {$connectUrl}."
        ))->withConnectionKey($key);
    }
}
