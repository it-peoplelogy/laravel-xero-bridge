# Webhooks and events

Two separate things share this page because they meet in one place: Xero's webhook delivery ends in a
Laravel event, and everything else the package wants to tell your application is an event too.

- **Webhooks** — Xero pushes a notification to your application when something changes in a connected
  organisation. The package verifies it, queues it, and hands you one Laravel event per item.
- **Events** — six plain PHP event classes the package dispatches. None of them implement
  `ShouldBroadcast`; they are ordinary Laravel events, discovered or registered like any other.

---

## Part 1 — The webhook endpoint

### What Xero actually sends you

A Xero webhook tells you **that** something changed. It does not tell you **what** changed. There is no
invoice body in the payload — you get an identifier and a URL, and you fetch the record yourself.

This is deliberate on Xero's side: the payload crosses the public internet, so it carries no financial
data.

### Setting it up in the Xero app

1. Open your app at <https://developer.xero.com/myapps> and go to the **Webhooks** tab.
2. Paste your delivery URL. `php artisan xero-bridge:install` prints it for you; by default it is:

   ```
   https://your-app.example.com/xero/webhook
   ```

   > ⚠️ Xero delivers webhooks **only to https on port 443**. Not http, not port 8443, not an IP
   > address. The install command warns you when the URL it prints is not `https://`; it does not
   > inspect the port, so a non-443 one is on you.

