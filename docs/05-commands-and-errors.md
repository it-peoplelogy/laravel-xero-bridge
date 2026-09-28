# Commands, errors and token lifecycle

Everything in this document is about operating the package rather than calling the Xero API: the three
Artisan commands, what keeps a connection alive, and what to catch when something fails.

Three facts drive the whole design, and they are worth reading before anything else:

1. A Xero **access token lasts 30 minutes**.
2. A Xero **refresh token rotates** — using one invalidates it and returns a new one that must be saved.
   Two processes refreshing the same connection concurrently each invalidate the other's token.
3. A refresh token that is never used **expires after 60 days**.

---

## Part 1 — Artisan commands

All three commands are registered by the service provider; there is nothing to wire up.

### `xero-bridge:install`

Publishes the config file and migration, then prints the `.env` keys, the redirect URI and the webhook URL.

```text
php artisan xero-bridge:install [--force]
```

| Option | Meaning |
|---|---|
| `--force` | Overwrite `config/xero-bridge.php` and the migration if they already exist. Without it, `vendor:publish` skips files that are present. |

**Sample output**

```text
   INFO  Installing Xero Bridge.

  Published config/xero-bridge.php ...................................... DONE
  Published the xero_connections migration .............................. DONE

Add these to your .env file:

  XERO_CLIENT_ID=              required - from your Xero app
  XERO_CLIENT_SECRET=          required - shown once, at creation
  XERO_REDIRECT_URI=           required - must match the app exactly
  XERO_WEBHOOK_KEY=            optional - only if you use webhooks
  XERO_SCOPES=                 optional - must include offline_access
  XERO_ACCOUNT_CODE=   optional - differs per organisation
  XERO_TAX_TYPE=       optional - leave unset for per-line tax
  XERO_CURRENCY=       optional - defaults to MYR
  XERO_LOCK_STORE=             recommended - redis/memcached/database

Then:
  1. php artisan migrate
  2. Create an app at https://developer.xero.com/myapps
  3. Register this redirect URI on it, exactly:
     https://app.example.com/xero/callback
  4. Visit https://app.example.com/xero/connect/default to connect an organisation

Webhook URL (paste into the Xero app's Webhooks tab):
  https://app.example.com/xero/webhook

   WARN  Schedule xero-bridge:refresh-tokens with ->withoutOverlapping()->onOneServer(). Xero rotates
   refresh tokens, so two concurrent refreshes invalidate each other.
```

If `APP_URL` is not `https`, an extra warning appears above that one:

```text
   WARN  Xero only delivers webhooks to https on port 443, so this URL will not work as-is. Set APP_URL
   to your public https address.
```

**Exit codes**

| Code | Meaning |
|---|---|
| `0` | Always. The command publishes and prints; it validates nothing and reaches no network. |

**Notes / gotchas**

- The redirect URI printed at step 3 comes from `XERO_REDIRECT_URI`, falling back to the package's own
  callback route. It must be registered on the Xero app **character for character**, including any
  trailing slash. If resolving it throws — for example because the routes are disabled and no
  `XERO_REDIRECT_URI` is set — the command degrades to printing `url('xero/callback')` rather than failing.
- The webhook URL is `routes.prefix` + `webhooks.path`, so it moves if you change `XERO_ROUTES_PREFIX`.
- Nothing here writes to `.env`. The keys are printed for you to copy.

---

### `xero-bridge:status`

Reports connection health and configuration gaps. Safe to run from monitoring on a tight loop.

```text
php artisan xero-bridge:status [--json] [--strict]
```

| Option | Meaning |
|---|---|
| `--json` | Emit machine-readable JSON instead of the table. Suppresses the human-readable errors and warnings entirely, but not their effect on the exit code. |
| `--strict` | Treat warnings (no webhook key, a non-locking cache store, recent transient failures) as a failure, returning `1`. |

**Sample output — healthy**

```text
+---------+--------------+-------+---------------------------+----------+
| Key     | Organisation | Token | Expires                   | Failures |
+---------+--------------+-------+---------------------------+----------+
| default | Acme Sdn Bhd | valid | 2026-01-15T09:30:00+00:00 | 0        |
+---------+--------------+-------+---------------------------+----------+
```

**Sample output — one connection needs re-authorising**

