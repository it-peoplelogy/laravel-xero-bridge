<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Exceptions;

/**
 * The connection key already points at a DIFFERENT Xero organisation, and
 * on_key_conflict is 'error'.
 */
class ConnectionKeyConflictException extends XeroBridgeException
{
    public static function make(string $key, string $existingTenantName, string $newTenantName): self
    {
        return (new self(
            "Connection [{$key}] is already bound to the Xero organisation \"{$existingTenantName}\" "
            ."and cannot be repointed at \"{$newTenantName}\". Disconnect it first, or set "
            .'XERO_ON_KEY_CONFLICT=replace to allow the key to be reused.'
        ))->withConnectionKey($key);
    }
}
