# Laravel Xero Bridge

A thin, tested Laravel wrapper around the [Xero Accounting API](https://developer.xero.com/documentation/api/accounting/overview),
built on Laravel's own HTTP client.

It handles connecting to Xero and keeping the connection alive, storing credentials encrypted, rate
limits, retries, error handling and duplicate prevention. It does **not** contain any bookkeeping logic:
your application decides when to invoice and what to put on the invoice.

Not covered: Payroll, Files, Projects, Assets. Anything the package has not wrapped is still reachable
through `XeroBridge::request()`.

---

## Support matrix

| | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|
| **PHP 8.2** | best-effort | supported | — Laravel 13 needs PHP 8.3+ |
| **PHP 8.3** | best-effort | supported | supported |
| **PHP 8.4** | best-effort | supported | supported |

Every cell except the impossible one runs in CI on every push.

> ### ⚠️ Laravel 11 is End of Life
>
> Laravel 11 stopped receiving **security** fixes on **12 March 2026**. Three advisories affect the
> entire 11.x line — including [CVE-2026-48019](https://github.com/advisories/GHSA-5vg9-5847-vvmq), a
> high-severity CRLF injection in the default email validation rule — and they are fixed only in
> 12.60.0 / 12.61.1 / 13.10.0 / 13.12.0. They will not be backported, so **Composer refuses to install
> Laravel 11 unless you disable its advisory policy.**
>
> The package still installs and its tests still pass on Laravel 11, and CI keeps running those legs so
> the compatibility claim is real — but they are non-blocking, and the version is supported on a
> best-effort basis only.
>
> **If you are on Laravel 11, upgrade to 12 or 13.** That is a security fix for your application, not a
> requirement of this package. Laravel documents an 11 → 12 upgrade as typically a day or less, and
> describes 12 → 13 as a minor upgrade for most applications.

---

## Installation

### 1. Add the repository

This package is private and is not on Packagist, so Composer has to be told where to find it. Add to the
consuming application's `composer.json`:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "git@github.com:it-peoplelogy/laravel-xero-bridge.git"
    }
]
```

> **Note for PIPS:** `pips/composer.json` currently has no `repositories` key at all. This block has to
> be added.

### 2. Authentication

Two supported routes.

**SSH** — for developers and for CI runners with a deploy key. Use the `git@github.com:` URL above;
Composer shells out to `git` and uses your agent. Nothing to store.

**HTTPS token** — for Docker builds and deploy pipelines. Use the `https://github.com/...` URL and supply
a token:

```bash
COMPOSER_AUTH='{"github-oauth":{"github.com":"ghp_your_token"}}' composer install
```

or an `auth.json` beside `composer.json`. **`auth.json` must be gitignored.** Use a fine-grained personal
access token scoped to this one repository with read-only Contents — not a classic `repo` token, which
grants access to the whole organisation.

### 3. Require a tagged version

```bash
composer require peoplelogy/laravel-xero-bridge:^1.0
```

> ### ⚠️ Tagged releases are mandatory
>
> PIPS sets `"minimum-stability": "stable"`. Against a `vcs` repository Composer derives versions from
> **git tags**, so an untagged package resolves only to `dev-main`, which is `dev` stability and will be
> **refused**. Tag before the first `composer require`, not after the first failure.
>
> If you need an unreleased commit, cut a pre-release tag (`v1.1.0-beta.1`) and require `^1.1@beta`.
> That scopes the relaxation to one package, instead of loosening `minimum-stability` application-wide.

### 4. Publish and migrate

```bash
php artisan xero-bridge:install
php artisan migrate
```

`xero-bridge:install` publishes the config and migration and prints every `.env` key you need, the exact
redirect URI to register, and your webhook URL.

The migration creates a table called `xero_connections` using a **bare** name, so whatever prefix your
database connection sets is applied automatically — in PIPS it becomes `pips_xero_connections`. Do not
add a prefix yourself.

### 5. Working on the package and an application together

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

## Create your own Xero app