```text
+---------+-------------------+-----------+---------------------------+----------+
| Key     | Organisation      | Token     | Expires                   | Failures |
+---------+-------------------+-----------+---------------------------+----------+
| acme    | Acme Holdings Bhd | reconnect | 2026-01-15T09:30:00+00:00 | 0        |
| default | Acme Sdn Bhd      | valid     | 2026-01-15T09:30:00+00:00 | 0        |
+---------+-------------------+-----------+---------------------------+----------+

   ERROR  Connection [acme] must be authorised again (invalid_grant). Go to
   https://app.example.com/xero/connect/acme.
```

**Sample output — configuration incomplete**

```text
   ERROR  Configuration is incomplete:

  missing client_secret (XERO_CLIENT_SECRET)

   WARN  No Xero organisations are connected.

  Connect one at https://app.example.com/xero/connect/default
```

**Sample output — `--json`**

```json
{
    "missing_config": [],
    "connections": [
        {
            "key": "default",
            "organisation": "Acme Sdn Bhd",
            "tenant_id": "e1a4b2c6-0000-0000-0000-9f3d7c2b1a58",
            "expires_at": "2026-01-15T09:30:00+00:00",
            "expires_in": 1800,
            "expired": false,
            "needs_reauthorisation": false,
            "invalidated_reason": null,
            "failure_count": 0,
            "last_refreshed_at": null,
            "scopes": [
                "openid",
                "profile",
                "email",
                "offline_access",
                "accounting.invoices",
                "accounting.settings"
            ],
            "connect_url": "https://app.example.com/xero/connect/default"
        }
    ]
}
```

**Exit codes**

| Code | Constant | Meaning for monitoring |
|---|---|---|
| `0` | `Command::SUCCESS` | Every connection is usable. A token shown as `expired` still counts as usable — the next call refreshes it. |
| `1` | `StatusCommand::EXIT_CONFIG_INCOMPLETE` | `XERO_CLIENT_ID`, `XERO_CLIENT_SECRET` or `offline_access` in `XERO_SCOPES` is missing. Nobody can connect and nothing will refresh. Page whoever owns the deploy. Also returned by `--strict` when only warnings were found. |
| `2` | `StatusCommand::EXIT_NEEDS_REAUTH` | At least one connection is marked invalidated. **A human must visit the connect URL.** No amount of retrying fixes this. |

Configuration is checked first, so a run that is both misconfigured and holding a dead connection returns
`1`, not `2`.

**Notes / gotchas**

- **This command never calls Xero.** It reads the database and config only, which is why it is safe on a
  one-minute monitoring schedule. There is a test asserting no HTTP request escapes.
- `missing_config` is a JSON **array** `[]` when nothing is missing, and a JSON **object** when something
  is. Parse defensively:

  ```php
  <?php

  $status = json_decode($jsonOutput, true, 512, JSON_THROW_ON_ERROR);

  $missing = (array) ($status['missing_config'] ?? []);

  if ($missing !== []) {
      // Keys are config paths, values are the env var names to set.
      foreach ($missing as $configKey => $envKey) {
          fwrite(STDERR, "xero-bridge: {$configKey} is not set ({$envKey})\n");
      }
  }
  ```

- `expires_in` is signed. A token that expired ten minutes ago reports `-600`.
- Tokens never appear in the output, in either format.
- The three warnings are: no `XERO_WEBHOOK_KEY` while webhooks are enabled (every webhook will be
  rejected with a 401); a cache store that cannot lock across processes; and any connection whose
  `failure_count` is above zero without being invalidated.
- With `--json`, warnings are not printed at all — but `--strict` still turns them into exit code `1`.
  If you script against the JSON, do not also pass `--strict` unless you want a silent non-zero.

---

### `xero-bridge:refresh-tokens`

Refreshes every stored connection whose access token is close to expiry. This is the command you schedule.

```text
php artisan xero-bridge:refresh-tokens [--connection=KEY] [--window=1800] [--force]
```

| Option | Default | Meaning |
|---|---|---|
| `--connection=` | all connections | Only refresh this connection key. An unknown key is an error, not a no-op. |
| `--window=` | `1800` | Refresh any token expiring within this many seconds. The default is a full access-token lifetime, so an hourly schedule always has something to do. |
| `--force` | off | Refresh even when the token is still fresh. Burns a rotation; use it to prove the credentials work, not on a schedule. |

**Sample output — mixed run**

```text
  acme ........................................................... still fresh
  default (Acme Sdn Bhd) ............................ refreshed until 09:30:00
```

**Sample output — transient failure (exit 1)**

```text
   WARN  [default] Could not reach Xero to refresh the token for connection [default]: Xero returned
   HTTP 503. The stored tokens are unchanged and this can safely be retried.
```

