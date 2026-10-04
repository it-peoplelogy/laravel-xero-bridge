# 8. MyInvois taxpayer TIN validation (Malaysia)

An optional, **disabled-by-default** client for LHDN Malaysia's MyInvois
**Validate Taxpayer's TIN** endpoint. One call, one boolean: is this TIN genuinely paired with this
identifier in HASiL's records?

- [Why it is in this package](#why-it-is-in-this-package)
- [Turning it on](#turning-it-on)
- [Using it](#using-it)
- [What a match does and does not prove](#what-a-match-does-and-does-not-prove)
- [Errors](#errors)
- [Caching](#caching)
- [The console panel](#the-console-panel)
- [Every setting](#every-setting)
- [Troubleshooting](#troubleshooting)

---

## Why it is in this package

Honestly: because the applications that need it are the applications already using this bridge, and
the TIN and BRN it validates are the same two values they write onto a Xero contact as `TaxNumber`
and `CompanyNumber`.

It shares **no code** with the Xero side and is not meant to. Xero uses per-connection OAuth with
rotating refresh tokens; MyInvois uses client credentials with one short-lived token and no tenant.
The module lives in its own namespace, its own config file and its own exception, and an
architecture test forbids it from reaching into any Xero class — so it could be lifted into a
separate package without touching a line of Xero code.

**If you are not in Malaysia, this page does not apply to you.** The module ships off. It registers
no route, requires no configuration, and changes nothing about how this package behaves.

---

## Turning it on

```bash
php artisan vendor:publish --tag=myinvois-config   # optional, for the annotated file
```

> `php artisan xero-bridge:install` does **not** publish this — it is a separate tag on purpose, so
> that republishing the Xero config with `--force` can never overwrite your MyInvois settings, and
> vice versa. Publishing is optional either way: every default is merged from the package.

Then:

```bash
MYINVOIS_ENABLED=true
MYINVOIS_ENVIRONMENT=sandbox          # or production
MYINVOIS_CLIENT_ID=...
MYINVOIS_CLIENT_SECRET=...
```

```bash
php artisan config:clear
```

Credentials come from the MyInvois portal, under the taxpayer's **ERP registration**. Sandbox and
production issue **separate** Client IDs — an ID that works in one is rejected by the other, so
changing `MYINVOIS_ENVIRONMENT` without also changing the credentials is a misconfiguration rather
than a promotion.

---

## Using it

```php
use Peoplelogy\XeroBridge\MyInvois\Facades\MyInvois;
use Peoplelogy\XeroBridge\MyInvois\IdType;

$valid = MyInvois::validate('C25845632020', IdType::BRN, '201901234567');
```

or inject the client, which is what most applications should do:

```php
use Peoplelogy\XeroBridge\MyInvois\MyInvoisClient;

final class ValidateBuyerTin
{
    public function __construct(private readonly MyInvoisClient $myInvois) {}
}
```

`$idType` accepts an `IdType` case or a string in any casing. The four values LHDN publishes:

| `IdType` | What it is |
|---|---|
| `NRIC` | Malaysian identity card number |
| `PASSPORT` | Passport number |
| `BRN` | Business registration number (SSM) |
| `ARMY` | Army number |

**Both values are required, and that is the point.** Since **1 August 2026** this endpoint validates
the TIN and the identifier **as a pair** — before that, a loosely-matched pair could pass. A valid
TIN submitted with a stale or mistyped registration number now answers exactly like a fabricated
one, which is why collecting the BRN alongside the TIN stopped being bookkeeping and became what
makes the call answer correctly.

**No format check is applied to the TIN, deliberately.** LHDN publishes none: the parameter table
types it as "Number" while its own example, `C25845632020`, is not one. Malaysian TINs carry letter
prefixes that vary by taxpayer class, so any regex this package invented would eventually reject a
valid TIN with no way for you to override it. The `idType` *is* a published closed list, so that one
is enforced.

---

## What a match does and does not prove

`true` means the TIN and the identifier exist, paired, in HASiL's records.

It does **not** mean the pair belongs to the counterparty you are invoicing. The endpoint returns no
name, no address and in fact no body at all — only a status code. Treat the answer as "this is a real
TIN/ID pair", never as identity verification.

If you need identity evidence, LHDN's *Search Taxpayer's TIN* (which takes a taxpayer name) and
*Taxpayer's QR Code* (which returns name and address) carry far more of it. Neither is implemented
here.

---

## Errors

```php
use Peoplelogy\XeroBridge\MyInvois\MyInvoisException;

try {
    $valid = MyInvois::validate($tin, IdType::BRN, $brn);
} catch (MyInvoisException $e) {
    if ($e->isConfigurationProblem()) {
        // A human must fix a setting or the portal registration. Do NOT retry.
    }

    if ($e->isRetryable()) {
        // Rate limit or an LHDN outage. Back off; $e->retryAfter() when present.
    }

    Log::error('MyInvois validation failed', $e->context());
}
```

**A negative answer is not an exception.** HTTP 404 — "that TIN and ID combination cannot be found or
are considered invalid" — is the answer you asked for, so it comes back as `false`. Nothing else in
this package returns a boolean and throws for one of its two values.

| Situation | What you get |
|---|---|
| Pair exists | `true` |
| Pair not found (404) | `false` |
| Malformed request (400) | throws, `isRetryable()` false |
| Credentials rejected (401 after refresh) | throws, `isConfigurationProblem()` true |
| Not entitled to the API (403) | throws, `isConfigurationProblem()` true |
| Rate limited (429) | throws, `isRetryable()` true, `retryAfter()` set |
| LHDN down (500/503) | throws, `isRetryable()` true |
| Module disabled | throws, message names `MYINVOIS_ENABLED` |

Accessors carry LHDN's own error envelope: `statusCode()`, `errorCode()`, `errorMalay()` (the same
message in Malay), `propertyName()`, `propertyPath()`, `target()`, `innerErrors()`, `retryAfter()`
and `correlationId()`. **Log the correlation id** — it is what LHDN support asks for.

`MyInvoisException` is deliberately **not** a `XeroBridgeException`, so an application wrapping its
Xero work in `catch (XeroBridgeException)` cannot silently swallow an LHDN fault.

---

## Caching

**The access token is cached, and that is mandatory.** LHDN allows **12 token requests per minute**
per Client ID and names "acquiring a new authentication token with every API call" an anti-pattern in
its own integration guidance. A loop validating 50 taxpayers without a cached token is blocked before
the tenth. Tokens last an hour; `MYINVOIS_TOKEN_LEEWAY` (60s) is taken off so one cannot expire in
flight.

No cache lock is taken. Unlike Xero's rotating refresh tokens, nothing here rotates — two processes
acquiring concurrently both get a valid token, and the worst case is one wasted request out of twelve.

**Validation results are not cached by default**, and turning that on is a decision you should make
consciously:

```bash
MYINVOIS_RESULT_MATCHED_TTL=86400    # cache a "yes" for a day
MYINVOIS_RESULT_UNMATCHED_TTL=300    # cache a "no" for five minutes
```

The two are asymmetric on purpose. A "yes" is a durable fact about the world; a "no" is usually a typo
the user is about to correct, and caching it makes their next attempt lie to them. A counterparty can
also be deregistered between your cache write and your invoice.

Cache keys hash the TIN and the identifier — they are personal data and should not sit in plaintext
in a Redis `MONITOR` stream or a slow log. The key also carries a **version segment**, bumped for the
1 August 2026 pairing change, so any result cached under the old weaker check is unreadable rather
than merely stale.

The validate endpoint allows **60 requests per minute** per Client ID. LHDN describes exceeding it as
"malicious activity", so a bulk sweep over a customer table wants both caching and pacing.

---

## The console panel

With the module enabled, the package's [test console](07-test-console.md) at `/xero/console` — itself
off unless `XERO_CONSOLE_ENABLED=true` — grows a **LHDN MyInvois** panel with two actions:

| Action | What it does |
|---|---|
| `myinvois.validate` | Runs one validation and shows the answer, the timing and LHDN's rate-limit headers |
| `myinvois.forget_token` | Drops the cached access token — worth doing after rotating the client secret |

Neither appears while the module is disabled: not the panel, not the actions, not a note saying it
exists. A consumer outside Malaysia sees the console exactly as it was.

---

## Every setting

| Key | Default | Notes |
|---|---|---|
| `MYINVOIS_ENABLED` | `false` | Nothing touches the network until this is true |
| `MYINVOIS_ENVIRONMENT` | `sandbox` | `sandbox` or `production`; moves the token and API host together |
| `MYINVOIS_CLIENT_ID` | none | Per environment, from the portal's ERP registration |
| `MYINVOIS_CLIENT_SECRET` | none | Ditto |
| `MYINVOIS_ON_BEHALF_OF` | none | **Intermediaries only.** A TIN, or `TIN:BRN` colon-separated |
| `MYINVOIS_TOKEN_LEEWAY` | `60` | Seconds taken off the token's own lifetime before caching |
| `MYINVOIS_TOKEN_CACHE_STORE` | default store | |
| `MYINVOIS_RESULT_MATCHED_TTL` | `0` (off) | Seconds to cache a positive answer |
| `MYINVOIS_RESULT_UNMATCHED_TTL` | `0` (off) | Seconds to cache a negative answer |
| `MYINVOIS_RESULT_CACHE_STORE` | default store | |
| `MYINVOIS_HTTP_TIMEOUT` | `30` | |
| `MYINVOIS_HTTP_CONNECT_TIMEOUT` | `10` | |
| `MYINVOIS_HTTP_RETRIES` | `3` | Total attempts; connection failures and 5xx only |
| `MYINVOIS_HTTP_RETRY_BASE_MS` | `1000` | |
| `MYINVOIS_PRODUCTION_URL` | `https://api.myinvois.hasil.gov.my` | Override only for testing |
| `MYINVOIS_SANDBOX_URL` | `https://preprod-api.myinvois.hasil.gov.my` | Ditto |
| `MYINVOIS_AUDIT` | `false` | Keeps a durable record of what LHDN answered, one row per pair checked, in a table of its own — see [MyInvois verdicts](09-persistence.md#myinvois-verdicts) |
| `MYINVOIS_AUDIT_TABLE` | `myinvois_validations` | A bare name: the connection adds its own prefix. Set it **before** the first migrate if the name is taken (below) |
| `MYINVOIS_AUDIT_RETAIN_DAYS` | `400` | Days a verdict is kept after it was last checked; `xero-bridge:prune` enforces it |
| `MYINVOIS_DB_CONNECTION` | default connection | The database connection the verdict table lives on |

> **Upgrading.** There is nothing to re-publish. On every boot that is not running from a cached config,
> the package adds any setting your published `config/myinvois.php` lacks, at any depth, with the
> package's own value for it — its env variable when set, its default otherwise — so `composer update`
> alone delivers a setting a later release adds inside `token`, `http` or any other block. A key you
> already have is never changed, whatever its value. Two consequences: a line you delete comes back with
> the package's value, so switch something off by setting it rather than deleting it; and under a config
> cache built before the update, run `php artisan config:clear` (or `config:cache`) before a new setting
> takes effect. See [Updating](../README.md#your-published-config-is-never-updated).

### The verdict table

`MYINVOIS_AUDIT=true` needs its table, which has a publish tag of its own, so the Xero migrations never
bring a Malaysian tax table into a database that has no use for one:

```bash
php artisan vendor:publish --tag=myinvois-migrations
php artisan migrate
```

If a table called `myinvois_validations` already exists on that connection — counting the connection's
prefix — set `MYINVOIS_AUDIT_TABLE` to an unused, bare name **before** you migrate, in every environment:
the package reads it at runtime too, not only when migrating. The migration will not adopt a same-named
table it did not create. It checks for the columns it would have made and, when they are missing, stops
before changing anything, naming the table, the columns it lacks and this setting. Laravel does not record
it as run, so the next `migrate` resumes there once the name is free. A rollback likewise never drops a
table this migration did not create.

That check is in the migration as published from 1.5.0 on. A copy published earlier and not yet run lacks
it; republish it first with `php artisan vendor:publish --tag=myinvois-migrations --force`, which rewrites
it in place under the same filename.

---

## Troubleshooting

**"MyInvois is disabled."** `MYINVOIS_ENABLED=true`, then `php artisan config:clear`.

**"MyInvois [client_id] is still set to a placeholder."** The value is one of a short list of obvious
non-credentials (`0`, `YOUR_CLIENT_ID`, `changeme`…). This is checked locally because LHDN
**auto-blocks** a Client ID that sends placeholder values to the token endpoint — so an unfilled
environment file does not merely fail, it can cost you the registration. Matching is exact, so a real
secret that happens to contain `xxx` is fine.

**"MyInvois refused the credentials (invalid_client)."** The ID and secret do not match, or they
belong to the other environment. Check `MYINVOIS_ENVIRONMENT`.

**HTTP 403.** The credentials are valid but not entitled to the taxpayer validation API. That is a
portal/registration problem, not a code one — do not retry it.

**Everything valid returns `false`.** Almost always the BRN rather than the TIN. Since 1 August 2026
the pair is validated together, so a stale registration number — or one still in the pre-October-2019
short format — fails even with a perfect TIN. Ask the buyer for their current SSM number and have
them confirm HASiL holds it.

**`migrate` stops at the MyInvois migration, saying the table "already exists, but it is not the table
this migration creates".** Your database already has a table by that name, and the package did not
create it. Nothing was changed. To keep that table, give the package another: set `MYINVOIS_AUDIT_TABLE`
to an unused, bare name — the connection adds its own prefix — run `php artisan config:clear` if your
config is cached, and migrate again. If the table is a leftover nothing uses, drop or rename it instead.
See [The verdict table](#the-verdict-table).

**cURL error 60, "self signed certificate in certificate chain".** This package offers no
TLS-verification switch and will not add one: turning verification off in a statutory tax integration
means accepting any certificate on the path. Fix it properly — point php.ini's `curl.cainfo` and
`openssl.cafile` at a current `cacert.pem` (usually the whole problem on stock MAMP, XAMPP or Windows
PHP), or if your network inspects TLS, add that proxy's root CA to the system trust store.
