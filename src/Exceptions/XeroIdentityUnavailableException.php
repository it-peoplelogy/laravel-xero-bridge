<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Exceptions;

/**
 * TRANSIENT failure talking to identity.xero.com -- a timeout, a connection
 * error, a 5xx or a 429. The stored refresh token is untouched and remains
 * valid; the caller should simply try again later.
 *
 * Keeping this distinct from XeroReauthorizationRequiredException is the whole
 * point. Conflating the two is the live bug in the host project:
 * pips/app/Services/XeroService.php truncates its token table on ANY failed
 * refresh, so a single transient 502 permanently destroys the connection and
 * requires someone to reconnect through a browser.
 */
class XeroIdentityUnavailableException extends XeroBridgeException
{
    public static function make(string $key, string $detail, ?int $status = null): self
    {
        return (new self(
            "Could not reach Xero to refresh the token for connection [{$key}]: {$detail}. "
            .'The stored tokens are unchanged and this can safely be retried.'
        ))->withConnectionKey($key)->withStatusCode($status);
    }
}