**Sample output — re-authorisation required (exit 2)**

```text
   ERROR  [default] The Xero connection [default] must be authorised again (refresh token expired).
   Re-authorise at https://app.example.com/xero/connect/default.
```

**Sample output — another process is already refreshing (exit 0)**

```text
  default ................................................... skipped (locked)
```

**Sample output — nothing to do**

```text
   INFO  No Xero connections to refresh.
```

**Exit codes**

| Code | Constant | Meaning for monitoring |
|---|---|---|
| `0` | `Command::SUCCESS` | Every connection was refreshed, was already fresh, or was skipped because another process holds the lock. All three are healthy. |
| `1` | `RefreshTokensCommand::EXIT_TRANSIENT_FAILURE` | At least one connection could not be refreshed **and nothing was changed**. Retry later. Alert only if it persists across several runs. Also returned when `--connection` names a key that does not exist. |
| `2` | `RefreshTokensCommand::EXIT_NEEDS_REAUTH` | At least one connection needs a human. Alert immediately. |

The command refreshes every connection before returning, and the exit code is the **highest** code any one
connection produced. A run where one connection 503s and another is invalidated returns `2`.

**Notes / gotchas**

- Exit `1` is overloaded: it covers a transient failure, a `--connection` key that does not exist, and —
  through the command's catch-all — any other error, including the `XeroConfigurationException` raised when
  Xero rejects the client credentials, which no amount of retrying fixes. Distinguish them by the output,
  or avoid `--connection` in automation.
- "skipped (locked)" is **not** a failure. It means a concurrent run already holds the per-connection lock
  and is doing the work. Deliberately exit `0`.
- An already-invalidated connection short-circuits: the command reports it and returns `2` without any
  network call at all. A five-minute schedule over a dead connection therefore does not hammer
  `identity.xero.com`.
- `--window` is passed all the way through to the lock re-check. Without that, the re-check inside the lock
  would apply the 60-second leeway instead, decide a token expiring in five minutes needed nothing doing,
  and the scheduled job would quietly never refresh anything.

---

## Part 2 — The token lifecycle

### What Xero guarantees

| Fact | Value | Consequence |
|---|---|---|
| Access token lifetime | 30 minutes | Every long-running process must be able to refresh. |
| Refresh token rotation | On every use | The new refresh token **must** be persisted or the connection is lost. |
| Rotation grace window | ~30 minutes | If you rotate but fail to save, the previous refresh token still works for about half an hour. |
| Idle refresh-token expiry | 60 days | A connection nobody touches for two months dies. This is why you schedule the command. |
| `offline_access` scope | Mandatory | Without it Xero issues no refresh token at all and the connection dies 30 minutes after it is created. |

### How a refresh is triggered

There are three entry points, and you normally only use the first two implicitly:

1. **Before any API call.** `TokenManager::valid()` refreshes when the token expires within
   `tokens.refresh_leeway` (`XERO_REFRESH_LEEWAY`, default **60** seconds). The leeway exists so a token
   with eight seconds left is not handed to a request that takes ten.
2. **After an unexpected 401.** `XeroHttpClient` refreshes once and replays the request exactly once —
   straight-line code, not a loop. A second 401 throws `XeroAuthenticationException`. The guard is the
   specific access token that failed, not a boolean, so a thundering herd of 401s produces one rotation
   rather than one per caller.
3. **On a schedule**, via `xero-bridge:refresh-tokens`.

An insufficient-scope 401 is detected **before** the refresh path (Xero sends
`WWW-Authenticate: insufficent_scope` — its own spelling, which the package matches alongside the correct
one) and throws `XeroScopeException` immediately. Refreshing would never fix it, and retrying it in a loop
is how an integration ends up spinning forever.

### The per-connection lock

Every refresh runs inside a cache lock named `xero-bridge:refresh:{connection-key}`. The three timings
interlock deliberately:

```text
tokens.http_timeout (8s)  <  tokens.lock_ttl (10s)  <  tokens.lock_wait (12s)
```

A lock holder can never outlive its lock, and a waiter can never give up before a dead holder's lock has
expired. If the wait times out, the package re-reads the row: if the winner refreshed, its token is
returned; otherwise a `XeroBridgeException` with "Timed out waiting" is thrown. It deliberately does **not**
fall back to an unlocked refresh — that is precisely what rotates a refresh token out from under the
process legitimately holding the lock.

