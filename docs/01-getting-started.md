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
- storing credentials, with both tokens encrypted at rest and hidden from serialisation
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
> Laravel 11 stopped receiving security fixes on 12 March 2026. Three advisories affect the whole 11.x
> line — including [CVE-2026-48019](https://github.com/advisories/GHSA-5vg9-5847-vvmq), a high-severity
> CRLF injection in the default email validation rule — and they are fixed only in 12.60.0 / 12.61.1 /
> 13.10.0 / 13.12.0. They will not be backported, so **Composer refuses to install Laravel 11 unless you
> disable its advisory policy.**
>
> The package installs and its tests pass on Laravel 11, and CI keeps running those legs so the
> compatibility claim is real — but they are non-blocking. If you are on Laravel 11, upgrade to 12 or 13.
> That is a security fix for your application, not a requirement of this package.

---

## Installation

### 1. Add the repository

The package is not on Packagist, so Composer has to be told where to find it. Add to the consuming
application's `composer.json`:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/it-peoplelogy/laravel-xero-bridge.git"
    }
]
```

`repositories` is a top-level key, sitting alongside `require` and `require-dev`.

The repository is public, so **no authentication is required** — no SSH key, no token, no deploy key,
and nothing to configure on a build server. Composer reads it anonymously.

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

`xero-bridge:install` publishes `config/xero-bridge.php` and the `xero_connections` migration, then
prints every `.env` key you need, the exact redirect URI to register on the Xero app, the connect URL,
and your webhook URL. Pass `--force` to overwrite files that already exist.

It also warns when `APP_URL` is not `https`, because Xero only delivers webhooks to https on port 443.
`tests/Feature/CommandsTest.php` asserts both the key list and that warning.

If you would rather publish by hand, the tags are `xero-bridge-config` and `xero-bridge-migrations`.

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
| `accounting.settings` | Accounts, TaxRates, Organisation, BrandingThemes — the `settings()` resource |
| `accounting.contacts` | Contacts |
| `accounting.invoices` | Invoices, CreditNotes, Quotes, Items |
| `accounting.payments` | Payments, Overpayments, Prepayments |
| `accounting.attachments` | Attachments |
| `openid profile email` | Only if you want the authorising user's identity |

`XeroConfig::assertOfflineAccess()` refuses to start the flow without `offline_access`, so a missing
refresh token fails at `/xero/connect` rather than 30 minutes after a successful-looking connect.

> ### ⚠️ Scopes are fixed at authorisation time
>
> Scopes are additive and cannot be removed from an existing token without revoking it, and a connection
> made *before* you added a scope does not gain it. **Deploy the scope change before asking anyone to
> reconnect**, or they will have to connect twice.
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

Every key in `config/xero-bridge.php`, and what goes wrong if it is missing or wrong.

The three credentials deliberately have **no default**. A wrong-but-present default turns a
misconfiguration into an opaque 401 from Xero hours later; a missing value throws
`XeroConfigurationException` at the point of use with a message naming the env key. There is a test
asserting the config file contains `env('XERO_CLIENT_ID')` and never `env('XERO_CLIENT_ID', ...)`.

### Credentials and connection

| Key | Default | What breaks if it is wrong |
|---|---|---|
| `XERO_CLIENT_ID` | none | Empty: `XeroConfigurationException` naming `XERO_CLIENT_ID` at `/xero/connect`. Wrong: Xero returns `unauthorized_client`, which the callback reports as "This application is not authorised by Xero." |
| `XERO_CLIENT_SECRET` | none | Empty: `XeroConfigurationException` at token exchange. Wrong: the token request fails `invalid_client` — which looks nothing like an expired connection, so do not go hunting for one. |
| `XERO_REDIRECT_URI` | callback route URL | Not an exact match for one registered on the app: Xero refuses before your callback ever runs. `127.0.0.1` or plain http: `XeroConfigurationException` at `/xero/connect`. |
| `XERO_SCOPES` | the granular set above | Missing `offline_access`: `XeroConfigurationException` at `/xero/connect`, and `xero-bridge:status` exits 1. A scope you never requested: `XeroScopeException` at the call site — refreshing will not help, the user must reconnect. |
| `XERO_DEFAULT_CONNECTION` | `default` | Changing it after connections exist orphans them: `XeroBridge::invoices()` then resolves a key nothing is stored under, and the first call needing a token throws `XeroConnectionNotFoundException`. |

### Routes

| Key | Default | What breaks if it is wrong |
|---|---|---|
| `XERO_ROUTES_ENABLED` | `true` | False removes `/xero/connect` and `/xero/callback`. `php artisan route:cache` bakes in whatever this was **at cache time**, so a `.env` change without `route:clear` does nothing. |
| `XERO_ROUTES_PREFIX` | `xero` | Changes the connect, callback **and webhook** paths. Changing it after the webhook URL is registered with Xero silently breaks delivery. |
| `XERO_ROUTES_NAME_PREFIX` | `xero-bridge.` | Route names become `<prefix>connect`, `<prefix>callback`, `<prefix>webhook`. Change it and any `route('xero-bridge.connect')` in your own code breaks. |
| `XERO_ROUTES_MIDDLEWARE` | `web,auth` | Comma separated, parsed into an array. **The flow needs a session** — that is where the OAuth state lives. Remove `web` (or anything else that starts a session) and every callback fails with "the link expired or your session changed". Add authorisation here, e.g. `web,auth,can:manage-xero`. |
| `XERO_AFTER_CONNECT_ROUTE` | none | A named route to redirect to after a successful connect. Ignored if the name is not registered. |
| `XERO_AFTER_CONNECT_REDIRECT` | `/` | The raw path used when no named route is set. |
| `XERO_ALLOW_RETURN_TO` | `false` | Honours `?return_to=` on the connect route. Off by default: an unvalidated redirect target on an authenticated route is an open redirect. When on, only same-host absolute paths are accepted — `//evil.test` is rejected as protocol-relative. |

