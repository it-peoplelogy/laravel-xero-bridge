<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Peoplelogy\XeroBridge\Http\Controllers\XeroConsoleController;
use Peoplelogy\XeroBridge\Http\Middleware\EnsureConsoleEnabled;

/*
| The test console. A separate file from the OAuth routes because it is gated
| separately -- off unless XERO_CONSOLE_ENABLED=true, in every environment --
| and because the console being off must not take connect/callback with it.
|
| Like those routes it needs a SESSION: the page posts a CSRF token with every
| action. The default middleware is ['web', 'auth'], which admits ANY
| authenticated user; the fallback below is that same default, for a published
| config that has lost the line.
|
| EnsureConsoleEnabled is attached here rather than left to the host's
| middleware config, because it is what makes the gate survive `route:cache`.
|
| It runs FIRST, ahead of `web` and `auth`, so a disabled console 404s for an
| anonymous visitor instead of redirecting them to a login page -- a redirect
| would advertise that something is here.
*/

Route::middleware(array_merge(
    [EnsureConsoleEnabled::class],
    (array) config('xero-bridge.console.middleware', ['web', 'auth']),
))
    ->prefix(
        trim((string) config('xero-bridge.routes.prefix', 'xero'), '/')
        .'/'.trim((string) config('xero-bridge.console.prefix', 'console'), '/')
    )
    ->name(((string) config('xero-bridge.routes.name_prefix', 'xero-bridge.')).'console')
    ->group(function () {
        Route::get('/', [XeroConsoleController::class, 'index']);

        // Every action the page runs goes through this one endpoint, which
        // answers with a uniform envelope. Named with a leading dot because
        // the group name has none: xero-bridge.console + .run.
        Route::post('run', [XeroConsoleController::class, 'run'])->name('.run');
    });
