<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Exceptions;

/**
 * The organisation just authorised is already stored under a different
 * connection key, and on_tenant_conflict is 'error' (the default).
 *
 * Silently re-keying would break every caller that already references the old
 * key, so the safe default is to refuse and explain.
 */
class TenantAlreadyConnectedException extends XeroBridgeException
{
    public static function make(string $tenantName, string $existingKey, string $attemptedKey): self
    {
        return (new self(
            "The Xero organisation \"{$tenantName}\" is already connected as [{$existingKey}], "
            ."so it cannot also be connected as [{$attemptedKey}]. Use the existing connection, "
            .'or disconnect it first.'
        ))->withConnectionKey($attemptedKey);
    }
}
