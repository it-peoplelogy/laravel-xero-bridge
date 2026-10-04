<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Peoplelogy\XeroBridge\Http\Controllers\XeroWebhookController;
use Peoplelogy\XeroBridge\Http\Middleware\EnsureCookielessResponse;
use Peoplelogy\XeroBridge\Support\XeroConfig;

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
|
| Loaded only while XeroConfig::webhooksActive(): webhooks.enabled on AND a
| XERO_WEBHOOK_KEY set. With no key there is nothing a delivery could be
| verified against, so an application that only calls Xero serves no
| endpoint at all. The controller still answers 401 without a key, for the
| route a stale route:cache can carry past that check.
|
| The URI is routes.prefix + webhooks.path, unless webhooks.prefix replaces
| the first half (XeroConfig::webhookUri() holds the rule). The stack below
| goes wherever the URI goes: under an `api/...` prefix it still gets no
| middleware group.
*/

Route::post(app(XeroConfig::class)->webhookUri(), XeroWebhookController::class)
    ->middleware(EnsureCookielessResponse::class)
    ->withoutMiddleware([
        StartSession::class,
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
    ])
    ->name(config('xero-bridge.routes.name_prefix').'webhook');
