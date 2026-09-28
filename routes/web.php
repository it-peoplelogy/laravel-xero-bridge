<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Peoplelogy\XeroBridge\Http\Controllers\XeroCallbackController;
use Peoplelogy\XeroBridge\Http\Controllers\XeroConnectController;

/*
| These two routes need a SESSION, because that is where the OAuth state
| lives. The default middleware is ['web', 'auth']; an API-only host must
| supply something that starts a session.
|
| The webhook route is deliberately NOT here -- it must carry no session and
| no cookies at all, so it is registered separately.
*/

Route::middleware(config('xero-bridge.routes.middleware'))
    ->prefix(config('xero-bridge.routes.prefix'))
    ->name(config('xero-bridge.routes.name_prefix'))
    ->group(function () {
        // The optional key means /xero/connect works for single-organisation
        // apps. The constraint keeps junk out of a unique column and out of
        // flash messages.
        Route::get('connect/{key?}', XeroConnectController::class)
            ->where('key', '[A-Za-z0-9._-]{1,64}')
            ->name('connect');

        Route::get('callback', XeroCallbackController::class)->name('callback');
    });
