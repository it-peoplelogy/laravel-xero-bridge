<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The second half of the console's gate.
 *
 * The service provider already declines to register the route when the console
 * is off, which keeps `route:list` honest. That alone is not enough:
 * `php artisan route:cache` serialises whatever routes existed at cache time,
 * so a cache built on a laptop where the console was on, and shipped to a host
 * where it is off, would carry the console route with it.
 *
 * This re-reads the flag on every request, so the page is unreachable wherever
 * the console is off, however the route got there. It is attached inside the
 * package's own route file rather than left to the host's middleware config, so
 * it cannot be configured away by accident.
 */
class EnsureConsoleEnabled
{
    public function __construct(private readonly Application $app) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(static::enabled($this->app), 404);

        return $next($request);
    }

    /**
     * Shared with the service provider, so registration and the request-time
     * check can never drift apart.
     *
     * On only for a value that reads as true. Unset, empty, false, 'off', 'no'
     * and anything unrecognised are all off, in every environment. APP_ENV is
     * deliberately not consulted: a live host whose APP_ENV is `prod`, `live` or
     * `Production` is no less live, and a staging box cloned from an
     * .env.example that says `local` is not a developer's laptop. Only an
     * explicit XERO_CONSOLE_ENABLED=true is a decision to expose the page.
     *
     * filter_var rather than a cast: (bool) 'false' is true, and a switch that
     * guards a page like this must fail closed on the value whose plain meaning
     * is off -- which a hand-edited config or a runtime config()->set() can
     * deliver as a string.
     */
    public static function enabled(Application $app): bool
    {
        return filter_var($app['config']->get('xero-bridge.console.enabled'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Why the gate is in its current state, in the words of the env variable
     * that changes it -- for command output, so a command can never describe a
     * gate other than the one enabled() applies.
     */
    public static function reason(Application $app): string
    {
        $value = $app['config']->get('xero-bridge.console.enabled');

        if ($value === null || $value === '') {
            return 'XERO_CONSOLE_ENABLED is not set';
        }

        return static::enabled($app) ? 'XERO_CONSOLE_ENABLED=true' : 'XERO_CONSOLE_ENABLED=false';
    }
}
