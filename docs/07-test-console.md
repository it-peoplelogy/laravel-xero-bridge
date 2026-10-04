# 7. The test console

A page at `/xero/console` that exercises the whole bridge by hand. It ships with the package and is **off
until you switch it on** with `XERO_CONSOLE_ENABLED=true` in the environment that should have it.

- [What it is](#what-it-is)
- [Turning it on and off](#turning-it-on-and-off)
- [Who can reach it](#who-can-reach-it)
- [The write guard](#the-write-guard)
- [Pre-flight](#pre-flight)
- [The actions](#the-actions)
- [Reading the response](#reading-the-response)
- [What it never shows you](#what-it-never-shows-you)
- [What it costs](#what-it-costs)
- [Customising the page](#customising-the-page)
- [Troubleshooting](#troubleshooting)

---

## What it is

The fastest way to answer "is this integration actually working?" without writing a script.

It renders as a **standalone HTML document** — no layout, no Vite, no Blade components, no CDN, no
published asset. Its CSS and JavaScript are inline and its only dependency is the browser. That is
deliberate: it behaves identically in an Inertia app, a Livewire app, a Filament app and an API-only one,
and it cannot disturb the host application's styling.

The left column runs actions. The right column shows the response as highlighted JSON, with how long the
call took, how many items came back, and what Xero reported as your remaining rate limit.

---

## Turning it on and off

| `XERO_CONSOLE_ENABLED` | The console |
|---|---|
| unset, or empty (the default) | off |
| `true` — or `1`, `on`, `yes`, in any case | **on**, in every environment |
| `false`, `0`, `off`, `no`, or anything else | off |

That is the only switch. **`APP_ENV` is never consulted**: a live host whose `APP_ENV` is `prod`, `live`
or `Production` is no less live, and a staging box cloned from an `.env.example` that says `local` is not
a developer's laptop. Only an explicit `XERO_CONSOLE_ENABLED=true` exposes the page, and anything that does
not read as true — a typo included — leaves it off.

An empty value (`XERO_CONSOLE_ENABLED=`) is off, like unset: a bare key copied out of an example file is
not a decision. `xero-bridge:install` and `xero-bridge:status` report it as "not set" rather than as
`false`, in the same words:

```text
Test console: off (XERO_CONSOLE_ENABLED is not set)
Test console: off (XERO_CONSOLE_ENABLED=false)
Test console: ON (XERO_CONSOLE_ENABLED=true)
```

Install goes on to say where the page is — or would be — served, what sits in front of it and which
organisations it may write into; status says where and behind what while it is on. See
[Commands](05-commands-and-errors.md).

> **Upgrading from 1.4 or earlier.** Before 1.5.0, unset meant on wherever `APP_ENV` was not exactly
> `production`. Set `XERO_CONSOLE_ENABLED=true` in each environment that should keep the console, then run
> `php artisan config:clear` and `php artisan route:clear` (or rebuild both caches). If your own code links
> to the page with `route('xero-bridge.console')`, guard it with `Route::has()`: where the console is off,
> the route does not exist.

### `route:cache` and why there is also a middleware

`ServiceProvider::loadRoutesFrom()` is a no-op once routes are cached. So a `route:cache` built on a box
where the console was enabled, then deployed where it is off, keeps the route: the conditional in the
service provider never runs again.

That is why the console route also carries `EnsureConsoleEnabled`, which re-reads the flag on every
request and returns **404** when the console is off. It runs first, ahead of `web` and `auth`, so an
anonymous visitor gets the 404 rather than a redirect to a login page that would advertise something is
there. It is baked into the cached route alongside everything else, so it closes the hole.
`routes.enabled` carries the same `route:cache` caveat and has no equivalent guard, which is tolerable
for two authenticated OAuth endpoints and is not for a page that reads the connected organisation's
invoices and contacts.

The reverse needs a hand: a route cache built while the console was **off** has no console route at all,
so switching it on takes `php artisan route:clear` (or a fresh `route:cache`) as well as
`php artisan config:clear`.

Note that `XERO_CONSOLE_MIDDLEWARE` and `XERO_CONSOLE_PREFIX` are baked by `route:cache` too, so changing
them needs `php artisan route:clear`.

---

## Who can reach it

```bash
XERO_CONSOLE_MIDDLEWARE="web,auth"            # the default
XERO_CONSOLE_MIDDLEWARE="web,auth,can:manage-xero"
XERO_CONSOLE_MIDDLEWARE="web,auth,role:admin"
```

The default means **any authenticated user** of your application — every account, not just your
administrators — which is almost never what you want beyond local development. Narrow it wherever you
switch the console on. `xero-bridge:install` warns when the console is on behind exactly `web,auth`,
more loudly behind `web` alone (no authentication), and when it is on with no middleware at all.

Whoever gets past the middleware can:

- **read** the connected organisation through every read action — its settings, chart of accounts and tax
  rates, its invoices and its contacts — as Xero holds them, except for the credentials and bank details
  listed under [What it never shows you](#what-it-never-shows-you);
- **force a token refresh**, which rotates the stored refresh token;
- **forget the stored connection**, which takes the integration offline until someone reconnects;
- **write into Xero** — but only into a Demo Company or an organisation you named, as
  [The write guard](#the-write-guard) explains.

Keep something that starts a **session**. The page posts a CSRF token with every action; on a
session-less stack the token is omitted (and `VerifyCsrfToken` is not in the stack either, so it still
works) — but that leaves an endpoint that can create invoices with no CSRF protection at all.

The URL follows `routes.prefix`, so `XERO_ROUTES_PREFIX=accounting` moves the console to
`/accounting/console`. `XERO_CONSOLE_PREFIX` changes the second segment.

---

## The write guard

Reads run against whatever is connected. Four actions **write into Xero**, and those are refused unless
the connected organisation is disposable:

1. a Xero **Demo Company** — Xero provisions it, flags it with `IsDemoCompany`, and its data is throwaway;
2. or an organisation named in `XERO_CONSOLE_WRITABLE_ORGANISATIONS`.

```bash
XERO_CONSOLE_WRITABLE_ORGANISATIONS="Acme Sandbox,Another Sandbox"
```

Matched on the **full name**, case-insensitively, **never as a substring** — `Acme` must not open the
door to `Acme Trading Sdn Bhd`. The list is empty by default, so out of the box only a Demo Company is
writable.

A sandbox organisation you create by hand looks exactly like a real ledger over the API. That is the
whole reason it has to be named deliberately rather than detected.

The guard is applied once, centrally, from the `xero_writes` flag on the action list, so a new write
action cannot forget it. The organisation lookup is memoised for the request only — a cached "yes" would
outlive a reconnection to a different organisation, which is precisely the mistake the guard exists to
prevent.

Why this matters: a DRAFT invoice can be deleted, but an **AUTHORISED invoice can only ever be voided**,
and it stays visible in Xero for good.

---

## Pre-flight

Two checks run on page load, because both fail late and confusingly otherwise.

| Check | What it proves |
|---|---|
| **Consent flow can start** | `XeroConfig::assertReadyToConnect()` — credentials present, `offline_access` granted, and a redirect URI Xero will actually accept. Xero rejects `http://127.0.0.1` explicitly and its own error for that is vague. |
| **Token-refresh lock is usable** | The lock store — the one `XERO_LOCK_STORE` names, or the default cache store when it is unset or blank — can be resolved and can lock, proven by taking and releasing a real lock. A store that supports no locks, or the `null` driver (which grants every lock at once, so excludes nothing), is a **warning**: the bridge logs it once and refreshes unlocked, which is fine in one process and loses rotated refresh tokens across several. A store that cannot be resolved or reached — `XERO_LOCK_STORE` naming one that is not defined, say — is a **failure**. |

Below them come the warnings — the same list `xero-bridge:status` prints:

- a package table in the way: the connections table missing, unreadable or not the package's own, or the
  table of a feature you switched on (write ledger, webhook replay, API capture) that is not the package's.
  The connections table is checked only while the package's own `EloquentConnectionRepository` stores
  the connections, and is the configured model's own table; with a repository of your own, a read that
  throws is reported here instead ("Could not read the stored connections: ...");
- the write ledger on with its table missing or unreadable, or with claims pending for over an hour;
- a package table that the `migrations` table records as created more than once — rolling back the later
  batch would drop the live table;
- a lock store whose locks do not reach far enough: `array` (one process), or `file` as a default nobody
  chose (one server);
- connections with recent transient failures;
- a connection whose stored tokens the current `APP_KEY` cannot decrypt — put the old key in
  `APP_PREVIOUS_KEYS`, or re-authorise. Its Connections row shows a red **tokens unreadable** pill.

The panel and `xero-bridge:status` read the same `Support\Diagnostics`, so they show the same checks and
cannot disagree. The one finding status prints that this panel does not — the note for a `file` store
that `XERO_LOCK_STORE` names explicitly — appears as the grey pill on the **Lock store** row instead. When
the connections table itself is unusable, the page still renders: the problem is here, and the
Connections panel is empty.

### The Lock store row

The **Environment** panel's **Lock store** row names the store — `XERO_LOCK_STORE`'s, or the default
cache store's while that is unset or blank — followed by at most one pill. The store is judged by its
class, exactly as `xero-bridge:status` judges it, never by its name: a file-driver store called `local`
counts as `file`.

| Pill | When | What `xero-bridge:status` says |
|---|---|---|
| **one process only**, amber | an `array` store, named or the default | a warning |
| **one server only**, amber | a `file` store that is only the default — `XERO_LOCK_STORE` unset or blank | a warning |
| **one server only**, grey | a `file` store that `XERO_LOCK_STORE` names | a note, which `--strict` ignores |
| **no locking**, amber | the `null` driver, or a store that supports no locks | a lock pre-flight warning |
| none | redis, memcached, database, dynamodb, or any other store that locks | nothing |
| none | a store that cannot be resolved | the pre-flight failure above |

A copy of the page published before 1.5.0 still has the old pill, which matched the store's *name* against
`array` and `file` and said "no cross-process lock" — wrong both ways. See
[Customising the page](#customising-the-page).

---

## The actions

Fifteen, on an explicit allow-list, plus two more when the MyInvois module is switched on. Anything not
on it is rejected by validation, so a value posted from a browser can never reach an arbitrary method.

| Action | Call | Writes |
|---|---|---|
| `status` | none — local only | |
| `settings.organisation` | `GET /Organisation` | |
| `settings.accounts` | `GET /Accounts` | |
| `settings.tax_rates` | `GET /TaxRates` | |
| `settings.payment_accounts` | `GET /Accounts`, filtered | |
| `contacts.first_or_create` | `GET /Contacts`, then `PUT` if absent | **Xero** |
| `invoices.create_draft` | `POST /Invoices` | **Xero** |
| `invoices.create_paid` | `POST /Invoices`, then `PUT /Payments` | **Xero** |
| `invoices.send_to_contact` | `POST /Contacts/{id}`, then `/Invoices/{id}/Email` | **Xero** |
| `invoices.find` | `GET /Invoices/{id}` | |
| `contacts.find_by_email` | `GET /Contacts?where=` | |
| `contacts.find` | `GET /Contacts/{id}` | |
| `invoices.list` | `GET /Invoices` | |
| `tokens.refresh` | `POST identity/connect/token` | local |
| `connection.forget` | none | local |
| `myinvois.validate` † | `GET /api/v1.0/taxpayer/validate/{tin}` (LHDN) | |
| `myinvois.forget_token` † | none — local cache only | local |

† Present **only** while the MyInvois module is enabled. It is off by default, so a console outside
Malaysia lists neither the panel nor these two actions. See
[MyInvois TIN validation](08-myinvois-tin-validation.md).

**Suggested first run.** Connect an organisation, then Organisation (proves the token works), Chart of
accounts (gives you real account codes), Tax rates (gives you real `TaxType` codes), List invoices
(proves paging and filters), Force token refresh (the behaviour most worth testing before go-live).

Three of these encode a Xero rule worth knowing:

- **`invoices.create_paid` is three calls.** PAID is not a status you can set — Xero rejects
  `Status: "PAID"` on create and moves an invoice there itself once payments cover it. So the console
  creates the invoice AUTHORISED (a payment cannot attach to a DRAFT), pays Xero's own `AmountDue`
  rather than the figure that was sent (rounding and tax are applied server-side), then reads the
  invoice back so you see the status Xero actually settled on.
- **`invoices.send_to_contact` rebuilds the CC list wholesale.** Xero's email endpoint takes an empty
  body — no to, cc, bcc or subject. The only way to copy anyone is `ContactPersons` with
  `IncludeInEmails` **on the contact**, five maximum, and the list you send replaces the stored one. It
  is per contact, not per send: everyone on it is copied on every future invoice for that customer.
- **`connection.forget` deletes this app's stored row, and that takes the integration offline.** It is
  there so the consent flow can be replayed, but until someone reconnects, every call through that key
  fails. Nothing is revoked at Xero: the authorisation stays live there until it is removed in Xero
  itself. Each deletion is logged at `warning` level, as "xero-bridge: the test console deleted a stored
  Xero connection. …", with the key, the `tenant_id`, Xero's `connection_id` — which finishing the
  disconnect at Xero needs, and which goes with the row — and who did it: `actor_id`, `actor_type` and
  `actor_guard`, `null` when nobody is signed in. It is an Eloquent delete, so your model observers see it
  too. A `deleting` listener that cancels it is reported as `deleted: false`, and nothing is logged.

`invoices.list` is always paged, with the page size clamped to 1000. An unbounded `/Invoices` on a live
organisation is the quickest way to spend the daily rate limit from a browser tab.

---

## Reading the response

Every action answers with the same envelope:

```json
{
  "ok": true,
  "action": "settings.accounts",
  "connection": "default",
  "duration_ms": 412,
  "rate_limit": {
    "minute_remaining": 59,
    "day_remaining": 4999,
    "app_minute_remaining": 9999,
    "problem": null,
    "retry_after": null
  },
  "count": 42,
  "data": [ … ]
}
```

and on failure:

```json
{
  "ok": false,
  "action": "invoices.create_draft",
  "connection": "default",
  "duration_ms": 388,
  "rate_limit": { … },
  "error": {
    "type": "XeroValidationException",
    "class": "Peoplelogy\\XeroBridge\\Exceptions\\XeroValidationException",
    "message": "A validation exception occurred: Account code '999' is not a valid code for this document.",
    "hint": "Xero rejected the request. See validation_errors for the element it objected to.",
    "status": 400,
    "connection_key": "default",
    "xero_type": "ValidationException",
    "xero_error_number": 10,
    "validation_errors": ["Account code '999' is not a valid code for this document."],
    "context": { … }
  }
}
```

`rate_limit` is the last rate-limit reading for the API the action belongs to — Xero's, or LHDN's for a
MyInvois action — so after a call it describes that call, and it is `null` when nothing in this process
has called that API yet. `count` is the number of items when `data` is a list, and `null` otherwise.

**A Xero failure comes back as HTTP 200**, with `ok: false`. That is deliberate: the console renders the
diagnosis itself, and a non-2xx would be swallowed by the browser's own error handling. The one genuine
non-2xx is a **422** for an action or connection key that failed validation — that is Laravel's
validator, not Xero.

`error.hint` is the remediation for that exception type. Fields with nothing to say are left out:
`XeroScopeException` adds `granted_scopes`, `XeroRateLimitException` adds `limit_problem`, and a MyInvois
failure adds LHDN's `correlation_id`, `error_code` and `error_ms`, its message in Malay. The full
exception reference is in [Commands, errors and token lifecycle](05-commands-and-errors.md).

---

## What it never shows you

Access tokens, refresh tokens and your client secret. The model hides the tokens, and the status payload
is built key by key rather than from the model, so nothing can leak by adding a column later.
Credentials appear only as booleans:

```json
{ "client_id_set": true, "client_secret_set": true, "webhook_key_set": false }
```

There are tests asserting this on both the rendered page and the JSON endpoint.

**Credentials and bank details are masked out of every action's response.** Before the response is
built, every value — at any depth — whose key is one of these is replaced with the capture placeholder,
`[redacted]` unless `XERO_CAPTURE_PLACEHOLDER` says otherwise:

`Authorization`, `Proxy-Authorization`, `access_token`, `refresh_token`, `id_token`, `client_secret`,
`X-Xero-Signature`, `APIKey`, `BankAccountNumber`, `BankAccountDetails`, `BankAccountName`,
`BatchPayments`, `BatchPayment`

That is `RedactionPolicy::ALWAYS`, the tier [API capture](10-api-capture.md) can never switch back on,
matched on the whole key, case-insensitively, and never as a stem: `BankAccountType`, and a contact's
`AccountNumber` (your own customer code), stay. A matched value goes whole, subtree and all, so
`BatchPayments` takes its `BankAccountName`, `Details`, `Code` and `Reference` with it. In practice that is
the Organisation's `APIKey` — a live Xero-to-Xero credential — the `BankAccountNumber` of the
organisation's own bank accounts, and a customer's `BankAccountDetails` and `BatchPayments` on every
contact read.

Nothing else is masked, on purpose. The console shows what Xero holds, so `TaxNumber`, `CompanyNumber`,
email addresses, names, addresses, phone numbers and every invoice field come back as they are — the TIN
you have just written onto a contact is the very thing you came to check. `capture.redact.add` and
`capture.redact.keep` do not apply here, and nothing is truncated: a 1,000-invoice page comes back whole.

Only the `data` of a successful response is masked. The `error` envelope carries messages and the
exception's `context()`, never a payload. A database error shows the driver's message — `SQLSTATE[...]`
and what went wrong — never the SQL with its bindings, which for a token save that failed would be the
new tokens' ciphertext; with no driver message beneath it, it reads "A database query failed.". `tests/Console/ConsoleRedactionTest.php` pins the masking.

---

## What it costs

Per page load: a look at each package table it reports on — does it exist, does it have the package's
columns — and at the `migrations` table, one `SELECT` over the connections table, a count of stuck claims
when the write ledger is on, and one cache lock taken and released. **No Xero call** — the whole status
panel is answerable locally. "Re-read status" repeats it.

That is cheap, but it is not free, so do not point a health check at this URL.

---

## Customising the page

```bash
php artisan vendor:publish --tag=xero-bridge-views
```

writes the console's views to `resources/views/vendor/xero-bridge/` — `console.blade.php`, and the MyInvois
panel's `console/myinvois.blade.php` — which then take precedence. Be aware that a published copy stops
receiving upstream fixes, so publish only when you need to.

The usual reason is a strict **`Content-Security-Policy`**. The page carries its CSS and JS inline, so a
policy of `script-src 'self'` without `'unsafe-inline'` leaves it rendered but unstyled and inert — every
panel empty, every button dead, and the only explanation in the browser console. Publish the view and
externalise the assets, relax the policy for this one path, or turn the console off.

A copy published before 1.5.0 still says the page is "off in production unless
`XERO_CONSOLE_ENABLED=true`" and still draws the old name-based lock-store pill. Republishing with
`--force` brings in the current page, and overwrites any edits you made to your copy.

---

## Troubleshooting

**The page is a 500 saying `Route [login] not defined`.** The stock `auth` middleware redirects
unauthenticated visitors to a `login` route your application does not define. Point
`XERO_CONSOLE_MIDDLEWARE` at a guard that suits you, such as `web,auth:sanctum`, or define the route.

**The page is unstyled and no button does anything.** A Content-Security-Policy is blocking the inline
`<style>` and `<script>`. See above.

**The page 404s.** The console is off unless `XERO_CONSOLE_ENABLED=true` in the environment serving it —
in every environment, local ones included. Set it, then run `php artisan config:clear` and
`php artisan route:clear`: a cached config never reads the environment file again, and a route cache built
while the console was off has no console route. `php artisan xero-bridge:status` ends with a line saying
whether the console is on, and why.

**`xero-bridge:install` says ON, but the page still 404s.** A route cache built while the console was off.
Run `php artisan route:clear`, or rebuild it with `php artisan route:cache`.

**A value reads `[redacted]`.** That is deliberate: credentials and bank details are masked out of every
response. See [What it never shows you](#what-it-never-shows-you).

**Connect URL or Callback URL reads "route disabled".** That route is not served: `XERO_ROUTES_ENABLED`
is off. The console reports it rather than failing, so the rest of the page still works.

**Webhook URL reads "off", in grey.** No webhook route is served. Usually no `XERO_WEBHOOK_KEY` is set —
the route exists only once one is, which is all an application that only calls Xero needs, so the
`XERO_WEBHOOK_KEY` row is grey too, not amber. Otherwise `XERO_WEBHOOKS_ENABLED` is off, and the row
says so.

**Every write is refused.** That is the write guard. Confirm which organisation you are connected to with
*Reference lookups → Organisation* and check `IsDemoCompany`. See [The write guard](#the-write-guard).
