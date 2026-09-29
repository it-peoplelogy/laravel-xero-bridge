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
 * so a cache built on a laptop or a staging box and shipped to production would
 * carry the console route with it.
 *
 * This re-reads the flag on every request, so the page is unreachable in
 * production however the route got there. It is attached inside the package's
 * own route file rather than left to the host's middleware config, so it cannot
 * be configured away by accident.
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
     * A null `enabled` means "decide from the environment". That decision is
     * deliberately NOT made in the config file: a config file is evaluated once,
     * at `config:cache` time, and would freeze whichever environment ran it.
     */
    public static function enabled(Application $app): bool
    {
        $enabled = $app['config']->get('xero-bridge.console.enabled');

        // '' as well as null: a bare XERO_CONSOLE_ENABLED= is not a decision.
        if ($enabled === null || $enabled === '') {
            return ! $app->environment('production');
        }

        return (bool) $enabled;
    }
}