> ### ⚠️ `XERO_LOCK_STORE` must be redis, memcached or database
>
> The lock is only as good as the store behind it. Laravel's `array` and `file` cache stores give **no
> cross-process guarantee**, so with either of them two PHP-FPM workers or two queue workers can refresh
> the same connection simultaneously and invalidate each other's refresh token.
>
> ```dotenv
> XERO_LOCK_STORE=redis
> ```
>
> If the configured store does not implement `LockProvider` at all, the package logs a warning **once** per
> process and proceeds unlocked — a single-process application is still perfectly usable. It does not fail
> closed, so the warning is the only signal you get. `xero-bridge:status` also reports this.

### The scheduling recipe

```php
<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('xero-bridge:refresh-tokens')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
```

> ### ⚠️ Both chained methods are mandatory
>
> `withoutOverlapping()` stops a slow run overlapping the next tick **on the same server**.
> `onOneServer()` stops the same tick running on every server behind your load balancer.
>
> Drop either one and you get two concurrent refreshes of the same connection, each invalidating the
> other's rotated refresh token. The cache lock inside `TokenManager` is the second line of defence, not
> the first — and `onOneServer()` itself requires a locking cache store, which is the same requirement as
> above.

Hourly is the right frequency: the window defaults to 1800 seconds, so each run refreshes anything due
within the next half hour, and a connection idle for weeks never approaches the 60-day cliff.

### The guarantee: a transient failure never alters stored tokens

This is the single most important behaviour in the package. Failures are classified into exactly three
buckets, because the correct reaction to each is completely different:

| What happened | Exception | Stored tokens | `invalidated_at` | Event |
|---|---|---|---|---|
| Timeout or connection error reaching `identity.xero.com` | `XeroIdentityUnavailableException` | untouched | untouched | none |
| HTTP 5xx from the identity host | `XeroIdentityUnavailableException` | untouched | untouched | none |
| HTTP 429 from the identity host | `XeroIdentityUnavailableException` | untouched | untouched | none |
| `{"error":"invalid_client"}`, `{"error":"unauthorized_client"}` or a 401 | `XeroConfigurationException` | untouched | untouched | none |
| Rotation succeeded but the database write failed | the underlying `Throwable` | previous pair still stored | untouched | none, plus a `critical` log line |
| **`{"error":"invalid_grant"}`** | `XeroReauthorizationRequiredException` | untouched | **set** | `ConnectionExpired`, once |

Two invariants hold throughout, and both are pinned by tests:

- **Nothing ever deletes a connection row.** The most destructive action available is setting
  `invalidated_at`, which reconnecting clears.
- **Only a genuine `invalid_grant` is terminal.** A transient failure increments `failure_count` and sets
  `last_failure_at`. That counter is the *only* thing that changes.

`invalid_client` deserves its own row above: it means your application's own credentials are wrong. Marking
every connection as expired for that would demand a re-consent from every user when the actual fix is one
line of `.env`.

### Events to hang alerting on

```php
<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Peoplelogy\XeroBridge\Events\ConnectionExpired;
use Peoplelogy\XeroBridge\Events\TokenRefreshed;

Event::listen(function (ConnectionExpired $event): void {
    Log::critical('A Xero connection needs re-authorising.', [
        'connection' => $event->connection->key,
        'reason' => $event->reason,
        'connect_url' => $event->connectUrl,
    ]);
});

Event::listen(function (TokenRefreshed $event): void {
    Log::info('Xero token rotated.', [
        'connection' => $event->connection->key,
        'expires_at' => $event->expiresAt?->toIso8601String(),
    ]);
});
```

`ConnectionExpired` fires **only on the valid → invalidated transition**, so a nightly cron hitting the same
dead connection cannot spam your listeners. That makes it safe to wire straight to a pager.

### `APP_KEY` rotation

Both tokens use Laravel's `encrypted` cast. Rotate `APP_KEY` and every stored token becomes undecryptable.
The package catches the `DecryptException` and rethrows `XeroConfigurationException` naming `APP_KEY` and
the connect URL, rather than letting a bare decryption error surface from inside Eloquent with nothing
linking it to Xero. Every organisation must reconnect.

Related, and worth knowing before you add any model observer: do **not** attach an activity-log trait to
`XeroConnection`. The `encrypted` cast protects the tokens at rest, not once the model is hydrated — an
activity log, `toArray()`, a JSON response or `Log::info($model)` all emit the decrypted value. The model
declares both tokens in `$hidden` to close the serialisation half of that hole.

---

## Part 3 — Exceptions

### The hierarchy