3. Choose the event categories you want (Xero's own UI offers Invoices and Contacts, among others).
4. Copy the **signing key** Xero shows you into `.env`:

   ```dotenv
   XERO_WEBHOOK_KEY=your-webhook-signing-key
   ```

5. Press **Save**, then **Send "Intent to receive"** in the Xero UI. See
   [Intent to receive](#intent-to-receive) below for what has to pass.

The signing key is per Xero app and is unrelated to `XERO_CLIENT_SECRET`. Rotating it in the Xero UI
takes effect immediately, so deploy the new value before you rotate.

> ⚠️ With `XERO_WEBHOOK_KEY` unset, the endpoint rejects **every** webhook with a 401. This is
> intentional — it fails closed rather than accepting unsigned traffic — but it means a missing env var
> looks exactly like an attack. `php artisan xero-bridge:status` warns you about it.

### The route

| | |
|---|---|
| Method and path | `POST /xero/webhook` |
| Route name | `xero-bridge.webhook` |
| Controller | `Peoplelogy\XeroBridge\Http\Controllers\XeroWebhookController` (invokable) |
| Middleware | `EnsureCookielessResponse` only |
| Explicitly excluded | `StartSession`, `EncryptCookies`, `AddQueuedCookiesToResponse` |
| CSRF | none — the HMAC signature is the authentication |
| Registered by | `routes/webhook.php`, loaded when `xero-bridge.webhooks.enabled` is true |

The path is assembled from two config values, so both move it:

```php
// config/xero-bridge.php
'routes' => ['prefix' => env('XERO_ROUTES_PREFIX', 'xero')],
'webhooks' => ['path' => env('XERO_WEBHOOK_PATH', 'webhook')],
```

The webhook route is registered from its own file, behind its own flag, separately from the
connect/callback routes. That is not tidiness. The connect routes **need** a session to carry the OAuth
state; the webhook route must have **no** session and **no** cookies, because any cookie in the response
fails Xero's validation. They cannot share a middleware stack.

> ⚠️ `php artisan route:cache` bakes in whatever `webhooks.enabled` and `routes.enabled` were at cache
> time. Changing the env var afterwards does nothing until you re-cache.

### Payload shape

Xero's **webhook** payload is camelCase, unlike every other Xero API response, which is PascalCase.
This catches people out constantly. The record you then fetch from `resourceUrl` is PascalCase with
.NET dates; the envelope that told you to fetch it is not. The one exception is the optional `data`
object, whose keys are PascalCase like the rest of the API.

```json
{
  "events": [
    {
      "resourceUrl": "https://api.xero.com/api.xro/2.0/Invoices/ed255415-e141-4150-aab7-89c3bbbb851c",
      "resourceId": "ed255415-e141-4150-aab7-89c3bbbb851c",
      "eventDateUtc": "2026-09-28T01:15:39.902",
      "eventType": "UPDATE",
      "eventCategory": "INVOICE",
      "tenantId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
      "tenantType": "ORGANISATION"
    },
    {
      "resourceUrl": "https://api.xero.com/api.xro/2.0/Contacts/430fa14a-f945-44d3-9f97-5df5e28441b8",
      "resourceId": "430fa14a-f945-44d3-9f97-5df5e28441b8",
      "eventDateUtc": "2026-09-28T01:15:41.117",
      "eventType": "CREATE",
      "eventCategory": "CONTACT",
      "tenantId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
      "tenantType": "ORGANISATION"
    }
  ],
  "firstEventSequence": 1,
  "lastEventSequence": 2,
  "entropy": "S0m3r4Nd0mt3xt"
}
```

**Envelope** — modelled by `Peoplelogy\XeroBridge\Webhooks\WebhookEnvelope`:

| Field | PHP property | Type | Meaning |
|---|---|---|---|
| `events` | `$events` | `list<WebhookEvent>` | The notifications. Empty on the validation ping. |
| `firstEventSequence` | `$firstEventSequence` | `int` | Sequence number of the first item in this delivery. |
| `lastEventSequence` | `$lastEventSequence` | `int` | Sequence number of the last item. |
| `entropy` | `$entropy` | `string` | Random string Xero adds so two otherwise identical payloads never hash the same. |

All four are declared required by Xero, not just `events`. The package defaults the three scalars
(`0`, `0`, `''`) rather than throwing, so a malformed delivery still reaches your listeners.

**Per event** — modelled by `Peoplelogy\XeroBridge\Webhooks\WebhookEvent`:

| Field | PHP property | Type | Meaning |
|---|---|---|---|
| `resourceUrl` | `$resourceUrl` | `string` | Absolute Accounting API URL of the changed record. |
| `resourceId` | `$resourceId` | `string` | The record's GUID — the last path segment of `resourceUrl`. |
| `eventDateUtc` | `$eventDateUtc` | `string` | `2026-09-28T01:15:39.902`, UTC, with **no trailing `Z`**. |
| `eventType` | `$eventType` | `string` | `CREATE` or `UPDATE`. Compare case-insensitively — see below. |
| `eventCategory` | `$eventCategory` | `string` | `INVOICE`, `CONTACT`, `CREDITNOTE`, … |
| `tenantId` | `$tenantId` | `string` | Which connected organisation this came from. |
| `tenantType` | `$tenantType` | `string` | `ORGANISATION`, or `APPLICATION` for Xero App Store subscription events. Defaulted to `ORGANISATION` when Xero omits it. |
| `data` | `$data` | `?array` | Present on CreditNote, Prepayment and Overpayment events only; `null` otherwise. PascalCase keys (`Type`, `Status`, and `UpdatedDateUTCString` on the two payment kinds). |

> ⚠️ **`eventType` must be compared case-insensitively.** Xero's own OpenAPI specification declares the
> constant as `UPDATE`, while Xero's webhook documentation shows `Update`. A strict `===` comparison
> against either spelling is a bug waiting for the other one. Use `$event->isUpdate()` /
> `$event->isCreate()`, which use `strcasecmp()`. There is a test pinning all three spellings.

An unfamiliar `eventCategory` is passed through untouched rather than rejected, so a category Xero adds
later reaches your listener without a package upgrade.

### Signature scheme

Xero signs the **raw request body** with your signing key and sends the result in the
`x-xero-signature` header:

```
base64( HMAC-SHA256( raw request body, signing key ) )
```

Two mistakes fail every delivery, and the package has a test for each:

- **Hashing a re-encoded body.** `json_encode($request->all())` reorders keys and changes whitespace.
  The controller reads `$request->getContent()` — the bytes exactly as they arrived.
- **Hex instead of binary.** `hash_hmac(..., false)` returns hex; base64-encoding *that* produces a
  plausible-looking string that never matches. The fourth argument must be `true`.

#### compute()

Produces the signature Xero would send for a given body. Used by the verifier, and by your tests.

```php
// signature exactly as in the source
public static function compute(string $rawBody, string $key): string
```

**Parameters**

| Name | Type | Notes |
|---|---|---|
| `$rawBody` | `string` | The raw bytes of the request body. Never a re-encoded array. |
| `$key` | `string` | The webhook signing key from the Xero app. |

**Request**

```php
use Peoplelogy\XeroBridge\Webhooks\WebhookSignature;

$raw = '{"events":[],"firstEventSequence":0,"lastEventSequence":0,"entropy":"S0m3r4Nd0mt3xt"}';

$signature = WebhookSignature::compute($raw, config('xero-bridge.webhook_key'));
```

**Response**

A base64 string, 44 characters, e.g. `q3tPLuq4k8Z0EXAMPLEEXAMPLEEXAMPLEEXAMPLEEXA=`.

**Notes / gotchas**

- The header name is available as `WebhookSignature::HEADER` (`'x-xero-signature'`), so you do not have
  to spell it out in tests.

#### isValid()

Constant-time check of an incoming signature.

```php
// signature exactly as in the source
public static function isValid(string $rawBody, ?string $signature, ?string $key): bool
```

**Parameters**

| Name | Type | Notes |
|---|---|---|
| `$rawBody` | `string` | Raw bytes, as above. |
| `$signature` | `?string` | The `x-xero-signature` header, or `null` when absent. |
| `$key` | `?string` | The configured signing key, or `null` when unconfigured. |

**Request**

```php
$valid = WebhookSignature::isValid(
    $request->getContent(),
    $request->header(WebhookSignature::HEADER),
    config('xero-bridge.webhook_key'),
);
```

**Response**

`true` or `false`.

**Notes / gotchas**

- Comparison is `hash_equals()`, not `===`, so the check does not leak timing information.
- A `null` or empty key returns `false` — it **fails closed**. An unconfigured application rejects
  everything rather than accepting everything.
- Never throws. A bad signature is an expected condition, not an exception.

### Intent to receive

When you press **Send "Intent to receive"** in the Xero UI, Xero posts several payloads and checks all
of the responses. Xero deliberately sends both correctly- and incorrectly-signed bodies, so getting
*either* wrong fails the whole check.

| Rule | How the package satisfies it |
|---|---|
| 2xx within **5 seconds** | The controller verifies, queues one job, returns. Nothing is processed inline. |
| **No cookies** in the response | Route registered with no middleware group, session and cookie middleware explicitly excluded, and `EnsureCookielessResponse` strips `Set-Cookie` as the actual guarantee. |
| **401** for a bad signature | `isValid()` fails closed and the controller returns a bare 401. |
| Handle an **empty `events` array** | The validation ping carries `"events": []`. `isIntentToReceive()` detects it, returns 200 and queues nothing. |

Excluding the `web` group is necessary but not sufficient for the cookie rule. Sanctum's stateful
middleware starts a session; a global middleware can call `Cookie::queue()`. Removing the header from
the outgoing response is the only thing that actually guarantees it, and there is a test that queues a
cookie and asserts it is gone.

### What the endpoint returns, in every case

| Situation | Status | Queued | Event dispatched |
|---|---|---|---|
| Valid signature, one or more events | 200 | one `ProcessXeroWebhook` | `XeroWebhookReceived` per event, later, from the job |
| Missing or wrong signature | 401 | no | `XeroWebhookSignatureFailed` |
| `XERO_WEBHOOK_KEY` unset | 401 | no | `XeroWebhookSignatureFailed` |
| Valid signature, empty `events` (validation ping) | 200 | no | none |
| Valid signature, body is not JSON | 200 | no | none — logged at `critical` |
| Valid signature, but the queue is down | 500 | no | none — logged at `error` |

Two of those rows are worth explaining.

**A signed body that is not JSON returns 200.** It came from Xero — the HMAC proves it — so anything
other than a 2xx counts against the subscription. The package logs it as `critical` and moves on.

**A failed `dispatch()` returns 500 on purpose.** We did not accept the delivery, so we should not claim
we did. A 500 makes Xero retry; a 200 would drop the event silently and for ever.

### Why the controller never dispatches events inline

Xero requires a 2xx within five seconds, and **disables a subscription after 24 hours of failures**.
A consuming application that registers a synchronous listener — one that calls the Xero API, or sends an
email, or writes to a slow report table — would blow that budget and take the whole integration offline.

So the controller cannot be allowed to run listener code at all. It queues
`Peoplelogy\XeroBridge\Jobs\ProcessXeroWebhook` and returns; the job fans the envelope out into one
`XeroWebhookReceived` per event. However badly a listener behaves, it cannot hold the HTTP response
open. There is a test asserting that no `XeroWebhookReceived` is dispatched during the request.

```
POST /xero/webhook
      │
      ├─ verify HMAC over raw body ──── fail ──► 401 + XeroWebhookSignatureFailed
      │
      ├─ empty events? ──────────────── yes ───► 200 (nothing queued)
      │
      └─ dispatch ProcessXeroWebhook ─────────► 200
                    │
                    │ (queue worker, later)
                    ▼
            XeroWebhookReceived  ×  one per event, in order
```

#### handle()

Fans one stored envelope out into one event per item.

```php
// signature exactly as in the source
public function handle(): void
```

**Request**

You never construct this yourself in application code — the controller does. In a test, running it by
hand is the fastest way to exercise listeners:

```php
use Peoplelogy\XeroBridge\Jobs\ProcessXeroWebhook;

(new ProcessXeroWebhook(json_decode($rawPayload, true)))->handle();
```

**Response**

Returns nothing. Dispatches `XeroWebhookReceived` once per element of `events`, **in payload order** —
Xero replays stored events in sequence and consumers may rely on that.

**Notes / gotchas**

- The constructor takes the decoded payload array: `public function __construct(private readonly array $payload)`.
- The job carries no Eloquent models, so `SerializesModels` has nothing to resolve and the payload
  survives any queue driver.
- Queue placement is configurable, and the controller applies it before dispatch:

  ```dotenv
  XERO_WEBHOOK_QUEUE=xero
  XERO_WEBHOOK_QUEUE_CONNECTION=redis
  ```

  Both are `null` by default, meaning the application's default queue and connection.

> ⚠️ If `QUEUE_CONNECTION=sync`, the fan-out happens **inside the request** and every listener runs
> before the response is sent. That defeats the entire design and will fail intent-to-receive as soon as
> a listener is slow. Use a real queue driver in any environment Xero can reach.

### The 31-day replay window

Xero stores undelivered events for up to **31 days** and replays them, in order, once your endpoint
recovers. It also retries individual deliveries.

> ⚠️ **Listeners must be idempotent.** The same `resourceId` will arrive more than once — after an
> outage, after a retry, and after a deployment that briefly 502s. Processing it twice must produce the
> same result as processing it once.

Practical ways to get there:

- Key your own record on `resourceId` and `updateOrCreate()` rather than `create()`.
- Compare `UpdatedDateUTC` on the fetched record with what you stored, and skip when it has not moved.
- Fetch the record rather than trusting the notification's ordering. The webhook is a hint that
  something changed; the record is the truth, and fetching late simply gets you the newest state.

Note that `entropy` differs between deliveries of the same logical event, so it is useless as a
deduplication key. Use `resourceId` plus `eventDateUtc`, or the record's own `UpdatedDateUTC`.

### WebhookEnvelope

#### fromArray()

Builds an envelope from a decoded payload.

```php
// signature exactly as in the source
public static function fromArray(array $payload): self
```

**Request**

```php
use Peoplelogy\XeroBridge\Webhooks\WebhookEnvelope;

$envelope = WebhookEnvelope::fromArray(json_decode($raw, true));
```

**Response**

A `WebhookEnvelope` with readonly `$events`, `$firstEventSequence`, `$lastEventSequence`, `$entropy`.

**Notes / gotchas**

- The constructor is private; `fromArray()` is the only way in.
- Non-array elements of `events` are skipped rather than throwing.

#### isIntentToReceive()

True when this is Xero's validation ping rather than a real notification.

```php
// signature exactly as in the source
public function isIntentToReceive(): bool
```

**Notes / gotchas**

- It is simply `$this->events === []`. A real delivery always carries at least one event.

#### toArray()

Round-trips the envelope back to the payload shape, with events nested.

```php
// signature exactly as in the source
public function toArray(): array
```

**Response**

```php
[
    'events' => [ /* each event's toArray() */ ],
    'firstEventSequence' => 1,
    'lastEventSequence' => 2,
    'entropy' => 'S0m3r4Nd0mt3xt',
]
```

Useful for logging a delivery verbatim, since the envelope holds nothing sensitive.

### WebhookEvent

Instances come from the envelope; the constructor is private, and the public factory it uses is
`WebhookEvent::fromArray(array $payload): self`. All seven scalar fields plus `$data` are public
readonly properties, listed in the table above.

Category constants: `WebhookEvent::CATEGORY_CONTACT`, `::CATEGORY_INVOICE`, `::CATEGORY_CREDITNOTE`.

#### isUpdate() / isCreate()

Case-insensitive `eventType` tests.

```php
// signatures exactly as in the source
public function isUpdate(): bool
public function isCreate(): bool
```

**Request**

```php
if ($event->isCreate()) {
    // a record that did not exist before
}
```

**Notes / gotchas**

- Both use `strcasecmp()`. This is the whole point — see the warning above about `UPDATE` vs `Update`.

#### isCategory() / isInvoice() / isContact()

Case-insensitive `eventCategory` tests.

```php
// signatures exactly as in the source
public function isCategory(string $category): bool
public function isInvoice(): bool
public function isContact(): bool
```

**Request**

```php
if ($event->isInvoice()) {
    // ...
}

// Anything the package has no helper for:
if ($event->isCategory('CREDITNOTE')) {
    // ...
}
```

#### occurredAt()

Parses `eventDateUtc` into a Carbon instance.

```php
// signature exactly as in the source
public function occurredAt(): ?CarbonImmutable
```

**Response**

A `CarbonImmutable`, or `null` when `eventDateUtc` is missing or unparseable.

**Notes / gotchas**

- The format is `2026-09-28T01:15:39.902` — UTC, but with **no offset and no trailing `Z`**. A string
  with no offset is interpreted in the application's default timezone. If `config('app.timezone')` is
  not `UTC`, this value will be read as a local time and be wrong by your offset. Parse it explicitly
  in that case:

  ```php
  use Carbon\CarbonImmutable;

  $occurredAt = CarbonImmutable::parse($event->eventDateUtc, 'UTC');
  ```

- Do not use this as an ordering key across tenants. Use `firstEventSequence` / `lastEventSequence`
  within a delivery, and the record's own `UpdatedDateUTC` across deliveries.

#### toArray()

The event back in Xero's payload shape.

```php
// signature exactly as in the source
public function toArray(): array
```

`null` values are filtered out, so `data` is absent rather than `null` for the categories that do not
carry it.

---

## Part 2 — Events

Six event classes, all under `Peoplelogy\XeroBridge\Events`, all using
`Illuminate\Foundation\Events\Dispatchable`. None of them broadcast, none of them queue themselves.

| Event | Fires when | Hang alerting on it? |
|---|---|---|
| `XeroConnected` | An organisation finished the consent flow and was stored | No — informational |
| `TokenRefreshed` | Rotated tokens were durably saved | No — observability only |
| `ConnectionExpired` | A connection is terminally dead and needs a human | **Yes. This is the one.** |
| `InvoiceCreated` | An invoice was accepted by Xero | No — but do persist the ID |
| `XeroWebhookReceived` | One webhook event, from the queued job | No — this is your workload |
| `XeroWebhookSignatureFailed` | A webhook was rejected with a 401 | Only on a sustained stream |

### Registering listeners

Laravel 11 and up discover listeners in `app/Listeners` automatically from the type-hint:

```php
<?php

namespace App\Listeners;

use Peoplelogy\XeroBridge\Events\ConnectionExpired;

class AlertOnXeroDisconnect
{
    public function handle(ConnectionExpired $event): void
    {
        // ...
    }
}
```

Or register a closure in a service provider:

```php
use Illuminate\Support\Facades\Event;
use Peoplelogy\XeroBridge\Events\XeroWebhookReceived;

public function boot(): void
{
    Event::listen(function (XeroWebhookReceived $event) {
        // ...
    });
}
```

**Should a `XeroWebhookReceived` listener implement `ShouldQueue`?** It already runs off-request, inside
`ProcessXeroWebhook`, so it does not have to. Implement `ShouldQueue` when you want **per-event retry
isolation** — otherwise one failing event fails the whole envelope job and every event in it is
retried together, which pushes the idempotency requirement harder than it needs to be.

---

### XeroConnected

An organisation finished the consent flow and its tokens were stored.

```php
// constructor exactly as in the source
public function __construct(
    public readonly XeroConnection $connection,
    /** True when an existing key was repointed at a different organisation. */
    public readonly bool $wasRepointed = false,
) {}
```

**Parameters**

| Property | Type | Meaning |
|---|---|---|
| `$connection` | `XeroConnection` | The stored row, including `key`, `tenant_id`, `tenant_name`, `scopes`. |
| `$wasRepointed` | `bool` | `true` when this key already existed and pointed at a **different** Xero organisation. |

**When it fires**

At the end of `XeroCallbackController`, after the token exchange succeeded, a tenant was selected and
the row was written. It never fires on a failed or cancelled consent — those redirect with a flash
message instead.

**Listener**

```php
<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use Peoplelogy\XeroBridge\Events\XeroConnected;

class RecordXeroConnection
{
    public function handle(XeroConnected $event): void
    {
        Log::info('Xero organisation connected.', [
            'key' => $event->connection->key,
            'tenant' => $event->connection->displayName(),
            'repointed' => $event->wasRepointed,
        ]);

        if ($event->wasRepointed) {
            // The books behind this key changed. Anything cached per
            // connection -- account codes, tax rates, branding themes --
            // is now about a different organisation.
            cache()->forget("xero:accounts:{$event->connection->key}");
        }
    }
}
```

**Notes / gotchas**

- `tenant_name` is nullable in Xero's API. Use `$event->connection->displayName()`, which falls back to
  the tenant ID, rather than interpolating `tenant_name` raw.
- A reconnect of an **invalidated** connection also fires this, and clears `invalidated_at`. Treat it as
  "this connection is now usable", not "this connection is new".
- `$wasRepointed` is only ever `true` under `XERO_ON_KEY_CONFLICT=replace` (the default). Under
  `error`, the conflicting case throws instead.

---

### TokenRefreshed

A connection's access token was rotated and the new pair was saved.

```php
// constructor exactly as in the source
public function __construct(
    public readonly XeroConnection $connection,
    public readonly ?CarbonImmutable $expiresAt,
) {}
```

**Parameters**

| Property | Type | Meaning |
|---|---|---|
| `$connection` | `XeroConnection` | The row as saved, with the new tokens already persisted. |
| `$expiresAt` | `?CarbonImmutable` | When the new access token expires — typically 30 minutes out. |

**When it fires**

Only **after** the rotated tokens have been durably written. Specifically, it does *not* fire:

- on the "another process already refreshed this, reuse theirs" short-circuit inside the lock;
- when Xero returned tokens but the database write failed (that path logs `critical` and rethrows).

**Listener**

```php
<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use Peoplelogy\XeroBridge\Events\TokenRefreshed;

class TraceXeroRefresh
{
    public function handle(TokenRefreshed $event): void
    {
        Log::debug('Xero token rotated.', [
            'key' => $event->connection->key,
            'expires_at' => $event->expiresAt?->toIso8601String(),
        ]);
    }
}
```

**Notes / gotchas**

- Most applications need no listener here. It exists for observability: a connection that stops
  emitting it every 30 minutes under load is a connection about to fail.
- **Never log `$event->connection` itself.** The `encrypted` cast protects the tokens at rest, not once
  the model is hydrated — `Log::info($model)`, an activity log or a JSON response all emit the decrypted
  values. Log named scalars, as above.

---

### ConnectionExpired

A connection is terminally dead and a human must re-authorise it.

```php
// constructor exactly as in the source
public function __construct(
    public readonly XeroConnection $connection,
    public readonly string $reason,
    /** Where a human should go to fix it. */
    public readonly string $connectUrl,
) {}
```

**Parameters**

| Property | Type | Meaning |
|---|---|---|
| `$connection` | `XeroConnection` | The row, now carrying `invalidated_at` and `invalidated_reason`. |
| `$reason` | `string` | `'invalid_grant'` — currently the only value, because it is the only terminal case. |
| `$connectUrl` | `string` | The package's connect URL for this key. Put it in the alert; whoever reads it needs it. |

**When it fires**

From `TokenManager::performRefresh()`, on a genuine `invalid_grant` from Xero's identity service, **and
only on the transition from valid to invalidated**.

It does **not** fire on:

- a 5xx or timeout from identity.xero.com — those are transient, counters only, tokens untouched;
- a failure to save rotated tokens — Xero keeps the previous refresh token usable for roughly 30 minutes
  precisely for this;
- a subsequent refresh attempt on an already-invalidated connection. A nightly cron over a dead
  connection fires this **once**, not once per night. The test is
  `fires ConnectionExpired only once across repeated failures`.

**This is the event to hang alerting on.** It is the only one that means "the integration is down and
will stay down until a person opens a browser". The host project's Xero connection dropped in June and
nobody found out for months; this event exists so that cannot happen again.

**Listener**

```php
<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use Peoplelogy\XeroBridge\Events\ConnectionExpired;

class AlertOnXeroDisconnect implements ShouldQueue
{
    public function handle(ConnectionExpired $event): void
    {
        Notification::route('mail', config('billing.finance_inbox'))
            ->route('slack', config('billing.ops_webhook'))
            ->notify(new \App\Notifications\XeroNeedsReconnecting(
                organisation: $event->connection->displayName(),
                reason: $event->reason,
                connectUrl: $event->connectUrl,
            ));
    }
}
```

**Notes / gotchas**

- Nothing in the package ever deletes a connection row. The most destructive thing that happens here is
  `invalidated_at` being set, and reconnecting clears it — so no data is lost while you wait for someone
  to act on the alert.
- `XeroBridge` calls against an invalidated connection throw `XeroReauthorizationRequiredException`
  **without touching the network**, so a five-minute cron over a dead connection does not hammer Xero.
  The exception message carries the same connect URL.
- `php artisan xero-bridge:refresh-tokens` exits with a distinct status code for this case, so a
  scheduler or CI job can alert on the exit code as well as the event.

---

### InvoiceCreated

An invoice was created in Xero and accepted.

```php
// constructor exactly as in the source
public function __construct(
    public readonly string $connectionKey,
    public readonly array $invoice,
    public readonly ?string $idempotencyKey = null,
) {}
```

**Parameters**

| Property | Type | Meaning |
|---|---|---|
| `$connectionKey` | `string` | The connection the invoice was written to, e.g. `'default'`. |
| `$invoice` | `array<string, mixed>` | The invoice **as Xero returned it** — PascalCase, .NET dates. |
| `$idempotencyKey` | `?string` | The `Idempotency-Key` header sent with the request. |

Three accessors read the payload safely:

```php
// signatures exactly as in the source
public function invoiceId(): ?string       // $invoice['InvoiceID']
public function invoiceNumber(): ?string   // $invoice['InvoiceNumber']
public function status(): ?string          // $invoice['Status']
```

Each returns `null` rather than throwing when the key is absent or not a string.

**When it fires**

- `Invoices::create()` — once, after Xero returns the created invoice.
- `Invoices::createMany()` — once **per accepted element**. Bulk creates are sent with
  `summarizeErrors=false`, so Xero returns HTTP 200 even when some items failed; the event fires only
  for the elements whose `StatusAttributeString` was `OK` or `WARNING`, never for a failure.

**The `$invoice` payload**, trimmed to what a listener usually reads:

```json
{
  "Type": "ACCREC",
  "InvoiceID": "ed255415-e141-4150-aab7-89c3bbbb851c",
  "InvoiceNumber": "INV-0007",
  "Reference": "Website Design",
  "Status": "AUTHORISED",
  "LineAmountTypes": "Exclusive",
  "CurrencyCode": "MYR",
  "CurrencyRate": 1.000000,
  "SubTotal": 40.00,
  "TotalTax": 0.00,
  "Total": 40.00,
  "AmountDue": 40.00,
  "AmountPaid": 0.00,
  "SentToContact": false,
  "HasErrors": false,
  "Contact": {
    "ContactID": "430fa14a-f945-44d3-9f97-5df5e28441b8",
    "Name": "Liam Gallagher",
    "ContactStatus": "ACTIVE",
    "UpdatedDateUTC": "/Date(1551747281053+0000)/"
  },
  "Date": "/Date(1518685950940+0000)/",
  "DateString": "2018-02-15T00:00:00",
  "DueDate": "/Date(1518685950940+0000)/",
  "DueDateString": "2018-02-15T00:00:00",
  "UpdatedDateUTC": "/Date(1552327126164+0000)/"
}
```

**Listener**

```php
<?php

namespace App\Listeners;

use App\Models\Order;
use Peoplelogy\XeroBridge\Events\InvoiceCreated;

class StoreXeroInvoiceId
{
    public function handle(InvoiceCreated $event): void
    {
        $reference = $event->invoice['Reference'] ?? null;

        if (! is_string($reference)) {
            return;
        }

        Order::where('reference', $reference)->update([
            'xero_invoice_id' => $event->invoiceId(),
            'xero_invoice_number' => $event->invoiceNumber(),
            'xero_connection' => $event->connectionKey,
        ]);
    }
}
```

**Notes / gotchas**

- **Persisting `invoiceId()` is the real defence against duplicates, not the idempotency key.** Xero
  retains an idempotency key for only **six minutes** — no realistic queued-job backoff stays inside
  that. The key protects an immediate transient-network retry; your stored `InvoiceID` protects
  everything else. Check for it before creating.
- Dates in `$invoice` are .NET format. Use `Peoplelogy\XeroBridge\Support\XeroDate::from($invoice, 'Date')`,
  which prefers the ISO `DateString` sibling when Xero supplied one. The package deliberately does not
  rewrite response bodies.
- `$invoice` can be an empty array in the pathological case where Xero returns 200 with no invoice in
  the body. The accessors all return `null` there — do not index the array blind.

---

### XeroWebhookReceived

One Xero webhook event, dispatched from the queued job — never from the controller.

```php
// constructor exactly as in the source
public function __construct(
    public readonly WebhookEvent $event,
    public readonly WebhookEnvelope $envelope,
) {}
```

**Parameters**

| Property | Type | Meaning |
|---|---|---|
| `$event` | `WebhookEvent` | The single notification. All fields documented in Part 1. |
| `$envelope` | `WebhookEnvelope` | The full delivery it arrived in, for the sequence numbers. |

Two shortcuts:

```php
// signatures exactly as in the source
public function tenantId(): string     // $this->event->tenantId
public function resourceId(): string   // $this->event->resourceId
```

**When it fires**

Inside `ProcessXeroWebhook::handle()`, once per element of `events`, in payload order. Never during the
HTTP request.

**Listener**

The notification does not carry the changed record, so the listener's job is: work out which connection
this tenant is, then fetch.

```php
<?php

namespace App\Listeners;

use App\Jobs\SyncXeroInvoice;
use Peoplelogy\XeroBridge\Contracts\ConnectionRepository;
use Peoplelogy\XeroBridge\Events\XeroWebhookReceived;

class QueueXeroResourceSync
{
    public function __construct(private readonly ConnectionRepository $connections) {}

    public function handle(XeroWebhookReceived $event): void
    {
        if (! $event->event->isInvoice()) {
            return;
        }

        // The webhook says WHICH organisation, not which of your connection
        // keys -- map it, and ignore anything you have never connected.
        $connection = $this->connections->findByTenantId($event->tenantId());

        if ($connection === null || ! $connection->isUsable()) {
            return;
        }

        SyncXeroInvoice::dispatch((string) $connection->key, $event->resourceId());
    }
}
```

And the job that actually fetches — idempotent, and tolerant of Xero's rate limits:

```php
<?php

namespace App\Jobs;

use App\Models\XeroInvoiceMirror;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Peoplelogy\XeroBridge\Exceptions\XeroRateLimitException;
use Peoplelogy\XeroBridge\Facades\XeroBridge;
use Peoplelogy\XeroBridge\Support\XeroDate;

class SyncXeroInvoice implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly string $connectionKey,
        private readonly string $invoiceId,
    ) {}

    public function handle(): void
    {
        try {
            $invoice = XeroBridge::connection($this->connectionKey)
                ->invoices()
                ->find($this->invoiceId);
        } catch (XeroRateLimitException $e) {
            $this->release($e->retryAfter() ?? 60);

            return;
        }

        if ($invoice === null) {
            return;
        }

        // updateOrCreate, not create: Xero replays events for up to 31 days.
        XeroInvoiceMirror::updateOrCreate(
            ['xero_invoice_id' => $invoice['InvoiceID']],
            [
                'connection_key' => $this->connectionKey,
                'invoice_number' => $invoice['InvoiceNumber'] ?? null,
                'status' => $invoice['Status'] ?? null,
                'total' => $invoice['Total'] ?? 0,
                'amount_due' => $invoice['AmountDue'] ?? 0,
                'updated_in_xero_at' => XeroDate::from($invoice, 'UpdatedDateUTC'),
            ],
        );
    }
}
```

**Response** from that `find()` call — Xero's shape, PascalCase with .NET dates, trimmed:

```json
{
  "Type": "ACCREC",
  "InvoiceID": "ed255415-e141-4150-aab7-89c3bbbb851c",
  "InvoiceNumber": "INV-0007",
  "Reference": "Website Design",
  "Status": "AUTHORISED",
  "AmountDue": 40.00,
  "AmountPaid": 0.00,
  "Total": 40.00,
  "Contact": {
    "ContactID": "430fa14a-f945-44d3-9f97-5df5e28441b8",
    "Name": "Liam Gallagher",
    "EmailAddress": "liam@rockstar.com",
    "UpdatedDateUTC": "/Date(1551747281053+0000)/"
  },
  "LineItems": [
    {
      "Description": "Onsite project management",
      "Quantity": 1.0000,
      "UnitAmount": 40.00,
      "AccountCode": "200",
      "LineAmount": 40.00,
      "LineItemID": "13a8353c-d2af-4d5b-920c-438449f08900"
    }
  ],
  "DateString": "2018-02-15T00:00:00",
  "DueDateString": "2018-02-28T00:00:00",
  "UpdatedDateUTC": "/Date(1552327126164+0000)/"
}
```

**Notes / gotchas**

- **The webhook carries no data.** Apart from `data` on CreditNote, Prepayment and Overpayment events,
  you must fetch. That fetch spends your Xero rate-limit budget, so a bulk change in Xero can produce a
  burst of hundreds of notifications. Queue the fetch (as above) rather than doing it in the listener,
  and handle `XeroRateLimitException` by releasing the job.
- **A tenant you do not recognise is normal**, not an error. Return early, as the example does, rather
  than throwing — a throw fails the envelope job and drags every sibling event through a retry with it.
- A `DELETE` never arrives. Xero has no delete event for invoices; a voided invoice arrives as an
  `UPDATE` with `"Status": "VOIDED"`.
- `find()` returns `null` when the record is gone. Treat it as a no-op, not a failure.

---

### XeroWebhookSignatureFailed

A webhook arrived with a bad or missing signature and was rejected with a 401.

```php
// constructor exactly as in the source
public function __construct(
    public readonly ?string $signature,
    public readonly int $bodyLength,
    public readonly ?string $ip,
) {}
```

**Parameters**

| Property | Type | Meaning |
|---|---|---|
| `$signature` | `?string` | The `x-xero-signature` header as received, or `null` when absent. |
| `$bodyLength` | `int` | `strlen()` of the raw body. The body itself is deliberately **not** carried. |
| `$ip` | `?string` | `$request->ip()`. |

**When it fires**

From the controller, synchronously, immediately before returning the 401 — including when
`XERO_WEBHOOK_KEY` is unset, because that path fails closed and is indistinguishable from a bad
signature by design.

**Listener**

```php
<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Peoplelogy\XeroBridge\Events\XeroWebhookSignatureFailed;

class WatchXeroSignatureFailures
{
    public function handle(XeroWebhookSignatureFailed $event): void
    {
        // A handful during intent-to-receive is expected. A steady stream
        // outside that window means a rotated key or someone probing.
        // add() first: on the database cache store, increment() returns false
        // for a key that does not exist yet and the counter never moves.
        Cache::add('xero:webhook:bad-signature', 0, now()->addHour());
        $count = Cache::increment('xero:webhook:bad-signature');

        if ($count === 20) {
            Log::warning('Sustained Xero webhook signature failures.', [
                'ip' => $event->ip,
                'body_length' => $event->bodyLength,
                'signature_present' => $event->signature !== null,
            ]);
        }
    }
}
```

**Notes / gotchas**

- **Expect these during intent-to-receive.** Xero deliberately sends incorrectly-signed payloads to
  confirm you refuse them. Do not page anyone on a single occurrence.
- A sustained stream after setup almost always means one of: the signing key was rotated in the Xero UI
  and not deployed, the wrong environment's key is in `.env`, or something upstream (a proxy, a WAF, a
  body-rewriting middleware) is altering the raw body in flight.
- Do not log `$event->signature` alongside the body anywhere an attacker could read it back.

---

## Testing webhooks

The package's own suite shows the shape to copy. Two things matter.

**Post raw bytes.** `postJson()` re-encodes the body, which is precisely what the signature check must
reject — using it would quietly defeat the test:

```php
use Illuminate\Support\Facades\Queue;
use Peoplelogy\XeroBridge\Jobs\ProcessXeroWebhook;
use Peoplelogy\XeroBridge\Webhooks\WebhookSignature;

it('accepts a signed Xero webhook', function () {
    Queue::fake();

    config()->set('xero-bridge.webhook_key', 'test-webhook-key');

    $raw = json_encode([
        'events' => [[
            'resourceUrl' => 'https://api.xero.com/api.xro/2.0/Invoices/ed255415-e141-4150-aab7-89c3bbbb851c',
            'resourceId' => 'ed255415-e141-4150-aab7-89c3bbbb851c',
            'eventDateUtc' => '2026-09-28T01:15:39.902',
            'eventType' => 'UPDATE',
            'eventCategory' => 'INVOICE',
            'tenantId' => 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d',
            'tenantType' => 'ORGANISATION',
        ]],
        'firstEventSequence' => 1,
        'lastEventSequence' => 1,
        'entropy' => 'S0m3r4Nd0mt3xt',
    ]);

    $response = $this->call(
        'POST',
        '/xero/webhook',
        [], [], [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XERO_SIGNATURE' => WebhookSignature::compute($raw, 'test-webhook-key'),
        ],
        $raw,
    );

    $response->assertOk()->assertHeaderMissing('Set-Cookie');

    Queue::assertPushed(ProcessXeroWebhook::class, 1);
});
```

**Test listeners by running the job**, not by posting. That separates "did the endpoint accept it" from
"did my listener do the right thing":

```php
use Illuminate\Support\Facades\Event;
use Peoplelogy\XeroBridge\Events\XeroWebhookReceived;
use Peoplelogy\XeroBridge\Jobs\ProcessXeroWebhook;

it('dispatches one event per item, in order', function () {
    Event::fake([XeroWebhookReceived::class]);

    (new ProcessXeroWebhook(json_decode($raw, true)))->handle();

    Event::assertDispatchedTimes(XeroWebhookReceived::class, 1);
});
```

To exercise the real delivery path locally, expose your machine over https on port 443 with a tunnel and
set `APP_URL` to the tunnel's hostname before running `php artisan xero-bridge:install`, so the URL it
prints is the one to paste into Xero.
