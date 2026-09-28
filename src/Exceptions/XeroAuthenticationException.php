<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Exceptions;

use Illuminate\Http\Client\Response;

/**
 * Xero refused authentication on a request (401/403) and refreshing the token
 * did not fix it.
 */
class XeroAuthenticationException extends XeroBridgeException
{
    public static function afterRefresh(Response $response, ?string $connectionKey = null): self
    {
        return (new self(
            'Xero returned 401 again immediately after a successful token refresh. '
            .'The connection may have been disconnected inside Xero, or this tenant is no '
            .'longer authorised for the application.'
        ))->withResponse($response, $connectionKey);
    }
}
