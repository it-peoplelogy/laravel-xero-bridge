<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Strips every cookie from the webhook response.
 *
 * Xero's intent-to-receive check fails outright if the response carries ANY
 * cookie. Registering the route without the `web` group is necessary but not
 * sufficient -- something else in the application can still queue a cookie
 * (Sanctum's stateful middleware starts a session; a global middleware may
 * call Cookie::queue()). Removing the header is the only actual guarantee.
 */
class EnsureCookielessResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        foreach ($response->headers->getCookies() as $cookie) {
            $response->headers->removeCookie(
                $cookie->getName(),
                $cookie->getPath(),
                $cookie->getDomain(),
            );
        }

        $response->headers->remove('Set-Cookie');

        return $response;
    }
}
