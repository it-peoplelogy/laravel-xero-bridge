# Getting started

Installing `peoplelogy/laravel-xero-bridge` into a Laravel application, creating the Xero app it talks
to, and connecting your first organisation.

Everything on this page has been read from the package source. Where a behaviour is surprising, the test
that pins it is named.

---

## What this package is

A thin wrapper around the [Xero Accounting API](https://developer.xero.com/documentation/api/accounting/overview),
built on Laravel's own HTTP client. It owns the parts of a Xero integration that are tedious and easy to
get subtly wrong:

- the OAuth 2.0 authorisation-code flow, including CSRF state in the session
- storing credentials, with both tokens encrypted at rest and kept out of `toArray()` and JSON
- the token lifecycle: 30-minute access tokens, rotating refresh tokens, per-connection locking
- rate limits, `Retry-After`, retries and idempotency keys
- typed exceptions that tell you whether to fix `.env`, fix the payload, retry later, or send a human to
  reconnect
- resource wrappers for invoices, contacts, payments and organisation settings

## What this package is not

**It is not bookkeeping logic.** It never decides when to raise an invoice or what goes on it. Your
application does that.

**It does not wrap every Xero API.** Payroll, Files, Projects, Assets and Bank Feeds are not wrapped, and
neither are the Accounting endpoints outside the four resources above. They are still reachable — see
[Anything the package has not wrapped](#anything-the-package-has-not-wrapped) below.

**It does not implement the client-credentials grant.** `src/OAuth/IdentityClient.php` sends only
`authorization_code` and `refresh_token`. Xero Custom Connections and the Xero App Store API use
client credentials, so they are out of scope by design.

**It does not cache reference data.** `settings()->accounts()` hits Xero every time. The right cache key,
TTL and invalidation depend on your application, so caching belongs there.

---

## Support matrix

| | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|
| **PHP 8.2** | best-effort | supported | — Laravel 13 needs PHP 8.3+ |
| **PHP 8.3** | best-effort | supported | supported |
| **PHP 8.4** | best-effort | supported | supported |

Every cell except the impossible one runs in CI on every push to `main` and on every pull request — eight
jobs. The constraint in `composer.json` is `php: ^8.2` with
`illuminate/contracts: ^11.0||^12.0||^13.0`.

> ### ⚠️ Laravel 11 is End of Life
>
> Laravel 11 receives no further security fixes, so it is supported here on a **best-effort** basis:
> the CI legs run and pass, but they are non-blocking.
>
> Because unpatched advisories affect the whole 11.x line, Composer may refuse a full
> `composer update` on a Laravel 11 application. That is a signal about **your** application, not
> about this package, and `--no-security-blocking` or `policy.advisories.block false` silence it
> without fixing it. `composer require` makes a minimal change and usually still succeeds.
>
> See [Laravel's support policy](https://laravel.com/docs/releases#support-policy) for current dates
> and versions, and upgrade when you can — that is a fix for your application, not a requirement of
> this package.

---

## Installation

### 1. Add the repository

The package is not on Packagist, so Composer has to be told where to find it. Add to the consuming
application's `composer.json`:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/it-peoplelogy/laravel-xero-bridge.git",
        "no-api": true
    }
]
```

`repositories` is a top-level key, sitting alongside `require` and `require-dev`.

The repository is public, so **no authentication is required** — no SSH key, no token, no deploy key,
and nothing to configure on a build server. Composer reads it anonymously, as long as the entry keeps
both of these:

- **The `https://` address.** Not `git@github.com:it-peoplelogy/laravel-xero-bridge.git`: GitHub refuses
  SSH without a key even for a public repository, and Composer then stops to ask for a GitHub token.
- **`"no-api": true`.** Without it Composer looks versions up through GitHub's API, which allows 60
  anonymous requests an hour. When that lookup fails — the limit used up, or a stale GitHub token saved
  on the machine — Composer asks for a token; under `--no-interaction` it falls back to cloning over
  SSH, which fails on a server with no key, or stops with an API-limit error. With `no-api`, Composer
  runs plain `git` over https and never calls the API.

With `no-api` the package is installed as a git checkout in `vendor/` — there is no zip to download. If
your deployment runs `chmod` over `vendor/`, git sees the changed files as modified and the next update
stops to ask whether to discard them, or under `--no-interaction` fails with "has uncommitted changes".
Add `"discard-changes": true` to your `config` block and Composer replaces them without asking.

The source is publicly visible so Peoplelogy Group's applications can install it without credentials.
Visibility grants no licence: the package is proprietary, and copying, modifying, distributing or using
it outside Peoplelogy Group requires prior written permission — see [LICENSE.md](../LICENSE.md).

### 2. Require a tagged version

```bash
composer require peoplelogy/laravel-xero-bridge:^1.0
```

> ### ⚠️ A tagged release is mandatory
>
> Consuming applications set `"minimum-stability": "stable"`. Against a `vcs` repository Composer derives
> versions from **git tags**, so an untagged package resolves only to `dev-main`, which is `dev`
> stability and will be **refused** — with an error about the package not being found at that version,
> which reads like a permissions problem and is not.
>
> Tag before the first `composer require`, not after the first failure.
>
> If you need an unreleased commit, cut a pre-release tag such as `v1.1.0-beta.1` and require
> `^1.1@beta`. That scopes the relaxation to this one package instead of loosening `minimum-stability`
> for the whole application.

The service provider and the `XeroBridge` facade alias are registered through package discovery
(`composer.json` → `extra.laravel`), so there is nothing to add to `bootstrap/providers.php`.

### 3. Publish and migrate

```bash
php artisan xero-bridge:install
php artisan migrate
```

`xero-bridge:install` publishes `config/xero-bridge.php` and the package's four migrations — for
`xero_connections`, `xero_webhook_events`, `xero_write_records` and `xero_api_calls` — then prints every
`.env` key you need, the exact redirect URI to register on the Xero app, the connect URL, whether the
test console is on in this environment, and your webhook URL. Pass `--force` to overwrite files that
already exist.

It also warns about what would otherwise fail later and less clearly: a webhook URL that is not https
(Xero only delivers webhooks to https on port 443, so in practice an `APP_URL` that is not), a redirect
URI Xero would refuse, a connect route — or a switched-on console — behind nothing more than `web,auth`,
and the need to schedule `xero-bridge:refresh-tokens` with `withoutOverlapping()` and `onOneServer()`.
`tests/Feature/CommandsTest.php` asserts the key list and the https warning;
`tests/Feature/InstallCommandTest.php` covers the console, connect-route, webhook and redirect-URI
output.

If you would rather publish by hand, the tags are `xero-bridge-config` and `xero-bridge-migrations`.
The optional MyInvois module has tags of its own, `myinvois-config` and `myinvois-migrations`, which
`xero-bridge:install` never publishes.

Every table is created under a **bare** name, and your database connection adds its own prefix — see
[Table prefixes](#table-prefixes). If a table of that name already exists and is not the one the
migration creates, that migration stops before changing anything: it names the table, the columns it
lacks and the variable below, is not recorded as run, and the next `migrate` resumes at it once the
clash is resolved. So when a name is already taken, set its variable **before** migrating:

| Table | Variable |
|---|---|
| `xero_connections` | `XERO_DB_TABLE` |
| `xero_write_records` | `XERO_WRITES_TABLE` |
| `xero_webhook_events` | `XERO_WEBHOOK_DEDUPE_TABLE` |
| `xero_api_calls` | `XERO_CAPTURE_TABLE` |
| `myinvois_validations` (MyInvois tag only) | `MYINVOIS_AUDIT_TABLE` |

The package's models read the same variables at runtime, so set the variable in every environment, and
run `php artisan config:clear` first if your configuration is cached. A migration published by 1.5.0 or
later also leaves such a table alone when it is rolled back. `tests/Unit/MigrationForeignTableTest.php`
pins both, for every migration.

The package also ships a [test console](07-test-console.md) at `/xero/console`. It is **off** unless
`XERO_CONSOLE_ENABLED=true` is set in the environment that should have it — in every environment,
whatever `APP_ENV` says — and `xero-bridge:install` tells you which state it is in and why.

### 4. Developing the package and an application together

```json
"repositories": [
    {
        "type": "path",
        "url": "../laravel-xero-bridge",
        "options": { "symlink": true }
    }
]
```

then `composer require peoplelogy/laravel-xero-bridge:@dev`. With `symlink: true`, edits in the package
are live in the application with no reinstall.

> This must never reach a deployment: the path does not exist on the server, and `@dev` violates the
> application's `minimum-stability`. Keep it in a local-only overlay and revert before committing.

---

## Create your Xero app

Each environment should have its **own** Xero app — separate client IDs mean a leaked staging secret
cannot touch production, and the redirect URI is registered per app anyway.

Go to [developer.xero.com/myapps](https://developer.xero.com/myapps) and:

1. Sign in with a Xero account that has access to the organisation you want to connect.
2. **Add App**, and choose **Web app**.
3. Give it a company URL and a redirect URI (see below).
4. Copy the **Client ID**, then generate a **Client Secret**.

> **Choose Web app, not a Custom Connection.** A Custom Connection is a client-credentials grant bound to
> a single organisation at creation time. This package implements the authorisation-code flow only, so a
> Custom Connection's credentials will not work with it — and a Custom Connection cannot serve more than
> one organisation, which is the whole point of connection keys.

> The client secret is displayed **once**. If you lose it you must generate a new one, and the old one
> stops working immediately.

### Redirect URIs

The `redirect_uri` sent in the authorisation request must match one registered on the app **exactly**,
including any trailing slash. The package sends whatever `XERO_REDIRECT_URI` holds, and sends the same
value again at token exchange — Xero checks it both times.

| Environment | Redirect URI |
|---|---|
| Local | `http://localhost:8000/xero/callback` |
| Staging | `https://staging.example.com/xero/callback` |
| Production | `https://example.com/xero/callback` |

HTTPS is required everywhere except `localhost`.

> ### ⚠️ Xero rejects `http://127.0.0.1` but allows `http://localhost`
>
> They are the same machine, and every other OAuth provider treats them as equivalent, so this catches
> people out constantly. Xero's own error for it is vague, so `XeroConfig::redirectUri()` checks the host
> itself and throws `XeroConfigurationException::loopbackRedirectUri()` with the fix in the message —
> before anyone is sent to Xero. `127.0.0.1`, `[::1]` and `::1` are all rejected the same way.
>
> Anything else that is not https and not `localhost` throws
> `XeroConfigurationException::insecureRedirectUri()`.
>
> Pinned by `tests/Feature/ConnectRouteTest.php` ("rejects a 127.0.0.1 redirect uri with the fix in the
> message" and "allows http://localhost for testing").

If `XERO_REDIRECT_URI` is left null, the package falls back to the URL of its own named callback route at
runtime. That is convenient locally and a liability in production, where the generated URL depends on
`APP_URL` and proxy headers. Set it explicitly.

### Scopes

The default scope string is granular:

```
openid profile email offline_access accounting.invoices accounting.payments accounting.contacts accounting.settings accounting.attachments
```

| Scope | What it unlocks |
|---|---|
| `offline_access` | **Mandatory.** Without it Xero issues no refresh token and the connection dies after 30 minutes. |
| `accounting.settings` | Accounts, TaxRates, Organisation, BrandingThemes — the `settings()` resource, which only reads, so `accounting.settings.read` is enough |
| `accounting.contacts` | Contacts — `contacts()`, whose `create()`, `update()` and `firstOrCreate()` write |
| `accounting.invoices` | Invoices, CreditNotes, Quotes, Items |
| `accounting.payments` | Payments, Overpayments, Prepayments — `payments()` |
| `accounting.attachments` | Attachments. The package wraps none of them, so this matters only for Attachments endpoints you call through [`XeroBridge::request()`](#anything-the-package-has-not-wrapped). |
| `openid profile email` | Nothing, in this package. They make Xero return an `id_token` describing the user who authorised, and the package discards it unread: no column stores it and no event carries it. Leave them out unless something outside this package needs them. |

`XeroConfig::assertOfflineAccess()` refuses to start the flow without `offline_access`, so a missing
refresh token fails at `/xero/connect` rather than 30 minutes after a successful-looking connect.

> ### ⚠️ The default asks for more than most applications need
>
> It requests every scope a wrapped resource could use, plus four the package itself never uses. An
> application that raises invoices and finds or creates the contacts on them needs only:
>
> ```dotenv
> XERO_SCOPES="offline_access accounting.invoices accounting.contacts accounting.settings.read"
> ```
>
> Add `accounting.payments` to record payments (`payments()`, including `createForInvoice()`), and use
> the `.read` variant of anything you only ever read. Keep a settings scope whatever else you drop:
> discovering account codes and tax rates needs it, and so does the test console's Demo Company write
> guard, which reads the Organisation.

> ### ⚠️ Scopes are fixed at authorisation time
>
> Editing `XERO_SCOPES` changes only the **next** consent. The connect URL asks for the new set, but a
> token refresh requests no scopes at all, so every existing connection keeps exactly what it was
> granted.
>
> - **Widening** reaches a connection when it reconnects. **Deploy the scope change before asking
>   anyone to reconnect**, or they will have to connect twice.
> - **Narrowing** never happens by reconnecting: Xero adds consented scopes and never removes them, so
>   the only way to shed one is to revoke the authorisation and connect again. Revoking disconnects
>   every organisation authorised in that flow; the endpoint is in the
>   [README's Uninstalling section](../README.md#uninstalling).
>
> The granted scope string is stored per connection in `xero_connections.scopes`, which is how
> `XeroConnection::hasScope()` can tell "needs re-consent" apart from "needs a refresh".

> ### ⚠️ Broad scopes retire in September 2027
>
> `accounting.transactions` is being replaced by `accounting.invoices` + `accounting.payments` +
> `accounting.banktransactions` + `accounting.manualjournals`. Since March 2026 every Web and PKCE app,
> new and existing, has been assigned the granular scopes; the broad ones keep working until September
> 2027. This package ships granular scopes; do not add broad ones back.
> `tests/Unit/ConfigTest.php` asserts `accounting.transactions` is absent from the default.

---

## Configuration

Every key in `config/xero-bridge.php`, and what goes wrong if it is missing or wrong. Two optional,
off-by-default features document their keys on their own pages: durable webhook replay dedupe in
[Persistence](09-persistence.md), and [API call capture](10-api-capture.md). MyInvois TIN validation
has a config file of its own, `config/myinvois.php` — see
[MyInvois TIN validation](08-myinvois-tin-validation.md).

A published `config/xero-bridge.php` receives the keys later releases add without being republished,
and a published `config/myinvois.php` likewise. On every boot that is not served from a cached
configuration, the package adds each key your file lacks, at any depth, with its shipped default. It
never changes a key you have — `null`, `false`, `''` and `[]` all count as values you chose, and a list
such as a middleware stack is one value, never topped up — and it never adds anything inside
`connections`, which is keyed by your own connection names. The flip side: a line you **delete** comes
back with the shipped default, so to switch something off, set it rather than removing it. A
configuration cached before an update holds none of the new keys until it is rebuilt, so run
`php artisan config:cache` (or `config:clear`) as on any deploy. `tests/Unit/ConfigFillTest.php` pins
this behaviour.

The three credentials deliberately have **no default**. A wrong-but-present default turns a
misconfiguration into an opaque 401 from Xero hours later; a missing value throws
`XeroConfigurationException` at the point of use with a message naming the env key. There is a test
asserting the config file contains `env('XERO_CLIENT_ID')` and never `env('XERO_CLIENT_ID', ...)`.

### Credentials and connection

| Key | Default | What breaks if it is wrong |
|---|---|---|
| `XERO_CLIENT_ID` | none | Empty: `XeroConfigurationException` naming `XERO_CLIENT_ID` at `/xero/connect`. Wrong: Xero returns `unauthorized_client`, which the callback reports as "This application is not authorised by Xero." |
| `XERO_CLIENT_SECRET` | none | Empty: `XeroConfigurationException` naming `XERO_CLIENT_SECRET` at `/xero/connect`, before anyone is sent to Xero. Wrong: the token request fails `invalid_client` — which looks nothing like an expired connection, so do not go hunting for one. |
| `XERO_REDIRECT_URI` | callback route URL | Not an exact match for one registered on the app: Xero refuses before your callback ever runs. `127.0.0.1` or plain http: `XeroConfigurationException` at `/xero/connect`. |
| `XERO_SCOPES` | the granular set above | Missing `offline_access`: `XeroConfigurationException` at `/xero/connect`, and `xero-bridge:status` exits 1. A scope you never requested: `XeroScopeException` at the call site — refreshing will not help, the user must reconnect. |
| `XERO_DEFAULT_CONNECTION` | `default` | Changing it after connections exist orphans them: `XeroBridge::invoices()` then resolves a key nothing is stored under, and the first call needing a token throws `XeroConnectionNotFoundException`. |

### Routes

| Key | Default | What breaks if it is wrong |
|---|---|---|
| `XERO_ROUTES_ENABLED` | `true` | False removes `/xero/connect` and `/xero/callback`; the webhook and the test console have switches of their own. `php artisan route:cache` bakes in whatever this was **at cache time**, so a `.env` change without `route:clear` does nothing. |
| `XERO_ROUTES_PREFIX` | `xero` | Changes the connect, callback and console paths — and the webhook's too, unless `XERO_WEBHOOK_PREFIX` gives it its own. Once the webhook URL is registered with Xero, moving it means re-entering it in the Xero app's Webhooks tab (Xero re-runs its intent-to-receive check) and rebuilding `route:cache`; until then delivery fails. |
| `XERO_ROUTES_NAME_PREFIX` | `xero-bridge.` | Route names become `<prefix>connect`, `<prefix>callback`, `<prefix>webhook`, and `<prefix>console` with `<prefix>console.run` while the console is on. Change it and any `route('xero-bridge.connect')` in your own code breaks. |
| `XERO_ROUTES_MIDDLEWARE` | `web,auth` | Comma separated, parsed into an array. **The flow needs a session** — that is where the OAuth state lives, and the browser comes back from Xero with cookies but no bearer token. Keep `web` (or anything else that starts a session), a session driver that persists between requests, and `SESSION_SAME_SITE=lax` rather than `strict`; otherwise every callback fails with "the link expired or your session changed". See [The flow needs a browser session](#the-flow-needs-a-browser-session). The default lets **any signed-in user** connect an organisation, or repoint an existing connection at one of their own, and `xero-bridge:install` warns about it: add authorisation here, e.g. `web,auth,can:manage-xero`. |
| `XERO_AFTER_CONNECT_ROUTE` | none | A named route to send the user to after a connect attempt, successful or not, when neither a [`redirectAfterConnectUsing()`](#redirectafterconnectusing) closure nor a `return_to` decided. Ignored if the name is not registered. It must need no route parameters — the callback has none to give — or its URL cannot be built: that is reported and logged, and `XERO_AFTER_CONNECT_REDIRECT` is used instead. |
| `XERO_AFTER_CONNECT_REDIRECT` | `/` | The path used when nothing above it decided. |
| `XERO_ALLOW_RETURN_TO` | `false` | Honours `?return_to=` on the connect route. Off by default: an unvalidated redirect target on an authenticated route is an open redirect. When on, only same-host absolute paths are accepted — `//evil.test` is rejected as protocol-relative. |

### Test console

The full story is in [The test console](07-test-console.md).

| Key | Default | What breaks if it is wrong |
|---|---|---|
| `XERO_CONSOLE_ENABLED` | unset — **off** | On only for a value that reads as true: `true`, `1`, `on` or `yes`. Unset, empty, `false` and anything unrecognised are off in **every** environment; `APP_ENV` plays no part. Off, the route is not registered — so guard any `route('xero-bridge.console')` with `Route::has()` — and a route cached while it was on answers 404. Where config or routes are cached, switching it on takes `config:clear` and `route:clear` (or rebuilt caches). Before 1.5.0, leaving it unset switched the console on wherever `APP_ENV` was not exactly `production`. |
| `XERO_CONSOLE_PREFIX` | `console` | Appended to `XERO_ROUTES_PREFIX`, giving `/xero/console`. |
| `XERO_CONSOLE_MIDDLEWARE` | `web,auth` | Comma separated. The page posts a CSRF token, so it needs a session. The default admits **any signed-in user**, and whoever gets in can read the connected organisation's invoices and contacts, force a token refresh and forget the stored connection. Narrow it wherever you switch the console on, e.g. `web,auth,can:manage-xero`. |
| `XERO_CONSOLE_WRITABLE_ORGANISATIONS` | none | Exact organisation names the console may write into besides a Xero Demo Company, comma separated and matched case-insensitively. Anything else is somebody's real ledger. |

### Webhooks

| Key | Default | What breaks if it is wrong |
|---|---|---|
| `XERO_WEBHOOK_KEY` | none | Unset or wrong: **every** webhook is rejected with a 401 and the signature check fails closed. `xero-bridge:status` warns when it is unset while webhooks are on. |
| `XERO_WEBHOOKS_ENABLED` | `true` | Set it false when the application only calls Xero and never needs Xero to call it: `xero-bridge:status` then stops warning about `XERO_WEBHOOK_KEY`. Write `false` or `0` — `off` and `no` read as on. False unregisters the webhook route entirely; Xero's deliveries 404 and the subscription is disabled after 24 hours of failures. |
| `XERO_WEBHOOK_PREFIX` | none — follows `XERO_ROUTES_PREFIX` | The webhook's own prefix, in place of `XERO_ROUTES_PREFIX`: `api/v1/xero` serves it at `/api/v1/xero/webhook` while connect, callback and the console stay where they are. Unset or empty follows `XERO_ROUTES_PREFIX`, so a bare `XERO_WEBHOOK_PREFIX=` moves nothing; `/` means the site root, `/webhook`. The route keeps its name and its cookieless middleware under any prefix. Moving it means re-entering the URL in the Xero app's Webhooks tab — Xero re-runs its intent-to-receive check — and rebuilding `route:cache`. |
| `XERO_WEBHOOK_PATH` | `webhook` | Appended to the webhook's prefix: `XERO_WEBHOOK_PREFIX` when set, otherwise `XERO_ROUTES_PREFIX`. A leading slash is trimmed rather than read as absolute, so `/webhook` still means `/xero/webhook`. Changing it after registering the URL with Xero breaks delivery until you re-enter it there. |
| `XERO_WEBHOOK_QUEUE` | default queue | The queue the envelope job is pushed onto. |
| `XERO_WEBHOOK_QUEUE_CONNECTION` | default connection | Set this to something durable. On `sync` — set here, or `QUEUE_CONNECTION` while this is unset — the job runs inside the request: listener time counts against Xero's 5-second budget, and a listener that throws turns the response into a 500, so Xero redelivers the whole envelope and the listeners that had succeeded run again, unless replay dedupe recorded them. |
| `XERO_WEBHOOK_UNIQUE_FOR` | `900` | Seconds, at least 60. A redelivery of the same events, while their job is still queued, waiting to retry or running, is answered 200 without being queued again — or 503, so Xero retries later, if it arrives before the first delivery is confirmed queued; see [When Xero retries a delivery](04-webhooks-and-events.md#when-xero-retries-a-delivery). The lock that does it is released when the job finishes or its last attempt fails, so this is only the ceiling for a job whose worker died. The lock lives in the store `XERO_LOCK_STORE` names, or the default cache store while that is unset. |
| `XERO_WEBHOOK_TRIES` | `5` | Attempts for the envelope job, including the first, with backoff of 10s, 30s, 2m and 10m. With `1` a failing listener gets no retry, and the event it failed on and every event after it in that envelope are lost. |
| `XERO_WEBHOOK_UNKNOWN_TENANTS` | `dispatch` | Events for an organisation with no stored connection here. Xero sends events for every organisation connected to the Xero app — one connected from another environment, or one forgotten here but never disconnected at Xero. `dispatch` fires `XeroWebhookReceived` for them like any other, as every release before 1.5.0 did, so each listener decides — for example with `$event->connection()`. `ignore` skips them before any listener runs: they are neither dispatched nor recorded. Under `ignore` an invalidated connection still counts as known, App Store (`APPLICATION`) events are never skipped, and an event whose lookup fails is dispatched rather than lost. Any other value means `dispatch`. The worker reads it, so after changing it run `config:clear` (or re-cache) and `queue:restart`. |

Durable replay dedupe — `XERO_WEBHOOK_DEDUPE`, `XERO_WEBHOOK_DEDUPE_TABLE` and
`XERO_WEBHOOK_DEDUPE_RETAIN_DAYS` — is covered in [Persistence](09-persistence.md).

### HTTP client

| Key | Default | What breaks if it is wrong |
|---|---|---|
| `XERO_HTTP_TIMEOUT` | `30` | Seconds. Too low and large invoice pages fail as timeouts, which the package treats as transient and retries. |
| `XERO_HTTP_CONNECT_TIMEOUT` | `10` | Seconds to establish the connection. |
| `XERO_HTTP_RETRIES` | `3` | **Total attempts including the first**, matching `Http::retry()`. `1` disables retrying; `0` is clamped up to `1`, never read as "send nothing". |
| `XERO_HTTP_RETRY_BASE_MS` | `1000` | Base backoff between attempts. |
| `XERO_HTTP_RETRY_MAX_MS` | `30000` | Hard ceiling on any single inline sleep. Xero's `Retry-After` on a daily limit can be hours; rather than pin a worker, the client throws with `retryAfter()` so a queued job can `release()` itself. |
| `XERO_HTTP_CONCURRENCY_BACKOFF_MS` | `250` | Concurrency-limit 429s carry **no** `Retry-After` header at all — only the minute and daily limits do — so they need their own short backoff. |
| `XERO_OFFLINE_RETRY_AFTER` | `300` | Seconds suggested for Xero's "The Organisation is offline" 503. |
| `XERO_HTTP_IDEMPOTENCY` | `true` | Sends an `Idempotency-Key` on writes. Set it false and **write requests are never retried**, unless you pass your own `Idempotency-Key` header — a retried POST that actually succeeded creates a duplicate invoice. |
| `XERO_USER_AGENT` | `"{app.name} (peoplelogy/laravel-xero-bridge)"` | Xero asks for an identifying user agent; a blank one can be throttled harder. |

### Tokens

| Key | Default | What breaks if it is wrong |
|---|---|---|
| `XERO_LOCK_STORE` | default cache store | **Set this.** Every token refresh runs inside a per-connection lock in this store, and so does the webhook job's retry lock (`XERO_WEBHOOK_UNIQUE_FOR`). `array` locks only inside one process and `file` only within one server, so with the scheduler, queue workers or web servers on more than one server, two refreshes can each invalidate the other's rotating refresh token — killing the connection — and a redelivered webhook can be queued twice. Use redis, memcached or database; leave it unset or blank for the default store. The store must hold plain cache entries as well as locks — the webhook controller keeps a "queued" marker beside each lock — so a `database` store needs Laravel's `cache` table, not only `cache_locks`; without it every webhook delivery logs a warning and a retry during a held lock is answered 503 and queued again later. `xero-bridge:status` judges the store by its **class**, never its name: an array store, or a file store that is only the default, is a warning; a file store named here is a note, which `--strict` does not count; a store that cannot lock, the null store, or a name that is not defined does not pass the lock pre-flight, which `--strict` does count. |
| `XERO_REFRESH_LEEWAY` | `60` | Seconds before expiry at which a token is treated as stale. Zero means a token can expire mid-flight. |
| `XERO_LOCK_TTL` | `10` | Seconds a holder may hold the lock. |
| `XERO_LOCK_WAIT` | `12` | Seconds a waiter will wait. |
| `XERO_TOKEN_HTTP_TIMEOUT` | `8` | Timeout on the token endpoint specifically. |
| `XERO_TOKEN_HTTP_RETRIES` | `2` | Network-level retries on the token endpoint. A 4xx is never retried. |

> The three token timings interlock deliberately: `http_timeout (8) < lock_ttl (10) < lock_wait (12)`, so
> a holder can never outlive its lock and a waiter can never give up before a dead holder's lock expires.
> `tests/Unit/ConfigTest.php` asserts the ordering. If you raise one, raise all three.

### Storage and conflict policy

| Key | Default | What breaks if it is wrong |
|---|---|---|
| `XERO_DB_CONNECTION` | default connection | Which database connection holds the package's four Xero tables. Point it somewhere they have not been migrated and every Xero call throws a `QueryException`, because each one reads its connection row first; `xero-bridge:status` reports the missing table and exits `1`. |
| `XERO_DB_TABLE` | `xero_connections` | A **bare** name — see [Table prefixes](#table-prefixes). Set it **before** the first `migrate` when that name is already taken: the migration refuses a table it did not create, changes nothing, and names this variable. The model reads it at runtime too, so set it in every environment. If it names a table that is missing or not the package's own, `xero-bridge:status` says so and exits `1`. |
| `XERO_ON_KEY_CONFLICT` | `replace` | The key exists but points at a different organisation. `replace` treats the key as an app-side slot and repoints it, overwriting the row's organisation and tokens; `error` refuses with `ConnectionKeyConflictException` and tells the user to disconnect the old organisation first. Any other value acts as `error`. |
| `XERO_ON_TENANT_CONFLICT` | `error` | This organisation is already connected under a different key. `error` refuses with `TenantAlreadyConnectedException`; `rekey` moves the existing row to the new key — and if that key already holds a different organisation, `XERO_ON_KEY_CONFLICT` decides: `replace` deletes that row first, `error` refuses and changes nothing. Any value but `rekey` acts as `error`. The default is `error` because silently re-keying would break every caller that already references the old key. |

### Write ledger

Optional and off by default: durable duplicate protection for writes that name the record they belong
to, `XeroBridge::invoices()->for($order)->create([...])`. How it works is in
[Persistence](09-persistence.md).

| Key | Default | What breaks if it is wrong |
|---|---|---|
| `XERO_WRITES_LEDGER` | `false` | Switches the ledger on. It needs the `xero_write_records` table migrated; until that exists, writes go out unprotected — or, with `XERO_WRITES_STRICT` on, the ones named with `for()` are refused. `xero-bridge:status` warns about a missing table and about claims stuck pending for over an hour. |
| `XERO_WRITES_STRICT` | `false` | What a write named with `for()` does when the ledger is on but cannot record its claim — the table is missing, the database is unreachable, or the insert fails for any reason other than a duplicate. `false` logs it and sends the write **unprotected**, as every release before 1.5.0 did. `true` refuses it with `XeroWriteLedgerUnavailableException` before anything is sent, so retrying is safe. A write with no owner is never refused, and a write inside a database transaction is still only warned about. |
| `XERO_WRITES_TABLE` | `xero_write_records` | A **bare** name. Set it before migrating if that name is already taken — see [Publish and migrate](#3-publish-and-migrate). |
| `XERO_WRITES_RETAIN_DAYS` | `90` | Days `xero-bridge:prune` keeps a succeeded row. A pending row is never pruned, at any age: deleting it would allow the very duplicate it exists to prevent. |

### Invoice defaults

Filled into `LineItems[].AccountCode` / `TaxType` and top-level `CurrencyCode` / `BrandingThemeID` **only
where the caller left them out**.

| Key | Default | What breaks if it is wrong |
|---|---|---|
| `XERO_ACCOUNT_CODE` | *(none)* | **No default, deliberately.** Account codes differ per organisation — one org's sales account may be `200`, another's `4000`, another's `410002-001`. A wrong-but-present default would post to the wrong ledger account and Xero would accept it happily; with none set, Xero rejects the invoice with a `XeroValidationException` naming the problem. |
| `XERO_TAX_TYPE` | none, on purpose | Malaysian SST is per **line**: training is 8%, education and rental or leasing are 6%. A connection-wide default stamps the wrong rate on a venue recharge. Set it only if every line of every invoice on this connection genuinely carries the same rate. |
| `XERO_CURRENCY` | `MYR` | A currency the organisation has not enabled is rejected by Xero. |
| `XERO_BRANDING_THEME_ID` | none | Per organisation. A theme ID from another organisation is rejected. |

### Endpoints

`XERO_AUTHORIZE_URL`, `XERO_TOKEN_URL`, `XERO_REVOCATION_URL`, `XERO_CONNECTIONS_URL` and `XERO_API_URL`
exist so tests and sandboxes can point elsewhere. There is no reason to set them in an application.

### `.env.example`

```dotenv
# --- Xero app credentials (developer.xero.com/myapps) -----------------------
# Never commit real values. The client secret is shown once, at creation.
XERO_CLIENT_ID=
XERO_CLIENT_SECRET=

# Must match a redirect URI registered on the Xero app EXACTLY, including any
# trailing slash. https everywhere except http://localhost.
XERO_REDIRECT_URI="${APP_URL}/xero/callback"

# Granular scopes. offline_access is mandatory or you get no refresh token.
# This is the shipped default, which asks for more than most applications
# need -- see Scopes above for an invoice-only set.
XERO_SCOPES="openid profile email offline_access accounting.invoices accounting.payments accounting.contacts accounting.settings accounting.attachments"

# From the Xero app's Webhooks tab. Leave blank to reject all webhooks.
XERO_WEBHOOK_KEY=

# Holds the token-refresh and webhook-retry locks. array locks within one
# process and file within one server; across servers, use redis, memcached
# or database.
XERO_LOCK_STORE=redis

# --- Invoice defaults (these differ per Xero organisation) ------------------
# No default. Find yours with settings()->accounts() -- e.g. 200, 4000, 410002-001
XERO_ACCOUNT_CODE=
XERO_CURRENCY=MYR
# Leave unset unless every line of every invoice carries the same rate.
XERO_TAX_TYPE=

# --- HTTP -------------------------------------------------------------------
XERO_HTTP_TIMEOUT=30
XERO_HTTP_RETRIES=3
```

---

## Connecting an organisation

Send an administrator to `/xero/connect`. They sign in at Xero, choose which organisations to authorise,
and Xero returns them to `/xero/callback`.

Both routes sit behind `['web', 'auth']` by default, so in practice you link to them from an
authenticated settings screen:

```blade
<a href="{{ route('xero-bridge.connect') }}">Connect Xero</a>
```

or, for a named connection:

```blade
<a href="{{ route('xero-bridge.connect', ['key' => 'acme']) }}">Connect Acme's Xero</a>
```

The `key` segment is constrained to `[A-Za-z0-9._-]{1,64}` in `routes/web.php`, which keeps junk out of a
unique column and out of flash messages.

> ### ⚠️ Any signed-in user can connect, or repoint, an organisation
>
> `auth` admits every account your application has, and whoever reaches `/xero/connect/{key}` decides
> which Xero organisation that key points at. Under the default `XERO_ON_KEY_CONFLICT=replace` that
> includes repointing a key already in use at an organisation of their own, after which every call
> through the key reaches their ledger. Put your own gate in `XERO_ROUTES_MIDDLEWARE`, e.g.
> `web,auth,can:manage-xero`; `xero-bridge:install` warns for as long as the shipped `web,auth` is in
> place.

### The flow needs a browser session

Connect and callback are two halves of one round trip through Xero's sign-in page. The OAuth state that
ties them together is kept in the session, so a state value presented from any other browser is
rejected (`tests/Feature/CallbackRouteTest.php`, "rejects a valid state presented from another
session"). And when Xero sends the browser back to `/xero/callback`, it carries cookies and nothing
else — no bearer token. So these two routes need:

- **Session middleware** — `web`, or your own equivalent.
- **A session driver that persists between requests.** `array` keeps nothing from the first request
  to the second.
- **`SESSION_SAME_SITE=lax`**, Laravel's default. `strict` withholds the session cookie on exactly this
  request, because the redirect back to your callback comes from Xero's site.
- **A full-page navigation** to the connect route — a link or `window.location`, not `fetch()` or an
  XHR. Its response is a redirect to Xero's sign-in page, which the user has to see.

Miss any of these and the callback cannot find its state, so it redirects with "The Xero authorisation
could not be verified, because the link expired or your session changed."

A single-page application that signs in through Sanctum's cookie-based session works as it is: set
`XERO_ROUTES_MIDDLEWARE=web,auth:sanctum`. An application that authenticates **only** with bearer
tokens has nothing for the returning browser to present, so a guard that reads only the token treats it
as a guest: these two routes need `web`, and a user signed in through that session.
[Recipes, section 8](06-recipes.md#8-connecting-from-an-spa-or-api-only-app) covers the round trip in
more detail.

### What happens, in order

1. `XeroConnectController` resolves the key (absent means the default connection) and calls
   `XeroConfig::assertReadyToConnect()`, which validates the client ID, the client secret, the redirect
   URI and `offline_access` **before** anyone leaves your application.
2. A random state value is stored in the session against that key, and the user is redirected to
   `https://login.xero.com/identity/connect/authorize`. No call is made to Xero at this point —
   `tests/Feature/ConnectRouteTest.php` asserts `Http::assertNothingSent()`.
3. Xero redirects back with `?code=...&state=...`. `XeroCallbackController` consumes the state first,
   whatever else happened, so it can never be replayed.
4. The code is exchanged at `https://identity.xero.com/connect/token` with HTTP Basic auth and a
   form-encoded body. If the response carries no refresh token — only possible when `offline_access` was
   not granted — the connection is refused with a message saying exactly that, rather than stored and
   left to die in 30 minutes.
5. `https://api.xero.com/connections` is read to find out which organisation was authorised.
6. The row is upserted with both tokens encrypted, and `XeroConnected` is dispatched, naming who
   completed the consent in `$event->actor` — `null` when nobody was signed in. The row is committed
   first, so a listener that throws cannot undo the connect: its exception is reported and logged, and
   the administrator still sees the success message.

### The `/connections` response

This is the one Xero response in the whole flow that uses camelCase keys and ISO dates. The Accounting
API, everywhere else in these docs, uses PascalCase and .NET dates such as `/Date(1439434356790)/`.

It returns a **bare JSON array**, not an object with a `Connections` key, and it lists **every** tenant
the token can reach — not only the one just authorised. Several `ORGANISATION` entries is the normal case
for a returning user.

```json
[
  {
    "id": "00000000-0000-0000-0000-000000000001",
    "authEventId": "00000000-0000-0000-0000-0000000000a1",
    "tenantId": "00000000-0000-0000-0000-0000000000b1",
    "tenantType": "ORGANISATION",
    "tenantName": "Example Sdn Bhd",
    "createdDateUtc": "2026-01-01T00:00:00",
    "updatedDateUtc": "2026-01-02T00:00:00"
  }
]
```

Three things about that payload are load-bearing:

- **`id` is not `tenantId`.** `id` is the *connection* id, and `DELETE /connections/{id}` takes it.
  Conflating the two makes per-organisation disconnect impossible, so both are stored —
  `connection_id` and `tenant_id` are separate columns.
- **`tenantName` is nullable.** Xero's own example has it null for a `PRACTICEMANAGER` tenant. Never
  interpolate it raw; use `XeroConnection::displayName()`, which falls back to the tenant id.
- **`tenantType` can be `ORGANISATION`, `PRACTICEMANAGER` or `PRACTICE`** (one word, no separator). Only
  `ORGANISATION` tenants work with the Accounting API, so the others are filtered out. If a user
  authorises only a practice account, the connect fails with a message telling them to choose an
  organisation.

When more than one organisation comes back, the package prefers one not already claimed by a *different*
key, then the most recently authorised, with the tenant id as a final deterministic tie-break, and logs
which it chose and what the alternatives were.

### Confirm it worked

```bash
php artisan xero-bridge:status
```

Exit codes are distinct so this can be wired to monitoring: `0` everything usable; `1` configuration
incomplete, the connections table missing, unreadable or not the package's own, or a connection whose
stored tokens the current `APP_KEY` cannot decrypt (shown as `unreadable` in the Token column); `2` at
least one connection needs re-authorising. `--json` emits a machine-readable dump, and a test asserts no
token ever appears in it. `--strict` also returns `1` for any warning, or a lock pre-flight that did not
pass — no webhook key, an `array` lock store or a `file` one left as the unchosen default (a `file` store
that `XERO_LOCK_STORE` names is only a note), recent transient failures, write-ledger
claims stuck pending, a package migration recorded twice, among others;
[Commands, errors and token lifecycle](05-commands-and-errors.md) lists every one. Warnings print even
when nothing is connected yet. The command never calls Xero: it reads the database, config and the package's migration files in
`database/migrations`, and takes
and releases one cache lock to prove the lock store works.

For a live round trip, read the organisation back:

```php
use Peoplelogy\XeroBridge\Facades\XeroBridge;

$organisation = XeroBridge::settings()->organisation();
```

**Response** — Xero returns the usual envelope, and `organisation()` hands you the first element of
`Organisations`:

```json
{
  "Id": "00000000-0000-0000-0000-0000000000c1",
  "Status": "OK",
  "ProviderName": "Example App",
  "DateTimeUTC": "/Date(1552404447003)/",
  "Organisations": [
    {
      "OrganisationID": "00000000-0000-0000-0000-0000000000b1",
      "Name": "Example Sdn Bhd",
      "LegalName": "Example Sdn Bhd",
      "PaysTax": true,
      "Version": "GLOBAL",
      "OrganisationType": "COMPANY",
      "BaseCurrency": "MYR",
      "CountryCode": "MY",
      "IsDemoCompany": false,
      "OrganisationStatus": "ACTIVE",
      "FinancialYearEndDay": 31,
      "FinancialYearEndMonth": 12,
      "SalesTaxBasis": "ACCRUALS",
      "DefaultSalesTax": "Tax Exclusive",
      "CreatedDateUTC": "/Date(1455827393000)/",
      "Timezone": "SINGAPORESTANDARDTIME",
      "Edition": "BUSINESS",
      "Class": "PREMIUM"
    }
  ]
}
```

`BaseCurrency` here is the check worth making: if it is not what `XERO_CURRENCY` says, every
invoice you create will be in a foreign currency to this organisation.

### Keep the connection alive

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('xero-bridge:refresh-tokens')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
```

> ### ⚠️ `withoutOverlapping()` and `onOneServer()` are not optional
>
> Xero rotates refresh tokens: using one invalidates it and returns a new one. Two runs at once each
> invalidate the other's token and the connection dies. The per-connection cache lock inside
> `TokenManager` is the second line of defence, not the first — and it is only as good as
> `XERO_LOCK_STORE`.

The command takes `--connection=`, `--window=` (seconds, default 1800) and `--force`. It exits `1` on a
transient failure with **nothing changed** and `2` when a human must reconnect. A 5xx or a timeout never
touches the stored tokens; only a genuine `invalid_grant` marks a connection invalidated, which fires
`ConnectionExpired` once and is cleared by reconnecting. `tests/Feature/CommandsTest.php` pins the exit
codes, that a transient failure changes nothing, and that `invalid_grant` marks the row rather than
deleting it; `tests/Unit/TokenManagerTest.php` pins the single `ConnectionExpired`.

Nothing in the token lifecycle deletes a connection row. Only two things do, both as Eloquent deletes that
your model observers see: the test console's **Forget connection**, and a reconnect under
`XERO_ON_TENANT_CONFLICT=rekey` with `XERO_ON_KEY_CONFLICT=replace` that moves a row onto a key another
organisation holds — the row that held it goes.

---

## The manager API

Everything below is on `XeroBridgeManager`, reached through the `XeroBridge` facade.

### connection()

Scopes every following call to one connected organisation.

```php
public function connection(?string $key = null): static
```

**Parameters**

| Name | Type | Default | Notes |
|---|---|---|---|
| `$key` | `?string` | `null` | The connection key. Null falls back to `XERO_DEFAULT_CONNECTION`. |

**Request**

```php
use Peoplelogy\XeroBridge\Facades\XeroBridge;

// The default connection.
$invoices = XeroBridge::invoices();

// A named one.
$acmeInvoices = XeroBridge::connection('acme')->invoices();
```

**Returns** — a **clone** of the manager, scoped to `$key`. No HTTP request is made.

**Notes / gotchas**

> ### ⚠️ It returns a clone, and that is the point
>
> The manager is a container singleton behind a facade. A mutating setter would leak the key into every
> later call in the same request — a cross-tenant data bug of exactly the kind this package exists to
> prevent. With a clone, `XeroBridge::connection('acme')->invoices()` is scoped to that expression alone
> and `XeroBridge::invoices()` always means the default.
>
> `tests/Unit/ContactsPaymentsSettingsTest.php` pins it: after `XeroBridge::connection('acme')->key()`
> returns `'acme'`, `XeroBridge::key()` still returns `'default'`.

Because it is a clone, you cannot set a connection once at the top of a job and forget about it. Hold the
scoped instance in a variable instead:

```php
$xero = XeroBridge::connection('acme');

$contact = $xero->contacts()->findByEmail('finance@example.test');
$invoice = $xero->invoices()->create($payload);
```

The underlying HTTP client is memoised per key in a singleton registry, so holding the variable and
calling `XeroBridge::connection('acme')` again both reach the same client — the clone is cheap.

An unknown key is not an error here. It fails later, at the first call that needs a token, with
`XeroConnectionNotFoundException`, whose message carries the connect URL for that key.

### key()

The connection key this manager instance resolves to.

```php
public function key(): string
```

**Request**

```php
use Peoplelogy\XeroBridge\Facades\XeroBridge;

XeroBridge::key();                       // 'default'
XeroBridge::connection('acme')->key();   // 'acme'
```

**Returns** — the scoped key, or `XERO_DEFAULT_CONNECTION` when none is set. No HTTP request is made.

### connectUrl()

The URL an administrator must visit to authorise or re-authorise a connection.

```php
public function connectUrl(?string $key = null): string
```

**Parameters**

| Name | Type | Default | Notes |
|---|---|---|---|
| `$key` | `?string` | `null` | Null means this instance's key. |

**Request**

```php
use Peoplelogy\XeroBridge\Facades\XeroBridge;

$url = XeroBridge::connectUrl();        // https://example.com/xero/connect/default
$acme = XeroBridge::connectUrl('acme'); // https://example.com/xero/connect/acme
```

**Returns** — an absolute URL when the named route is registered; otherwise the plain path
`/{prefix}/connect/{key}`, so the guidance stays actionable even with `XERO_ROUTES_ENABLED=false`.

**Notes / gotchas**

This is the string to put in an alert when `ConnectionExpired` fires, and it is already embedded in
`XeroReauthorizationRequiredException` and in `xero-bridge:status` output. You rarely need to build it
yourself.

### connections()

Every stored connection, for a settings screen or a health check.

```php
/** @return \Illuminate\Support\Collection<int, \Peoplelogy\XeroBridge\Models\XeroConnection> */
public function connections(): Collection
```

**Request**

```php
use Peoplelogy\XeroBridge\Facades\XeroBridge;

$rows = XeroBridge::connections()->map(fn ($connection) => [
    'key' => $connection->key,
    'organisation' => $connection->displayName(),
    'expires_at' => $connection->expires_at?->toIso8601String(),
    'needs_reconnect' => $connection->isInvalidated(),
]);
```

**Returns** — a collection of `XeroConnection` models ordered by `key`. No HTTP request is made; this
reads the database only.

**Notes / gotchas**

> ### ⚠️ Never log or serialise a `XeroConnection` yourself
>
> `access_token` and `refresh_token` use the `encrypted` cast, which protects them **at rest only**. Once
> the model is hydrated, an activity log, `toArray()`, a JSON response, an Inertia prop or
> `Log::info($model)` all emit the **decrypted** value.
>
> Both columns are in `$hidden`, which keeps them out of `toArray()` and JSON, and
> `tests/Unit/XeroConnectionTest.php` asserts it stays that way. It does not stop PHP serialisation: a
> queued listener for an event that carries the model writes the token ciphertext into the job payload —
> see [Webhooks and events](04-webhooks-and-events.md). Do not add an activity-log trait to this
> model, and do not remove `$hidden`.

Useful methods on each row: `displayName()`, `isExpired(int $leeway = 0)`, `expiresWithin(int $seconds)`,
`isInvalidated()`, `isUsable()`, `scopeList()`, `hasScope(string $scope)`. Query scopes: `forKey()`,
`forTenant()`, `usable()`, `expiringWithin()`.

A null `expires_at` counts as **expired** — the package cannot prove the token is good, and assuming it
is would send a doomed request to Xero.

### defaults()

The defaults in force for this connection: the invoice defaults, and a default account to record
payments against.

```php
public function defaults(): ConnectionDefaults
```

**Request**

```php
use Peoplelogy\XeroBridge\Facades\XeroBridge;

$defaults = XeroBridge::connection('acme')->defaults();

$defaults->accountCode();                 // '4000', from the acme block below; null when nothing sets one
$defaults->taxType();                     // null unless XERO_TAX_TYPE is set
$defaults->currency();                    // 'MYR'
$defaults->brandingThemeId();             // null unless configured
$defaults->get('payment_account_code');   // '090', from the acme block below
$defaults->all();                         // the raw array
```

**Returns** — a `ConnectionDefaults` value object. No HTTP request is made.

**Notes / gotchas**

A named connection inherits the `default` block and overrides only the keys it declares; an explicit key
wins **even when set to null**. A key with no config block at all is not an error — it inherits
everything.

```php
// config/xero-bridge.php
'connections' => [
    'default' => [
        'account_code' => env('XERO_ACCOUNT_CODE'),
        'tax_type' => env('XERO_TAX_TYPE'),
        'currency' => env('XERO_CURRENCY', 'MYR'),
        'branding_theme_id' => env('XERO_BRANDING_THEME_ID'),
    ],

    'acme' => [
        'account_code' => '4000',          // currency still inherits 'MYR'
        'payment_account_code' => '090',   // not shipped; read by payments()->createForInvoice()
    ],
],
```

There is **no fallback account code**. The `'200'` that releases before 1.1.0 shipped was Xero's
demo-company sales account and meaningless anywhere else, so it was removed: with neither
`XERO_ACCOUNT_CODE` nor a block value, `accountCode()` is `null` and the package sends no `AccountCode`.

These are the keys the package applies, and where:

| Key | Applied by |
|---|---|
| `account_code`, `tax_type` | `invoices()->create()` and `createMany()`: `LineItems[].AccountCode` and `TaxType`, only on a line that has none of its own (a line carrying `TaxAmount` gets no `TaxType` either) |
| `currency`, `branding_theme_id` | The same two calls: `CurrencyCode` and `BrandingThemeID`, only when the invoice has none |
| `payment_account_code` | `payments()->createForInvoice()`, when you pass no account code. It is not in the shipped config: add it to a connection block or pass it through `withDefaults()`. With neither, `createForInvoice()` throws `InvalidArgumentException`. |

Any other key in a block is carried — `get()` and `all()` return it — but nothing in the package
applies it.

> ### ⚠️ Plain calls read the connection defaults once per process
>
> `defaults()` reads the configuration every time it is called. The resources do not:
> `XeroBridge::invoices()` and `payments()` are built once per connection and kept for the life of the
> process — a queue worker, or every request under Octane — with the defaults of that moment built in.
> A change made at runtime, such as `config()->set()` in a long-running worker or a test, reaches
> `defaults()` and anything called through `withDefaults()`, but not a plain call on a connection this
> process has already used. Pass the value per call, or use `withDefaults()`, which builds a fresh
> resource every time.

### withDefaults()

Overrides the connection defaults for one expression, without touching config.

```php
/** @param array<string, mixed> $overrides */
public function withDefaults(array $overrides): static
```

**Parameters**

| Name | Type | Default | Notes |
|---|---|---|---|
| `$overrides` | `array<string, mixed>` | — | The keys the package applies are `account_code`, `tax_type`, `currency`, `branding_theme_id` and `payment_account_code` — see [defaults()](#defaults). |

**Request**

```php
use Peoplelogy\XeroBridge\Facades\XeroBridge;

$invoice = XeroBridge::withDefaults(['account_code' => '4000'])
    ->invoices()
    ->create($payload);
```

**Returns** — another clone, with the overrides layered on top of the connection's configured defaults.

**Notes / gotchas**

Chainable with `connection()`, in either order. Like `connection()`, the scoping lives on the returned
instance only.

An override never touches the resource that plain calls share. A manager carrying overrides builds a
fresh resource each time you ask it for one, so the override applies to that expression — or to every
resource taken from a manager you hold in a variable — even when the plain `invoices()` or `payments()`
was resolved first, and it never reaches a later plain call. `tests/Unit/WithDefaultsTest.php` pins both
directions, for invoices and payments.

### redirectAfterConnectUsing()

Registers a closure that decides where the OAuth callback sends the user, when a config value is not
expressive enough — a destination that depends on which connection was made, say.

```php
public static function redirectAfterConnectUsing(Closure $callback): void
```

**Parameters**

| Name | Type | Default | Notes |
|---|---|---|---|
| `$callback` | `Closure(?XeroConnection): ?string` | — | Receives the stored `XeroConnection` after a successful connect, and `null` after a failed one. Returns the URL to send the user to, or `null` or `''` to leave it to the configured destination. |

**Request** — register it once, in a service provider's `boot()`:

```php
// app/Providers/AppServiceProvider.php
use Peoplelogy\XeroBridge\Models\XeroConnection;
use Peoplelogy\XeroBridge\XeroBridgeManager;

public function boot(): void
{
    XeroBridgeManager::redirectAfterConnectUsing(
        fn (?XeroConnection $connection) => $connection === null
            ? null // the connect failed: let the configured destination decide
            : route('integrations.xero.show', $connection->key),
    );
}
```

**Returns** — nothing. The closure is held in a static property for the life of the process, and
registering another replaces it.

**Notes / gotchas**

The callback consults it **first**, on success and on failure alike, and has since 1.0.1 — earlier
releases stored the closure and never called it. The full order, highest first:

1. the closure;
2. a validated `return_to`, only when `XERO_ALLOW_RETURN_TO` is on;
3. `XERO_AFTER_CONNECT_ROUTE`, if that route is registered and its URL can be built;
4. `XERO_AFTER_CONNECT_REDIRECT`, which defaults to `/`.

Anything the closure returns other than a non-empty string passes the decision down the list. A
closure that throws counts as one that returned nothing: the exception goes to `report()`, the package
logs an error, and the rest of the order decides, so the administrator still lands somewhere with the
message the outcome earned. `tests/Feature/AfterConnectRedirectTest.php` pins each of these cases.

It exists as a hook rather than a config key because `php artisan config:cache` cannot serialise a
closure.

Whatever the destination, the callback flashes a message to the session under both `xero-bridge.status`
and `status` on success, and both `xero-bridge.error` and `error` on failure — the unprefixed keys mean
Breeze and Jetstream layouts display it without you wiring anything up. Failures are always a redirect
with a readable message, never a 500: the person looking at the screen is an administrator connecting an
accounting system, not a developer. The same holds for what you add to that request: a `XeroConnected`
listener or this closure that throws, or a `XERO_AFTER_CONNECT_ROUTE` whose URL cannot be built, is
reported and logged, and cannot turn a stored connection into an error page.

---

## Multiple organisations

Each connected organisation gets a short key. `/xero/connect` uses `default`; `/xero/connect/acme` uses
`acme`. The key is stored in the session-side OAuth state entry, never in the callback URL — Xero allows
one registered `redirect_uri`, and a key travelling in a query string would be attacker-settable.

```php
use Peoplelogy\XeroBridge\Facades\XeroBridge;

XeroBridge::invoices()->create($invoice);                     // the default connection
XeroBridge::connection('acme')->invoices()->create($invoice); // a named one
```

Two uniqueness rules apply, and both are enforced by unique indexes:

- one row per **key**
- one row per **tenant**, so the same Xero organisation cannot be connected twice under two keys

The four collision cases a reconnect can produce are resolved explicitly inside one transaction with both
candidate rows locked — a double-clicked callback is a real race — according to `XERO_ON_KEY_CONFLICT`
and `XERO_ON_TENANT_CONFLICT` above.

A successful reconnect clears `invalidated_at`, `invalidated_reason`, `last_failure_at` and
`failure_count`, so recovering from an expired connection needs no manual cleanup.

> ### ⚠️ Connection limits
>
> Xero caps how many connections an application may hold by developer-account **tier**, not by
> certification — a new app starts on the starter tier — and separately limits each organisation to two
> **uncertified** apps. An internal business system will not be certified, because that means a public
> app-store listing, and a connection over the limit is **refused**, not degraded.
>
> Check the numbers for your plan against Xero's
> [API limits](https://developer.xero.com/documentation/guides/oauth2/limits/) before onboarding another
> system, and keep a record of which applications hold the allowance. Xero's own pages have disagreed on
> the exact figures, so confirm in the Xero console rather than trusting a number written down anywhere.

---

## Table prefixes

Every migration the package ships creates its table with a **bare** name and resolves the schema
builder through the configured connection. The connections migration, abridged:

```php
// database/migrations/create_xero_connections_table.php.stub
public function up(): void
{
    if ($this->schema()->hasTable($this->table())) {
        if (($missing = $this->missingColumns()) !== []) {
            $this->refuse($missing); // a table this migration did not create
        }

        return; // already ours: nothing to do
    }

    $this->schema()->create($this->table(), function (Blueprint $table) {
        // ...
    });
}

private function schema(): Builder
{
    return Schema::connection(config('xero-bridge.database.connection'));
}

private function table(): string
{
    return config('xero-bridge.database.table', 'xero_connections');
}
```

So whatever prefix your database connection sets is applied automatically. A connection with
`'prefix' => 'app_'` and `'prefix_indexes' => true` gets `app_xero_connections` — and
`app_xero_write_records`, `app_xero_webhook_events` and `app_xero_api_calls` — with no configuration in
this package at all.

**Do not put a prefix in `XERO_DB_TABLE`**, or in any other table variable. Setting it to
`app_xero_connections` on a connection that already prefixes produces `app_app_xero_connections`.

Indexes are deliberately left unnamed for the same reason: hand-naming them would defeat
`prefix_indexes`.

A refusal names the **physical** table, prefix included, while the variable it asks you to set takes the
bare name. The message is a single line, wrapped here, and it begins:

```text
Table "app_xero_connections" on database connection "mysql" already exists, but it is not the table
this migration creates (missing columns: "key", "invalidated_at", "invalidated_reason" and 12 more).
Nothing was changed. To keep that table, give the package another one: set XERO_DB_TABLE (config key
xero-bridge.database.table) to an unused, bare table name -- the connection adds its own prefix -- ...
```

`XeroConnection` reads the same two config values in its constructor, so a custom table or connection is
picked up by the model as well as the migration; the package's other models do the same with their own
table keys. `tests/Unit/TablePrefixTest.php` runs the real migrations against a prefixed connection and
proves three things: `Schema::hasTable('xero_connections')` is true through the prefixed connection
while the physical table carries the prefix; reads and writes go through the prefixed connection; and
`XERO_DB_TABLE` is honoured.

---

## Anything the package has not wrapped

Any endpoint without a resource wrapper is still reachable, with the token and the `Xero-tenant-id`
header applied for you.

```php
use Peoplelogy\XeroBridge\Facades\XeroBridge;

// Relative path: resolved against https://api.xero.com/api.xro/2.0
$creditNotes = XeroBridge::request('GET', 'CreditNotes', [], [
    'If-Modified-Since' => '2026-01-01T00:00:00',
]);

// Absolute URL: reaches a different Xero API entirely.
$employees = XeroBridge::request('GET', 'https://api.xero.com/payroll.xro/2.0/Employees');

// The undecoded Response: status, headers and body (still requested as JSON; for an invoice
// PDF use invoices()->pdf($id)).
$response = XeroBridge::raw('GET', 'Reports/BalanceSheet');
```

`request()` returns the decoded array (an empty array for a `204`); `raw()` returns
`Illuminate\Http\Client\Response`. `tests/Unit/GenericRequestTest.php` pins the absolute-URL and
relative-path routing; `tests/Unit/XeroHttpClientTest.php` pins the `204`. `raw()` has no test of its own
yet.

Passing an absolute URL bypasses the Accounting base URL while still attaching the bearer token and
tenant header, which is how Payroll (`payroll.xro`), Files (`files.xro`), Projects (`projects.xro`),
Assets (`assets.xro`) and Bank Feeds are reached. **Each needs its own scope**, and scopes are fixed at
authorisation time, so add the scope and deploy before asking anyone to reconnect.

---

## Testing your integration

Your test suite should never reach Xero. Three things make sure of it:

- **`Http::preventStrayRequests()`**, worth adding even if you fake everything: it turns an unstubbed
  request into a loud failure instead of a live call to Xero from CI.
- **A stored connection row with a future `expires_at`**, or the first call tries to refresh the token
  and your fakes do not cover the identity endpoint — plus an `APP_KEY`, for the encrypted token columns.
- **`Http::fake()` patterns that name the API path and end in `*`**, such as
  `api.xero.com/api.xro/2.0/Invoices*`. Laravel matches the whole URL, so without the `*` a pattern
  misses every filtered read, which carries a query string, and every lookup by id.

The complete harness — the connection row, the stubs, faking the email endpoint — is in
[Recipes, section 6](06-recipes.md#6-testing-a-consuming-application), the one place it is kept.

---

## Uninstalling

Removing the package is `composer remove peoplelogy/laravel-xero-bridge`, plus tidying the
`repositories` entry you added above. If you had already run `xero-bridge:install` and `migrate` there
is more to it: the package's tables, its published migrations and config — and, before any of that,
disconnecting from Xero, because deleting your stored tokens does not revoke anything on Xero's side.

The full procedure, including the disconnect endpoints, is in the
[README's Uninstalling section](../README.md#uninstalling). It is kept in one place deliberately, so
the two cannot drift.

## Where next

- [Invoices](02-invoices.md) — every method on `invoices()`, the `InvoiceFilter` builder, the safety
  guards, paging
- [Contacts, payments and settings](03-contacts-payments-settings.md) — start here before invoices:
  account codes and tax types must be discovered per organisation
- [Webhooks and events](04-webhooks-and-events.md)
- [Commands, errors and token lifecycle](05-commands-and-errors.md)
- [Recipes](06-recipes.md)
- [The test console](07-test-console.md) — off until `XERO_CONSOLE_ENABLED=true`; who can reach it once it
  is on, and the write guard
- [Persistence](09-persistence.md) — the write ledger and webhook replay dedupe