Everything the package throws for a Xero, connection or payload failure extends `XeroBridgeException`,
which extends `RuntimeException`. Subclasses are discriminated by **what the caller must do differently**,
not by which endpoint failed.

```text
RuntimeException
└── XeroBridgeException
    ├── XeroAuthenticationException
    │   ├── XeroReauthorizationRequiredException
    │   └── XeroScopeException
    ├── XeroConfigurationException
    ├── XeroConnectionNotFoundException
    ├── XeroIdentityUnavailableException
    ├── XeroRateLimitException
    ├── XeroRequestException
    ├── XeroServiceUnavailableException
    ├── XeroValidationException
    ├── ConnectionKeyConflictException
    ├── TenantAlreadyConnectedException
    ├── InvalidInvoicePayloadException
    ├── InvalidInvoiceTransitionException
    ├── InvoiceCannotBeVoidedException
    └── UnsafeContactPayloadException
```

`catch (XeroBridgeException $e)` is the coarse catch for everything in that tree, and every accessor below
is available whichever subclass arrives. It is **not** a catch-all for the package: an argument rejected
locally, before any request is built, throws a plain `InvalidArgumentException` — an invoice type that is
not ACCREC or ACCPAY, an order direction that is not ASC or DESC, a page below 1, a payment with no
account, an empty or malformed contact `Name`, an unparseable date. Those are caller bugs, they extend
`LogicException` rather than `RuntimeException`, and they carry none of the accessors below.

> `XeroReauthorizationRequiredException` and `XeroScopeException` both extend `XeroAuthenticationException`.
> If you catch the parent, **catch the two children first** or you will never reach them.

### What each one means and what to do

| Exception | Means | Do |
|---|---|---|
| `XeroConfigurationException` | `.env` or `config/xero-bridge.php` is wrong: a missing client ID or secret, an `http://127.0.0.1` redirect URI (Xero rejects it — use `http://localhost`), a non-https redirect URI, `offline_access` absent from the scopes, credentials rejected by Xero, undecryptable tokens after an `APP_KEY` rotation, or an invalid conflict-policy value. | Fix the configuration and deploy. **Nothing is wrong with the connection** and nothing is retried. Fail the job. |
| `XeroConnectionNotFoundException` | No row is stored under that connection key. | Send someone through the consent flow. The message carries the connect URL. Fail the job. |
| `XeroReauthorizationRequiredException` | Terminal. Xero answered `invalid_grant`, or the connection is already marked invalidated. | **A human must reconnect.** Fail the job and alert. Retrying is pointless; the connection short-circuits without a network call. |
| `XeroScopeException` | Xero refused for insufficient scope (a 401 with `WWW-Authenticate: insufficent_scope`). | Widen `XERO_SCOPES`, redeploy, then re-consent. Scopes are fixed at authorisation time, so doing it the other way round means connecting twice. `grantedScopes()` tells you what the connection actually holds. Refreshing never helps. |
| `XeroAuthenticationException` | A 401 or 403 that a refresh did not fix — usually the connection was disconnected inside Xero, or the tenant is no longer authorised for the app. | Treat as terminal. Check the organisation's connected apps. |
| `XeroIdentityUnavailableException` | Transient failure talking to `identity.xero.com` — timeout, connection error, 5xx or 429. Stored tokens are untouched. | Retry later. Safe to release the job. |
| `XeroRateLimitException` | HTTP 429 that the package refused to absorb. | `release($e->retryAfter())`. Never sleep inline: a daily-limit `Retry-After` can be hours. `limitProblem()` says which limit. |
| `XeroServiceUnavailableException` | 500/502/503/504, including the plain-text "The Organisation is offline" and "offline for maintenance" bodies. | Retry later. `retryAfter()` defaults to 300 seconds for these. |
| `XeroValidationException` | HTTP 400, `Type: ValidationException`. Xero rejected the payload. | Fix the payload. Read `validationErrors()`. **Do not retry** — it will fail identically. |
| `XeroRequestException` | Any other non-2xx with no more specific subclass: 404, 405, 409, and a 400 carrying no validation errors. Also thrown when Xero returns XML, which means the `Accept: application/json` header went missing. | Inspect `statusCode()` and the message. Usually a bug in the caller. |
| `ConnectionKeyConflictException` | The connection key already points at a **different** Xero organisation and `on_key_conflict` is `error`. | Disconnect the old organisation first, or set `XERO_ON_KEY_CONFLICT=replace`. Thrown during the OAuth callback. |
| `TenantAlreadyConnectedException` | The organisation just authorised is already stored under a **different** key and `on_tenant_conflict` is `error` (the default). | Use the existing connection, or disconnect it first. Silently re-keying would break every caller referencing the old key. |
| `InvalidInvoicePayloadException` | An invoice has no explicit `Type`, no `Contact`, or an update carries `LineItems` without `LineItemID` on every line. | Fix the payload. The `Type` is never defaulted on purpose: guessing `ACCREC` would, on the one occasion someone meant `ACCPAY`, post a bill as a sale. |
| `InvalidInvoiceTransitionException` | An illegal status change, caught locally. The message names the current status and what is reachable from it. | Fix the caller's logic. |
| `InvoiceCannotBeVoidedException` | The invoice has payments applied. The message names the blocking payment IDs. | Delete the payments with `payments()->delete($paymentId)`, then void. |
| `UnsafeContactPayloadException` | An invoice `Contact` block carries a `ContactID` **plus** other contact fields. Xero would apply them to the contact record itself and delete any `ContactPersons` not included. | Send only `ContactID`, or call `withContactMutation()` to confirm you mean it. This is irreversible and nobody notices for weeks, which is why it is refused rather than merely documented. |

