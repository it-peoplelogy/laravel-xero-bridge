<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Peoplelogy\XeroBridge\Http\Controllers\XeroWebhookController;
use Peoplelogy\XeroBridge\Http\Middleware\EnsureCookielessResponse;

/*
| Registered SEPARATELY from the connect/callback routes, and deliberately
| with no middleware group at all.
|
| Xero's intent-to-receive check requires a 2xx within 5 seconds and NO
| cookies in the response. The `web` group sets a session cookie, and the
| `api` group only looks safe -- Sanctum's stateful middleware starts a
| session too. So: no group, the session and cookie middleware explicitly
| excluded in case the application has made them global, and Set-Cookie
| stripped from the response as the actual guarantee.
|
| There is no CSRF token here either, which is correct: the HMAC signature is
| the authentication.
*/

Route::post(
    trim((string) config('xero-bridge.routes.prefix'), '/')
        .'/'.trim((string) config('xero-bridge.webhooks.path', 'webhook'), '/'),
    XeroWebhookController::class
)
    ->middleware(EnsureCookielessResponse::class)
    ->withoutMiddleware([
        StartSession::class,
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
    ])
    ->name(config('xero-bridge.routes.name_prefix').'webhook');
