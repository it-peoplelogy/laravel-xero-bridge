<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Exceptions;

/**
 * No stored connection for this key. The remedy is always the same: send
 * someone through the consent flow, so the message carries the URL.
 */
class XeroConnectionNotFoundException extends XeroBridgeException
{
    public static function forKey(string $key, string $connectUrl): self
    {
        return (new self(
            "No Xero connection is stored under [{$key}]. Connect an organisation at {$connectUrl}."
        ))->withConnectionKey($key);
    }
}