Each environment should have its **own** Xero app. Go to
[developer.xero.com/myapps](https://developer.xero.com/myapps) and:

1. Sign in with a Xero account that has access to your organisation.
2. **Add App**, and choose **Web app** — not a Custom Connection, which is a client-credentials grant
   tied to a single organisation and does not fit this flow.
3. Give it a company URL and a redirect URI (below).
4. Copy the **Client ID**, then generate a **Client Secret**.

> The client secret is displayed **once**. If you lose it you must generate a new one, and the old one
> stops working immediately.

### Redirect URIs

The `redirect_uri` you send must match one registered on the app **exactly**, including any trailing
slash.

| Environment | Redirect URI |
|---|---|
| Local | `http://localhost:8000/xero/callback` |
| Staging | `https://staging.example.com/xero/callback` |
| Production | `https://example.com/xero/callback` |

HTTPS is required everywhere except `localhost`. **`http://127.0.0.1` is explicitly rejected by Xero** —
use `http://localhost` instead. (The package raises a clear error for this, because Xero's own is vague.)

### Scopes

| Scope | What it unlocks |
|---|---|
| `offline_access` | **Mandatory.** Without it Xero issues no refresh token and the connection dies after 30 minutes. |
| `accounting.settings` | Accounts, TaxRates, Organisation, BrandingThemes — the `settings()` resource |
| `accounting.contacts` | Contacts |
| `accounting.invoices` | Invoices, CreditNotes, Quotes, Items |
| `accounting.payments` | Payments, Overpayments, Prepayments |
| `accounting.attachments` | Attachments |
| `openid profile email` | Only if you want the authorising user's identity |

> ### ⚠️ Broad scopes retire in September 2027
>
> `accounting.transactions` is replaced by `accounting.invoices` + `accounting.payments` +
> `accounting.banktransactions` + `accounting.manualjournals`. Since March 2026 Xero has assigned
> granular scopes to all Web and PKCE apps, new and existing alike. This package ships granular scopes
> by default — do not add broad ones back.
>
> Scopes are **additive and cannot be removed** from an existing token without revoking it, and a
> connection made before you added a scope does not gain it. Deploy the scope change *before* asking
> anyone to reconnect, or they will have to do it twice.

---

## Configuration

Every key, and what breaks if it is wrong.

| Key | Required | Notes |
|---|---|---|
| `XERO_CLIENT_ID` | yes | From your Xero app. |
| `XERO_CLIENT_SECRET` | yes | Shown once at creation. A wrong value fails as `invalid_client`, not as an expired connection. |
| `XERO_REDIRECT_URI` | yes | Must match the app exactly. https, except `http://localhost`. |
| `XERO_WEBHOOK_KEY` | if using webhooks | From the app's Webhooks tab. Unset means every webhook is rejected with a 401. |
| `XERO_SCOPES` | no | Space separated. Must include `offline_access`. |
| `XERO_DEFAULT_CONNECTION` | no | Defaults to `default`. |
| `XERO_ROUTES_ENABLED` | no | Defaults to true. `route:cache` bakes in whatever this was at cache time. |
| `XERO_ROUTES_PREFIX` | no | Defaults to `xero`. |
| `XERO_ROUTES_MIDDLEWARE` | no | Defaults to `web,auth`. The flow needs a **session**. |
| `XERO_AFTER_CONNECT_REDIRECT` | no | Where the callback sends the user. |
| `XERO_HTTP_TIMEOUT` | no | Seconds, default 30. |
| `XERO_HTTP_RETRIES` | no | **Total** attempts including the first, default 3. |
| `XERO_LOCK_STORE` | recommended | A cache store that locks across processes. See below. |
| `XERO_DEFAULT_ACCOUNT_CODE` | no | Default `200`. **Differs per organisation.** |
| `XERO_DEFAULT_TAX_TYPE` | no | Deliberately unset. See the tax note below. |
| `XERO_DEFAULT_CURRENCY` | no | Default `MYR`. |
| `XERO_BRANDING_THEME_ID` | no | Per organisation. |

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
XERO_DEFAULT_ACCOUNT_CODE=200
XERO_DEFAULT_CURRENCY=MYR
# Leave unset unless every line of every invoice carries the same rate.
XERO_DEFAULT_TAX_TYPE=

# --- HTTP -------------------------------------------------------------------
XERO_HTTP_TIMEOUT=30
XERO_HTTP_RETRIES=3
```

---

## Connecting an organisation

Send an administrator to `/xero/connect`. They sign in, choose which organisations to authorise, and
Xero returns them to your callback. The package exchanges the code, reads the tenant list, stores the
connection with both tokens **encrypted at rest**, and fires `XeroConnected`.

The routes are behind `['web', 'auth']` by default. They need a **session**, because that is where the
CSRF state lives — an API-only application must supply something that starts one.

### Multiple organisations

Each connection has a short key. `/xero/connect` uses `default`; `/xero/connect/acme` uses `acme`.

```php
XeroBridge::invoices()->create($invoice);                    // the default connection
XeroBridge::connection('acme')->invoices()->create($invoice); // a named one
```

`connection()` returns a scoped clone, so it never leaks into later calls in the same request.

```bash
php artisan xero-bridge:status     # what is connected, and how healthy
```

> ### ⚠️ Connection limits
>
> Two different caps apply, and they are often confused:
>
> - **How many organisations your app may connect to** is set by your Xero **developer-account tier**
>   (Starter allows far fewer than Core).
> - **How many uncertified apps a single organisation may connect to** is capped separately. An
>   internal business system can never be certified — that requires a public app-store listing.
>
> A connection over either limit is **refused**, not degraded.
>
> Check the numbers for your plan against Xero's
> [API limits](https://developer.xero.com/documentation/guides/oauth2/limits/) before onboarding more
> systems, and keep a record of which applications hold the allowance. Xero's own pages have disagreed
> on the exact figures, so confirm in the Xero console rather than trusting a number here.

---

## Keeping tokens alive

Access tokens last **30 minutes**. Refresh tokens **rotate**: using one invalidates it and returns a new
one, which must be saved. An unused refresh token dies after **60 days**.

The package refreshes automatically before any call that needs it. Schedule this as well, so a connection
that is idle for weeks does not lapse:

```php
Schedule::command('xero-bridge:refresh-tokens')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
```

> ### ⚠️ `withoutOverlapping()` and `onOneServer()` are not optional
>
> Two processes refreshing the same connection concurrently each invalidate the other's token, and the
> connection dies. The package also takes a per-connection cache lock, but that lock is only as good as
> your cache store: **`array` and `file` do not lock across processes.** Set `XERO_LOCK_STORE` to redis,
> memcached or database. The package logs a warning if it detects a store that cannot lock.

If a refresh fails transiently — a 5xx, a timeout — nothing is changed and it is retried later.
**Credentials are never deleted.** Only a genuine `invalid_grant` marks a connection as needing
re-authorisation, which fires `ConnectionExpired` (once) and is cleared by reconnecting. Hang your
alerting on that event.

If your application rotates `APP_KEY`, every stored token becomes undecryptable and every organisation
must reconnect. The package reports this as a clear configuration error rather than a stack trace.

---

## Usage

### Settings first

Start here, not with invoices: you cannot build a line item without an account code, and **account codes
and tax types differ per organisation**.

```php
$settings = XeroBridge::settings();

$settings->accounts();          // the chart of accounts
$settings->paymentAccounts();   // only those a payment can be applied to
$settings->taxRates();          // the org's real TaxType codes and rates
$settings->organisation();      // base currency, country, timezone
```

> ### ⚠️ Never hardcode an account code or tax type
>
> A `200` sales account in one organisation may be `4000` in another. Read them per tenant with
> `settings()`, cache them in your own application, and re-read when a create fails with a validation
> error naming the code.
>
> **Malaysian SST is per line, not per invoice**: training is 8%, while education and rental or leasing
> are 6%. A single invoice that recharges a venue alongside training carries two rates. That is why
> `XERO_DEFAULT_TAX_TYPE` is unset by default, and why the package never adds a tax type to a line that
> already states one. Set a connection-wide default only if every line of every invoice is genuinely the
> same rate.

### Contacts

```php
XeroBridge::contacts()->findByEmail('finance@acme.test');
XeroBridge::contacts()->firstOrCreate(['EmailAddress' => 'finance@acme.test'], ['Name' => 'Acme Sdn Bhd']);
```

`firstOrCreate()` looks the contact up and then creates it with `PUT`, which is create-only. It
deliberately avoids `POST`'s implicit upsert: Xero warns that contact names are no longer guaranteed
unique, and with `POST` the loser of a race silently overwrites the winner's record.

### Invoices

```php
$invoice = XeroBridge::invoices()->create([
    'Type' => 'ACCREC',
    'Contact' => ['ContactID' => $contactId],
    'Reference' => $ccfCode,
    'Status' => 'DRAFT',
    'LineItems' => [[
        'Description' => 'Leadership training, 2 days',
        'Quantity' => 1,
        'UnitAmount' => 4800,
        'TaxType' => 'OUTPUT',     // per line
    ]],
]);
```

Missing `AccountCode`, `TaxType`, `CurrencyCode` and `BrandingThemeID` are filled from the connection
defaults. Anything you supply is left alone.

> **`Type` is never guessed.** ACCREC is a sale, ACCPAY is a bill; defaulting would post a bill as a sale
> on the one occasion someone meant the other, which Finance then has to journal back out.

> **Send only `ContactID` in the `Contact` block.** Xero applies any other field to the *contact record*
> and **deletes any `ContactPersons` you did not include**. The package refuses this by default; call
> `withContactMutation()` if you genuinely mean it.

Other operations:

```php
$invoices = XeroBridge::invoices();

$invoices->find('INV-01514');        // by number or by InvoiceID
$invoices->authorise($id);
$invoices->void($id);                // AUTHORISED only, and only with no payments
$invoices->delete($id);              // DRAFT or SUBMITTED
$invoices->email($id);               // asks Xero to send it
$invoices->pdf($id);                 // raw PDF bytes
$invoices->onlineUrl($id);
```

There is no HTTP DELETE for invoices — both `void()` and `delete()` are status changes, and the package
checks the transition is legal before sending it.

`update()` refuses a `LineItems` array whose lines lack `LineItemID`: Xero deletes and recreates any line
without one, and deletes any line you leave out. Call `replacingLineItems()` to opt in.

### Filtering

```php
use Peoplelogy\XeroBridge\Filters\InvoiceFilter;

$filter = InvoiceFilter::make()
    ->statuses(['AUTHORISED', 'PAID'])
    ->type('ACCREC')
    ->dateBetween('2026-01-01', '2026-03-31')
    ->orderBy('Date', 'DESC')
    ->pageSize(100);

$page = XeroBridge::invoices()->list($filter);          // one page
$all  = XeroBridge::invoices()->all($filter);           // LazyCollection, pages as you iterate
```

For incremental sync use `modifiedSince()`, which Xero recommends over a `where` on `UpdatedDateUTC`:

```php
InvoiceFilter::make()->modifiedSince($lastSyncedAt);
```

`all()` returns a `LazyCollection`, so `->take(5)` fetches one page, not all of them.

### Payments

```php
XeroBridge::payments()->createForInvoice($invoiceId, 4800, '090');
XeroBridge::payments()->delete($paymentId);   // payments cannot be modified, only reversed
```

The account must be of type `BANK` or have "enable payments to this account" switched on — use
`settings()->paymentAccounts()` to find valid ones. The invoice must be `AUTHORISED`; Xero flips it to
`PAID` itself.

### Anything else

```php
XeroBridge::request('GET', 'CreditNotes', [], ['If-Modified-Since' => '2026-01-01T00:00:00']);
XeroBridge::raw('GET', 'Reports/BalanceSheet');   // the raw Response
```

---

## Queued invoice creation

```php
class CreateXeroInvoice implements ShouldQueue
{
    public function __construct(public int $orderId) {}

    public function handle(): void
    {
        $order = Order::findOrFail($this->orderId);

        // 1. Already done? Nothing to do.
        if ($order->xero_invoice_id !== null) {
            return;
        }

        // 2. Belt and braces: has one already been created under our reference?
        $existing = XeroBridge::invoices()
            ->list(InvoiceFilter::make()->reference($order->reference));

        if ($existing !== []) {
            $order->update(['xero_invoice_id' => $existing[0]['InvoiceID']]);

            return;
        }

        $invoice = XeroBridge::invoices()->create([...], "order-{$order->id}");

        // 3. Persist immediately. THIS is the real dedupe.
        $order->update(['xero_invoice_id' => $invoice['InvoiceID']]);
    }
}
```

> ### ⚠️ Idempotency keys are retained for six minutes only
>
> The package sends an `Idempotency-Key` on every write, and it genuinely protects an immediate
> transient-network retry. It does **not** deduplicate a queued job retried later: after six minutes the
> key has expired and Xero treats the request as brand new, so **you get a duplicate invoice**. Almost
> any realistic backoff exceeds six minutes.
>
> Treat the key as an optimisation for transient failures, and make step 3 above — persisting the
> returned `InvoiceID` — your actual defence. Keys are also capped at 128 characters, and are checked
> *after* rate limiting, so duplicate requests still consume quota.

---

## Rate limits

Per organisation: 60 calls per minute, 5 concurrent, and a daily cap that depends on your plan. Across
your whole app: 10,000 per minute.

The package retries `429` and `5xx` automatically, honouring `Retry-After`. Two cases it deliberately
does not absorb, because blocking a worker for hours is worse than failing:

```php
try {
    XeroBridge::invoices()->create($invoice);
} catch (XeroRateLimitException | XeroServiceUnavailableException $e) {
    $this->release($e->retryAfter() ?? 300);
}
```

`XeroBridge::client()->lastRateLimit()` exposes the remaining quota from the last response.

---

## Webhooks

Set `XERO_WEBHOOK_KEY` and give Xero the URL printed by `xero-bridge:install` (`/xero/webhook`). Then
listen:

```php
Event::listen(XeroWebhookReceived::class, function (XeroWebhookReceived $event) {
    if ($event->event->isInvoice()) {
        SyncXeroInvoice::dispatch($event->resourceId(), $event->tenantId());
    }
});
```

The controller verifies the HMAC signature, queues one job and returns — it never runs listeners inline,
because Xero requires a 2xx within **5 seconds** and disables a subscription after 24 hours of failures.

> **Listeners must be idempotent.** Xero stores events for up to 31 days and replays them in order after
> an outage, so the same event can arrive more than once.

The webhook route carries no session, no CSRF and no cookies — any cookie in the response fails Xero's
intent-to-receive check.

---

## Error handling

All exceptions extend `XeroBridgeException`, so `catch (XeroBridgeException $e)` always works. Catch a
subclass when you want to react differently:

| Exception | What to do |
|---|---|
| `XeroConfigurationException` | Fix `.env`. Nothing is wrong with the connection. |
| `XeroConnectionNotFoundException` | Nobody has connected that organisation yet. |
| `XeroReauthorizationRequiredException` | A human must reconnect. The message carries the URL. |
| `XeroScopeException` | Widen `XERO_SCOPES` and re-consent. Refreshing will not help. |
| `XeroValidationException` | Fix the payload — see `validationErrors()`. |
| `XeroRateLimitException` | `release($e->retryAfter())`. |
| `XeroServiceUnavailableException` | Xero is down. Retry later. |

`$e->context()` returns a log-safe array. No exception message or context ever contains a token or your
client secret.

---

## Testing your integration

Your test suite should never reach Xero:

```php
Http::preventStrayRequests();

Http::fake([
    'api.xero.com/api.xro/2.0/Invoices' => Http::response([
        'Invoices' => [['InvoiceID' => 'test-id', 'InvoiceNumber' => 'INV-0001']],
    ]),
]);
```

You will also need a connection row. Create one directly with
`Peoplelogy\XeroBridge\Models\XeroConnection`, and an `APP_KEY` so the encrypted casts work.

---

## Versioning

[Semantic Versioning](https://semver.org). The public API is the `XeroBridge` facade and everything
reachable from it, the config keys, the published migration's schema, the command signatures, the
exception hierarchy and the dispatched events. Anything under `src/Support/` is internal.

Adding a Laravel major is a minor release; dropping one is a major.

---

## Contributing

```bash
composer test       # Pest
composer analyse    # PHPStan
composer format     # Pint
composer serve      # boot the workbench app
```

See [CONTRIBUTING.md](CONTRIBUTING.md) — in particular, tests must be written against the Pest 3 API,
because the CI matrix resolves Pest 3, 4 and 5 depending on the leg.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Security

Never commit credentials. Tokens are encrypted at rest with your `APP_KEY`. If a Xero client secret is
exposed, rotate it immediately at developer.xero.com and report it to the maintainers.

## Licence

Proprietary — © Peoplelogy Group. Internal use only.