### Accessors

Available on every exception in the hierarchy — signatures exactly as in
`src/Exceptions/XeroBridgeException.php`:

```text
public function response(): ?\Illuminate\Http\Client\Response
public function statusCode(): ?int
public function connectionKey(): ?string
public function validationErrors(): array           // list<string>
public function validationErrorsByElement(): array  // array<int, list<string>>
public function xeroType(): ?string
public function xeroErrorNumber(): ?int
public function retryAfter(): ?int
public function context(): array                    // array<string, mixed>
```

Plus two subclass-specific ones:

```text
// src/Exceptions/XeroRateLimitException.php
public function limitProblem(): ?string   // 'minute' | 'day' | 'concurrent' | 'appminute' | null

// src/Exceptions/XeroScopeException.php
public function grantedScopes(): array    // list<string>
```

| Accessor | Returns | Use it for |
|---|---|---|
| `statusCode()` | The HTTP status, or `null` for a locally raised error such as `InvalidInvoicePayloadException`. | Branching on 404 versus 409 inside `XeroRequestException`. |
| `retryAfter()` | Seconds, where Xero told us or the package supplied a sane default. `null` otherwise. | `release()` on a queued job. |
| `validationErrors()` | Every `ValidationErrors[].Message` flattened and de-duplicated. | Showing a user what to fix. |
| `validationErrorsByElement()` | The same messages keyed by index in `Elements[]`. | Reporting "row 3 of the bulk POST failed because…". |
| `xeroType()` / `xeroErrorNumber()` | Xero's `Type` and `ErrorNumber` from the 400 envelope. | Logging and triage. |
| `connectionKey()` | Which connection was in play. | Multi-organisation apps. |
| `response()` | The raw `Illuminate\Http\Client\Response`. | Last resort. **Never log it** — the request it carries holds the bearer token. |
| `context()` | A filtered array of connection, status, Xero type, error number, retry-after and validation errors. | Passing straight to `Log::error()`. |

### `context()` is the only thing you should log

```php
<?php

use Illuminate\Support\Facades\Log;
use Peoplelogy\XeroBridge\Exceptions\XeroBridgeException;

try {
    $invoice = \Peoplelogy\XeroBridge\Facades\XeroBridge::invoices()->create($payload);
} catch (XeroBridgeException $e) {
    Log::error('Xero call failed: '.$e->getMessage(), $e->context());

    throw $e;
}
```

`context()` deliberately excludes the request headers and body, which carry the bearer token. **No exception
message and no `context()` value ever contains an access token, a refresh token or the client secret**, and
there is a test asserting it stays that way. Null and empty entries are stripped, so the array is short.

A representative value:

```php
<?php

// What $e->context() returns for a rejected invoice.
$context = [
    'connection' => 'default',
    'status' => 400,
    'xero_type' => 'ValidationException',
    'xero_error_number' => 10,
    'validation_errors' => [
        'Invoice # must be unique.',
    ],
];
```

### What Xero actually sends

Xero speaks four different error dialects and the package reads all of them, which is why you can branch on
a class rather than on `str_contains($e->getMessage(), ...)`.

**a) HTTP 400 — the validation envelope** → `XeroValidationException`