### Webhooks

| Key | Default | What breaks if it is wrong |
|---|---|---|
| `XERO_WEBHOOK_KEY` | none | Unset or wrong: **every** webhook is rejected with a 401 and the signature check fails closed. `xero-bridge:status` warns when it is unset. |
| `XERO_WEBHOOKS_ENABLED` | `true` | False unregisters the webhook route entirely; Xero's deliveries 404 and the subscription is disabled after 24 hours of failures. |
| `XERO_WEBHOOK_PATH` | `webhook` | Appended to the route prefix. Changing it after registering the URL with Xero breaks delivery. |
| `XERO_WEBHOOK_QUEUE` | default queue | The queue the envelope job is pushed onto. |
| `XERO_WEBHOOK_QUEUE_CONNECTION` | default connection | Set this to something durable. On `sync` the controller processes inline and can blow Xero's 5-second budget. |

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
| `XERO_LOCK_STORE` | default cache store | **Set this.** The refresh is wrapped in a per-connection cache lock, and `array` and `file` give no cross-process guarantee — two workers then refresh concurrently and each invalidates the other's rotating refresh token, killing the connection. Use redis, memcached or database. `xero-bridge:status` warns only when this is unset **and** `cache.default` is `array` or `file` — naming a bad store explicitly buys silence, not a lock. |
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
| `XERO_DB_CONNECTION` | default connection | Which database connection holds `xero_connections`. Point it somewhere the migration has not run and every call throws a `QueryException`. |
| `XERO_DB_TABLE` | `xero_connections` | A **bare** name — see [Table prefixes](#table-prefixes). |
| `XERO_ON_KEY_CONFLICT` | `replace` | The key exists but points at a different organisation. `replace` treats the key as an app-side slot and repoints it; `error` refuses with `ConnectionKeyConflictException` and tells the user to disconnect the old organisation first. |
| `XERO_ON_TENANT_CONFLICT` | `error` | This organisation is already connected under a different key. `error` refuses with `TenantAlreadyConnectedException`; `rekey` moves the existing row to the new key. The default is `error` because silently re-keying would break every caller that already references the old key. |

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
XERO_SCOPES="openid profile email offline_access accounting.invoices accounting.payments accounting.contacts accounting.settings accounting.attachments"

# From the Xero app's Webhooks tab. Leave blank to reject all webhooks.
XERO_WEBHOOK_KEY=

# A cache store that locks ACROSS PROCESSES. array and file do not.
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
6. The row is upserted with both tokens encrypted, and `XeroConnected` is dispatched.

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

Exit codes are distinct so this can be wired to monitoring: `0` everything usable, `1` configuration
incomplete, `2` at least one connection needs re-authorising. `--json` emits a machine-readable dump, and
a test asserts no token ever appears in it. `--strict` also returns `1` for warnings — no webhook key, no
cross-process lock store, recent transient failures. The command never calls Xero.

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
`ConnectionExpired` once and is cleared by reconnecting. Nothing in this package ever deletes a
connection row. `tests/Feature/CommandsTest.php` pins all of that.

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
> Both columns are in `$hidden`, which closes the serialisation half of that hole, and
> `tests/Unit/XeroConnectionTest.php` asserts it stays closed. Do not add an activity-log trait to this
> model, and do not remove `$hidden`.

Useful methods on each row: `displayName()`, `isExpired(int $leeway = 0)`, `expiresWithin(int $seconds)`,
`isInvalidated()`, `isUsable()`, `scopeList()`, `hasScope(string $scope)`. Query scopes: `forKey()`,
`forTenant()`, `usable()`, `expiringWithin()`.

A null `expires_at` counts as **expired** — the package cannot prove the token is good, and assuming it
is would send a doomed request to Xero.

### defaults()

The invoice defaults in force for this connection.

```php
public function defaults(): ConnectionDefaults
```

**Request**

```php
use Peoplelogy\XeroBridge\Facades\XeroBridge;

$defaults = XeroBridge::connection('acme')->defaults();

$defaults->accountCode();       // '4000' if the acme block sets it, else '200'
$defaults->taxType();           // null unless XERO_TAX_TYPE is set
$defaults->currency();          // 'MYR'
$defaults->brandingThemeId();   // null unless configured
$defaults->all();               // the raw array
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
        'account_code' => '4000',   // currency still inherits 'MYR'
    ],
],
```

### withDefaults()

Overrides the invoice defaults for one expression, without touching config.

```php
/** @param array<string, mixed> $overrides */
public function withDefaults(array $overrides): static
```

**Parameters**

| Name | Type | Default | Notes |
|---|---|---|---|
| `$overrides` | `array<string, mixed>` | — | Keys are `account_code`, `tax_type`, `currency`, `branding_theme_id`. |

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

### redirectAfterConnectUsing()

Registers a closure for where the OAuth callback should send the user, when a config value is not
expressive enough. **Not consulted yet — see the warning below.**

```php
public static function redirectAfterConnectUsing(Closure $callback): void
```

**Request** — register it in a service provider's `boot()`:

```php
use Peoplelogy\XeroBridge\XeroBridgeManager;

XeroBridgeManager::redirectAfterConnectUsing(
    fn () => route('settings.integrations')
);
```

**Returns** — nothing.

**Notes / gotchas**

> ### ⚠️ The closure is stored but never read
>
> `XeroCallbackController::destination()` does not call `XeroBridgeManager::afterConnectCallback()`, and
> nothing else in `src/` or `tests/` does either, so registering a closure currently has no effect. Use
> `XERO_AFTER_CONNECT_ROUTE` until that is wired up.

It exists as a hook rather than a config key because `php artisan config:cache` cannot serialise a
closure. The precedence the callback actually applies is: a validated `return_to` (only when
`XERO_ALLOW_RETURN_TO` is on), then `XERO_AFTER_CONNECT_ROUTE` if that route name is registered, then
`XERO_AFTER_CONNECT_REDIRECT`, which defaults to `/`.

Whatever the destination, the callback flashes a message to the session under both `xero-bridge.status`
and `status` on success, and both `xero-bridge.error` and `error` on failure — the unprefixed keys mean
Breeze and Jetstream layouts display it without you wiring anything up. Failures are always a redirect
with a readable message, never a 500: the person looking at the screen is an administrator connecting an
accounting system, not a developer.

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

The migration creates the table with a **bare** name and resolves the schema builder through the
configured connection:

```php
// database/migrations/create_xero_connections_table.php.stub
$this->schema()->create($this->table(), function (Blueprint $table) {
    // ...
});

private function schema(): Builder
{
    return Schema::connection(config('xero-bridge.database.connection'));
}

private function table(): string
{
    return config('xero-bridge.database.table', 'xero_connections');
}
```

So whatever prefix your database connection sets is applied automatically. PIPS sets
`'prefix' => 'pips_'` with `'prefix_indexes' => true` on its `mysql` connection, and the table there
becomes `pips_xero_connections` with no configuration in this package at all.

**Do not put a prefix in `XERO_DB_TABLE`.** Setting it to `pips_xero_connections` on a connection that
already prefixes produces `pips_pips_xero_connections`.

Indexes are deliberately left unnamed for the same reason: hand-naming them would defeat
`prefix_indexes`.

`XeroConnection` reads the same two config values in its constructor, so a custom table or connection is
picked up by the model as well as the migration. `tests/Unit/TablePrefixTest.php` runs the real migration
against a prefixed connection and proves three things: `Schema::hasTable('xero_connections')` is true
through the prefixed connection while the physical table is `pips_xero_connections`; reads and writes go
through the prefixed connection; and `XERO_DB_TABLE` is honoured.

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

// The raw response, for PDFs and other non-JSON bodies.
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

Your test suite should never reach Xero:

```php
use Illuminate\Support\Facades\Http;
use Peoplelogy\XeroBridge\Models\XeroConnection;

Http::preventStrayRequests();

Http::fake([
    // Trailing * matters: Laravel matches the WHOLE url, and any filtered
    // read carries a query string.
    'api.xero.com/api.xro/2.0/Invoices*' => Http::response([
        'Invoices' => [[
            'InvoiceID' => '00000000-0000-0000-0000-0000000000d1',
            'InvoiceNumber' => 'INV-0001',
            'Status' => 'DRAFT',
        ]],
    ]),
]);

XeroConnection::create([
    'key' => 'default',
    'tenant_id' => '00000000-0000-0000-0000-0000000000b1',
    'access_token' => 'placeholder-access-token',
    'refresh_token' => 'placeholder-refresh-token',
    'expires_at' => now()->addMinutes(30),
    'scopes' => 'offline_access accounting.invoices',
]);
```

You need an `APP_KEY` for the encrypted casts to work, and a connection row with a future `expires_at` —
otherwise the first call tries to refresh and your fake will not cover the identity endpoint.

`Http::preventStrayRequests()` is worth adding even if you fake everything: it turns an unstubbed request
into a loud failure instead of a live call to Xero from CI.

---

## Uninstalling

Removing the package is `composer remove peoplelogy/laravel-xero-bridge`, plus tidying the
`repositories` entry you added above. If you had already run
`xero-bridge:install` and `migrate` there is more to it — in particular, **roll the migration back
before removing the package**, and disconnect from Xero before dropping the table, because deleting
your stored tokens does not revoke anything on Xero's side.

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
