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
> **This affects your own `composer update`, not just ours.** `composer require` makes a minimal change
> and usually will not re-resolve `laravel/framework`, so adding this package to a Laravel 11
> application normally succeeds. A *full* `composer update` re-resolves everything and can then be
> refused outright:
>
> ```
> found laravel/framework[v11.56.1] but these were not loaded, because they are
> affected by security advisories (...). Go to https://packagist.org/security-advisories/
> ```
>
> Recent Composer versions accept `--no-security-blocking` as an escape hatch, and
> `composer config policy.advisories.block false` disables the check entirely — but both silence a real
> signal about your own application. The actual fix is upgrading off Laravel 11.
>
> **If you are on Laravel 11, upgrade to 12 or 13.** That is a security fix for your application, not a
> requirement of this package. Laravel documents an 11 → 12 upgrade as typically a day or less, and
> describes 12 → 13 as a minor upgrade for most applications.

---

## Installation

### 1. Add the repository

This package is private and is not on Packagist, so Composer has to be told where to find it. Add **both**
blocks below to the consuming application's `composer.json` — the `repositories` entry and the
`preferred-install` override. Adding only the first one fails; see
[Why those two extra settings](#why-those-two-extra-settings).

```json
"repositories": [
    {
        "type": "vcs",
        "url": "git@github.com:it-peoplelogy/laravel-xero-bridge.git",
        "no-api": true
    }
],
```

```json
"config": {
    "preferred-install": {
        "peoplelogy/laravel-xero-bridge": "source",
        "*": "dist"
    }
}
```

`repositories` is a top-level key, alongside `require` and `require-dev`. If your application already has
a `config` block — most Laravel applications do, with `"preferred-install": "dist"` — replace that one
line rather than adding a second `config` key.

### 2. Authentication

**SSH — recommended, and nothing is stored.** With the `git@github.com:` URL and `no-api` above, Composer
shells out to `git` and uses your existing agent. No token, no expiry to manage, no secret on disk.
Check it works with `ssh -T git@github.com`, which should greet you by username.

**HTTPS token — for build servers with no SSH key.** Use the `https://github.com/...` URL, drop the
`preferred-install` override, and supply a token as a build-time environment variable:

```bash
COMPOSER_AUTH='{"github-oauth":{"github.com":"YOUR_TOKEN"}}' composer install
```

Use a **fine-grained** personal access token with read-only *Contents* scoped to this one repository —
not a classic `repo` token, which grants write access across the whole organisation. An `auth.json` file
works too, but it stores the token in plain text, so it must be gitignored and is the weaker option.

### 3. Require a tagged version

```bash
composer require peoplelogy/laravel-xero-bridge:^1.0
```

### Why those two extra settings

Both settings exist because this is a **private** repository. Without them you hit two separate failures,
and neither error message names the real cause.

**`"no-api": true`** stops Composer asking for a GitHub token. Composer's GitHub driver reads repository
metadata from the **GitHub API** even when the URL is SSH, and the API needs a token for a private
repository. Without this you get an interactive prompt:

```
Your GitHub credentials are required to fetch private repository metadata
You need to provide a GitHub access token.
```

`no-api` tells Composer to treat it as an ordinary git repository and use SSH for metadata too. The cost
is that Composer clones to read metadata rather than fetching JSON, which is marginally slower and not
noticeable for a package this size.

**`preferred-install: source`** stops the download failing. Even with `no-api`, the `dist` URL recorded
for the package is a GitHub API zipball, which also needs a token — and GitHub answers **404**, not 403,
for an unauthorised private resource:

```
Failed to download peoplelogy/laravel-xero-bridge from dist: ...zipball/... (HTTP/2 404)
Source fallback is disabled. Not trying alternative sources.
```

That last line is the giveaway: applications that set `"preferred-install": "dist"` forbid Composer from
falling back to a git clone. The per-package override says *clone this one over SSH, keep using fast dist
archives for everything else*. Installing from source means `vendor/peoplelogy/laravel-xero-bridge`
contains a `.git` directory, which is harmless.

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

## Deploying: server and CI authentication

Your laptop authenticates to GitHub with your own SSH key. **A server does not.** The first
`composer install` on a new machine therefore fails like this:

```
Cloning failed using an ssh key for authentication, enter your GitHub credentials to access private repos
Token (hidden):
```

That is not a package problem — it is the machine having no credential for a private repository. A token
typed at that prompt works, but it expires, it is written in plain text under the deploy user's home
directory, and you repeat the exercise on every machine.

Note this applies **even when `composer.lock` is committed**. The lock pins which commit to install; it
does not grant access to fetch it.

### Recommended: a read-only deploy key

Best for a long-lived server. No expiry, no token to rotate, scoped to this one repository.

Run as the **deploy user** — the same account that runs `composer`, commonly `ubuntu` or `deploy`, not
`root`:

```bash
ssh-keygen -t ed25519 -C "xero-bridge deploy key $(hostname)" -f ~/.ssh/xero_bridge_deploy -N ""
cat ~/.ssh/xero_bridge_deploy.pub
```

On GitHub, go to the **repository** (not your account) → **Settings → Deploy keys → Add deploy key**,
paste the public key, and leave **"Allow write access" unchecked**.

Point SSH at the key, and pre-accept GitHub's host key so an unattended deploy never hangs on a
first-connection prompt:

```bash
printf 'Host github.com\n  HostName github.com\n  User git\n  IdentityFile ~/.ssh/xero_bridge_deploy\n  IdentitiesOnly yes\n' >> ~/.ssh/config
chmod 600 ~/.ssh/config
ssh-keyscan github.com >> ~/.ssh/known_hosts
```

Verify. A deploy key greets you with the repository name rather than a username:

```bash
ssh -T git@github.com
# Hi it-peoplelogy/laravel-xero-bridge! You've successfully authenticated, ...
```

`composer install` now works with no token.

> **One deploy key, one repository.** GitHub will not accept the same key on a second repository in the
> same account. If you later add another private package, either generate a second key and give it a
> `Host` alias in `~/.ssh/config`, or switch to a machine user with read access to the organisation.

> **Run Composer as the user that owns the key.** If deploys run as `root` or `www-data` while the key
> lives in `/home/ubuntu/.ssh`, SSH will not find it and you will see exactly the same prompt again.

### Alternative: a token, for containers and ephemeral CI

A container or a fresh CI runner has no persistent home directory to hold a key. There, use the HTTPS
URL, drop the `preferred-install` override (dist downloads work once authenticated), and inject the token
as a build-time environment variable rather than writing it to disk:

```bash
COMPOSER_AUTH='{"github-oauth":{"github.com":"YOUR_TOKEN"}}' composer install --no-dev --no-interaction
```

Use a **fine-grained** personal access token with read-only *Contents*, scoped to this one repository.
Not a classic `repo` token, which grants write access across every repository you can see.

Tokens expire, so this needs a rotation plan — which is why a deploy key is the better answer for a
server that will be around for years.

### Checklist for a new machine

1. Deploy key created, added to the repository, write access **not** granted.
2. `~/.ssh/config` points at it, `~/.ssh/known_hosts` pre-seeded.
3. `ssh -T git@github.com` names the repository.
4. Composer runs as the user owning that key.
5. `composer install` completes without prompting.

---

## Uninstalling

How much there is to undo depends on how far you got. Work through these in order and stop when you reach
a step that does not apply.

### If you only ran `composer require`

```bash
composer remove peoplelogy/laravel-xero-bridge
```

Then tidy the two things `composer remove` leaves behind in `composer.json`:

1. Delete the `"repositories"` entry for this package.
2. Revert `"preferred-install"` to plain `"dist"` (or remove the per-package override if you keep other
   private repositories).

Nothing else exists yet — no published files, no table — so you are done.

### If you also ran `xero-bridge:install` and `migrate`

**Roll the migration back BEFORE removing the package**, while the class is still autoloadable. Check
what you are about to undo first — `--step=1` rolls back the most recent migration, which is only ours
if nothing has been migrated since:

```bash
php artisan migrate:status | tail -5      # confirm the xero_connections migration is last
php artisan migrate:rollback --step=1     # drops the xero_connections table
composer remove peoplelogy/laravel-xero-bridge
```

If other migrations have run since, do not use `--step`. Drop the table directly instead — the migration
creates nothing else, and there are no foreign keys into it:

```sql
DROP TABLE xero_connections;   -- plus your connection's prefix, e.g. pips_xero_connections
```

then delete the migration file and the row for it in the `migrations` table.

Then remove what publishing left in your application:

```bash
rm config/xero-bridge.php
rm database/migrations/*_create_xero_connections_table.php
```

and delete the `XERO_*` keys from `.env` and `.env.example`.

> ### ⚠️ Dropping the table does not disconnect you from Xero
>
> `xero_connections` holds encrypted OAuth tokens, and deleting them only makes *you* forget the
> connection. Xero still lists your application against that organisation, and it still counts against
> the connection limits.
>
> Disconnect properly **before** you drop the table, while you can still read the tokens:
>
> - **Per organisation** — `DELETE https://api.xero.com/connections/{id}`, using the stored
>   `connection_id`. Note that is Xero's *connection* id, not the `tenant_id`.
> - **Everything authorised in one flow** — `POST https://identity.xero.com/connect/revocation` with the
>   refresh token.
>
> Or have an administrator remove it from the organisation's connected apps in the Xero UI.

### If `composer remove` fails

On Laravel 11 you may see the advisory error described in the support matrix above, because removing a
package re-resolves the dependency graph. Add `--no-security-blocking`, or fix the underlying problem by
upgrading off Laravel 11.

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
