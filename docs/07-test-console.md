# 7. The test console

A page at `/xero/console` that exercises the whole bridge by hand. It ships with the package: install,
migrate, and it is there.

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

| `XERO_CONSOLE_ENABLED` | Production | Everywhere else |
|---|---|---|
| unset (the default) | off | **on** |
| `true` | on | on |
| `false` | off | off |

An empty value (`XERO_CONSOLE_ENABLED=`) counts as unset, not as `false` — a bare key copied out of an
example file is not a decision.

### Why the environment is not resolved in the config file

A config file is evaluated **once**, at `config:cache` time, and the resolved array is written to
`bootstrap/cache/config.php`. A default computed there would freeze whichever environment happened to run
that command — and an artifact built in CI, where `APP_ENV` is often unset, would freeze the wrong one.

So `enabled` stays `null` in config and the environment is resolved at **boot**, on every request. What
`config:cache` does bake is `app.env`, and Laravel's own `config/app.php` ships
`env('APP_ENV', 'production')` — so a cache built with no `APP_ENV` resolves to production and the
console is **off**. It fails closed.

### `route:cache` and why there is also a middleware

`ServiceProvider::loadRoutesFrom()` is a no-op once routes are cached. So a `route:cache` built on a box
where the console was enabled, then deployed to production, keeps the route: the conditional in the
service provider never runs again.

That is why the console route also carries `EnsureConsoleEnabled`, which re-reads the flag on every
request and returns **404** when the console is off. It is baked into the cached route alongside
everything else, so it closes the hole. `routes.enabled` carries the same `route:cache` caveat and has
no equivalent guard, which is tolerable for two authenticated OAuth endpoints and is not for a console
that can void invoices.

Note that `XERO_CONSOLE_MIDDLEWARE` and `XERO_CONSOLE_PREFIX` are baked by `route:cache` too, so changing
them needs `php artisan route:clear`.

---

## Who can reach it

```bash
XERO_CONSOLE_MIDDLEWARE="web,auth"            # the default
XERO_CONSOLE_MIDDLEWARE="web,auth,can:manage-xero"
XERO_CONSOLE_MIDDLEWARE="web,auth,role:admin"
```

The default means **any authenticated user**, which is almost never what you want beyond local
development. Narrow it.

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
XERO_CONSOLE_WRITABLE_ORGANISATIONS="Acme Sandbox,Peoplelogy Bridge Test"
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
| **Token-refresh lock is usable** | The configured cache store is reachable and is a `LockProvider`, proven by taking and releasing a real lock. A store with no lock provider is a **warning**, not a failure: the bridge logs and carries on unlocked, which is fine in one process and loses rotated refresh tokens across several. |

Below them, the same advisories `xero-bridge:status` reports — a missing webhook key, a cache store that
cannot lock across processes, connections with recent transient failures. Both surfaces read the same
`Support\Diagnostics`, so they cannot disagree.

---

## The actions

Fifteen, on an explicit allow-list, plus two more when the MyInvois module is switched on. Anything not on it is rejected by validation, so a value posted from
a browser can never reach an arbitrary method.

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
- **`connection.forget` is local only.** It drops the stored row so the consent flow can be replayed.
  Nothing is revoked at Xero.

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
  "rate_limit": { "minute_remaining": 59, "day_remaining": 4999, "problem": null },
  "count": 42,
  "data": [ … ]
}
```

and on failure:

```json
{
  "ok": false,
  "action": "invoices.create_draft",
  "duration_ms": 388,
  "error": {
    "type": "XeroValidationException",
    "message": "…",
    "hint": "Xero rejected the request. See validation_errors for the element it objected to.",
    "status": 400,
    "validation_errors": ["Account code 999 is invalid"]
  }
}
```

**A Xero failure comes back as HTTP 200**, with `ok: false`. That is deliberate: the console renders the
diagnosis itself, and a non-2xx would be swallowed by the browser's own error handling. The one genuine
non-2xx is a **422** for an action or connection key that failed validation — that is Laravel's
validator, not Xero.

`error.hint` is the remediation for that exception type. `XeroScopeException` adds `granted_scopes`,
`XeroRateLimitException` adds `limit_problem`. The full exception reference is in
[Commands, errors and token lifecycle](05-commands-and-errors.md).

---

## What it never shows you

Access tokens, refresh tokens and your client secret. The model hides the tokens, and the status payload
is built key by key rather than from the model, so nothing can leak by adding a column later.
Credentials appear only as booleans:

```json
{ "client_id_set": true, "client_secret_set": true, "webhook_key_set": false }
```

There are tests asserting all of this on both the rendered page and the JSON endpoint.

---

## What it costs

One `SELECT` over the connections table, plus one cache lock taken and released, per page load. **No
Xero call** — the whole status panel is answerable locally. "Re-read status" repeats it.

That is cheap, but it is not free, so do not point a health check at this URL.

---

## Customising the page

```bash
php artisan vendor:publish --tag=xero-bridge-views
```

writes `resources/views/vendor/xero-bridge/console.blade.php`, which then takes precedence. Be aware
that a published copy stops receiving upstream fixes, so publish only when you need to.

The usual reason is a strict **`Content-Security-Policy`**. The page carries its CSS and JS inline, so a
policy of `script-src 'self'` without `'unsafe-inline'` leaves it rendered but unstyled and inert — every
panel empty, every button dead, and the only explanation in the browser console. Publish the view and
externalise the assets, relax the policy for this one path, or turn the console off.

---

## Troubleshooting

**The page is a 500 saying `Route [login] not defined`.** The stock `auth` middleware redirects
unauthenticated visitors to a `login` route your application does not define. Point
`XERO_CONSOLE_MIDDLEWARE` at a guard that suits you, such as `web,auth:sanctum`, or define the route.

**The page is unstyled and no button does anything.** A Content-Security-Policy is blocking the inline
`<style>` and `<script>`. See above.

**The page 404s where you expect it.** In production it needs `XERO_CONSOLE_ENABLED=true`. Everywhere
else, check that `XERO_CONSOLE_ENABLED` is not set to `false`, and run `php artisan config:clear` and
`php artisan route:clear` if either cache is stale.

**Connect URL, Callback URL or Webhook URL reads "route disabled".** That route group is switched off —
`XERO_ROUTES_ENABLED` or `XERO_WEBHOOKS_ENABLED`. The console reports it rather than failing, so the rest
of the page still works.

**Every write is refused.** That is the write guard. Confirm which organisation you are connected to with
*Reference lookups → Organisation* and check `IsDemoCompany`. See [The write guard](#the-write-guard).