```json
{
  "ErrorNumber": 10,
  "Type": "ValidationException",
  "Message": "A validation exception occurred",
  "Elements": [
    {
      "AccountID": "00000000-0000-0000-0000-000000000000",
      "Code": "123456",
      "Name": "Foobar",
      "Type": "EXPENSE",
      "Description": "Hello World",
      "ValidationErrors": [
        { "Message": "Please enter a unique Name." }
      ]
    }
  ]
}
```

Xero's own documentation uses `Message` in one bulk example and `Description` in another for the same
feature, so the package reads both. It also walks the resource collection — the shape a
`summarizeErrors=false` bulk response uses, where each rejected item carries `StatusAttributeString: "ERROR"`
and its own `ValidationErrors`:

```json
{
  "Id": "bd364af7-08f0-432b-81db-c1e5ba05f3dd",
  "Status": "OK",
  "DateTimeUTC": "/Date(1552351488159)/",
  "Invoices": [
    {
      "InvoiceID": "0032a5d3-9c1a-4c0b-9c1e-6b0f6f3a1f21",
      "Type": "ACCREC",
      "Status": "DRAFT",
      "Date": "/Date(1551916800000+0000)/",
      "DueDate": "/Date(1552435200000+0000)/",
      "UpdatedDateUTC": "/Date(1551981568133+0000)/",
      "CurrencyCode": "MYR",
      "Total": 148062.76,
      "StatusAttributeString": "ERROR",
      "ValidationErrors": [
        { "Message": "Invoice # must be unique." }
      ]
    }
  ]
}
```

Note that response is an HTTP **200**. `invoices()->createMany()` sends `summarizeErrors=false` precisely so
one bad row does not reject the batch, and returns a `BatchResult` — so partial failures arrive through
`$result->failed()` and `$result->errorMessages()`, not as an exception. Call
`$result->throwIfAnyFailed()` if you would rather have one.

**b) HTTP 401/403 — the PascalCase problem envelope** → `XeroAuthenticationException`

```json
{
  "Type": null,
  "Title": "Unauthorized",
  "Status": 401,
  "Detail": "AuthenticationUnsuccessful",
  "Instance": "6a7f1b4e-0000-0000-0000-2d9c8e5a3f10"
}
```

**c) HTTP 503 — plain text, not JSON** → `XeroServiceUnavailableException`

```text
The Organisation is offline
```

**d) `identity.xero.com` — snake_case OAuth** → classified by `error`

```json
{
  "error": "invalid_grant",
  "error_description": "refresh token expired"
}
```

Building the message from these envelopes rather than relying on Laravel's `->throw()` also sidesteps its
120-character truncation of `RequestException` messages, which would otherwise hide the very validation
errors you need.

### A worked queued job

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Peoplelogy\XeroBridge\Exceptions\XeroConfigurationException;
use Peoplelogy\XeroBridge\Exceptions\XeroConnectionNotFoundException;
use Peoplelogy\XeroBridge\Exceptions\XeroIdentityUnavailableException;
use Peoplelogy\XeroBridge\Exceptions\XeroRateLimitException;
use Peoplelogy\XeroBridge\Exceptions\XeroReauthorizationRequiredException;
use Peoplelogy\XeroBridge\Exceptions\XeroScopeException;
use Peoplelogy\XeroBridge\Exceptions\XeroServiceUnavailableException;
use Peoplelogy\XeroBridge\Exceptions\XeroValidationException;
use Peoplelogy\XeroBridge\Facades\XeroBridge;

class CreateXeroInvoice implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 8;

    public function __construct(public int $orderId) {}

    public function handle(): void
    {
        $order = Order::findOrFail($this->orderId);

        if ($order->xero_invoice_id !== null) {
            return;
        }

        try {
            $invoice = XeroBridge::invoices()->create([
                'Type' => 'ACCREC',
                'Contact' => ['ContactID' => $order->xero_contact_id],
                'Date' => $order->invoiced_on->toDateString(),
                'DueDate' => $order->due_on->toDateString(),
                'Reference' => $order->reference,
                'Status' => 'AUTHORISED',
                'LineItems' => [[
                    'Description' => $order->description,
                    'Quantity' => 1,
                    'UnitAmount' => $order->amount,
                ]],
            ], "order-{$order->id}");

            // Persist immediately. This, not the idempotency key, is the real
            // defence against a duplicate: Xero retains a key for six minutes
            // only, and any realistic backoff is longer than that.
            $order->update(['xero_invoice_id' => $invoice['InvoiceID']]);
        }

        // --- Wait and try again. Nothing is wrong with the payload. ---------

        catch (XeroRateLimitException $e) {
            // A daily-limit Retry-After can be hours, so never sleep inline.
            Log::warning('Xero rate limit hit.', $e->context());

            $this->release($e->retryAfter() ?? 300);
        } catch (XeroServiceUnavailableException $e) {
            // "The Organisation is offline" defaults to 300 seconds.
            $this->release($e->retryAfter() ?? 300);
        } catch (XeroIdentityUnavailableException $e) {
            // The stored tokens are untouched; this is always safe to retry.
            $this->release(120);
        }

        // --- Terminal. Retrying cannot help. --------------------------------
        // These two extend XeroAuthenticationException, so they must be caught
        // before any catch of the parent class.

        catch (XeroReauthorizationRequiredException | XeroScopeException $e) {
            Log::critical($e->getMessage(), $e->context());

            $this->fail($e);
        } catch (XeroConnectionNotFoundException | XeroConfigurationException $e) {
            Log::critical($e->getMessage(), $e->context());

            $this->fail($e);
        } catch (XeroValidationException $e) {
            // Identical input produces an identical rejection.
            $order->update(['xero_error' => implode('; ', $e->validationErrors())]);

            $this->fail($e);
        }
    }
}
```

The shape to copy: **release** for the three transient classes, **fail** for the terminal ones, and never
`release()` a `XeroValidationException` — the same payload will be rejected identically every time, and you
will simply burn quota until `$tries` runs out.

---

## Rate limits

Per organisation, Xero enforces:

| Limit | Value | `X-Rate-Limit-Problem` |
|---|---|---|
| Per minute | 60 calls | `minute` |
| Concurrent | 5 calls | `concurrent` |
| Per day | 1,000 on Starter, 5,000 above it | `day` |
| Per minute, whole app across all tenants | 10,000 calls | `appminute` |

Every response — not only a 429 — carries `X-MinLimit-Remaining`, `X-DayLimit-Remaining` and
`X-AppMinLimit-Remaining`. The package records them from each response and logs a `notice` when the minute
allowance drops below 5 or the daily allowance below 100. Read them yourself with:

```php
<?php

use Peoplelogy\XeroBridge\Facades\XeroBridge;

$status = XeroBridge::client()->lastRateLimit();

if ($status !== null && $status->isRunningLow()) {
    // ->minuteRemaining, ->dayRemaining, ->appMinuteRemaining, ->problem, ->retryAfter
    report_quota($status->toArray());
}
```

### What the package absorbs, and what it hands you

`http.retries` is **total attempts including the first**, defaulting to 3 (one try plus two retries).

| Situation | Absorbed? | Behaviour |
|---|---|---|
| Connection error or timeout | Yes | Always retried — the request may never have reached Xero. |
| 429 with `Retry-After` ≤ 30s (`http.retry_max_ms`) | Yes | Sleeps exactly that long. Laravel's HTTP client has no `Retry-After` support of its own; this is entirely the package's doing. |
| 429 with no `Retry-After` (the concurrency limit) | Yes | Short exponential backoff from 250ms, capped at 2 seconds. The concurrency limit frees in milliseconds, so backing off for a minute would be absurd. |
| 429 with `Retry-After` > 30s (typically the daily limit) | **No** | Thrown as `XeroRateLimitException` carrying the full `retryAfter()`, so a queued job can release itself rather than pin a worker for hours. |
| 500, 502, 504, and any 503 that is not an outage message | Yes | Exponential backoff from 1s with jitter, capped at 30s. If every attempt fails it is thrown as `XeroServiceUnavailableException`. |
| 503 "The Organisation is offline" / "offline for maintenance" | **No** | Thrown immediately with `retryAfter()` of 300. Xero suggests about five minutes, which is not an inline wait. |
| 401 or 403 | **No** | Never blind-retried. A 401 gets exactly one token refresh and one replay; an insufficient-scope 401 gets neither. |
| Any write (`POST`/`PUT`/`PATCH`/`DELETE`) when `http.idempotency` is `false` | **No** | Writes are not retried at all without an `Idempotency-Key`, because a retried POST that actually succeeded creates a duplicate invoice. |

When the package refuses to sleep, it guarantees `retryAfter()` is populated: if Xero sent no header, the
client fills it from `http.offline_retry_after` (default 300). So `$e->retryAfter() ?? 300` in the job above
is belt and braces rather than a real fallback.

Two things quota-related worth knowing: idempotency keys are checked **after** rate limiting, so duplicate
requests still consume quota; and a key is capped at 128 characters and retained by Xero for six minutes only.
