# Webhooks and events

Two separate things share this page because they meet in one place: Xero's webhook delivery ends in a
Laravel event, and everything else the package wants to tell your application is an event too.

- **Webhooks** — Xero pushes a notification to your application when something changes in a connected
  organisation. The package verifies it, queues it, and hands you one Laravel event per item.
- **Events** — seven plain PHP event classes the package dispatches. None of them implement
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

   [The route](#the-route) below says what moves it.

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
| Method and path | `POST /xero/webhook` by default — see below |
| Route name | `xero-bridge.webhook` |
| Controller | `Peoplelogy\XeroBridge\Http\Controllers\XeroWebhookController` (invokable) |
| Middleware | `EnsureCookielessResponse` only |
| Explicitly excluded | `StartSession`, `EncryptCookies`, `AddQueuedCookiesToResponse` |
| CSRF | none — the HMAC signature is the authentication |
| Registered by | `routes/webhook.php`, loaded when `xero-bridge.webhooks.enabled` is true |

The path is a prefix followed by `webhooks.path`. The prefix is `webhooks.prefix` when that is set,
and the connect routes' `routes.prefix` when it is not:

```php
// config/xero-bridge.php
'routes' => ['prefix' => env('XERO_ROUTES_PREFIX', 'xero')],
'webhooks' => [
    'path' => env('XERO_WEBHOOK_PATH', 'webhook'),
    'prefix' => env('XERO_WEBHOOK_PREFIX'),   // unset or empty => follow routes.prefix
],
```

| Settings | Webhook route |
|---|---|
| neither prefix set | `POST /xero/webhook` |
| `XERO_ROUTES_PREFIX=admin/xero` | `POST /admin/xero/webhook` — it moves with the connect routes |
| `XERO_ROUTES_PREFIX=admin/xero` and `XERO_WEBHOOK_PREFIX=api/v1/xero` | `POST /api/v1/xero/webhook`, while connect, callback and the console stay under `/admin/xero` |
| `XERO_WEBHOOK_PREFIX=/` | `POST /webhook`, at the site root |

- An empty `XERO_WEBHOOK_PREFIX=` counts as unset, so a line copied out of an example file cannot move
  a live endpoint. `/` is the way to say "the site root".
- Slashes at either end are trimmed rather than read as absolute: `XERO_WEBHOOK_PATH=/webhook` still
  means `/xero/webhook`.
- The route keeps its name and its cookieless middleware under any prefix. An `api/...` prefix does
  not put it in the `api` middleware group.
- `xero-bridge:install` prints the URL the route is actually registered at.
- Moving it means entering the new URL in the Xero app's **Webhooks** tab — Xero then runs
  [intent to receive](#intent-to-receive) again — and rebuilding the route cache. Until both are done,
  deliveries fail.

The webhook route is registered from its own file, behind its own flag, separately from the
connect/callback routes. That is not tidiness. The connect routes **need** a session to carry the OAuth
state; the webhook route must have **no** session and **no** cookies, because any cookie in the response
fails Xero's validation. They cannot share a middleware stack.

> ⚠️ `php artisan route:cache` bakes in whatever `webhooks.enabled`, `routes.enabled` and the webhook
> URL were at cache time. Changing the env var afterwards does nothing until you re-cache.

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
| 2xx within **5 seconds** | The controller verifies, takes a uniqueness lock, queues one job, returns. Nothing is processed inline — unless the queue driver is `sync`; see [below](#why-the-controller-never-dispatches-events-inline). |
| **No cookies** in the response | Route registered with no middleware group, session and cookie middleware explicitly excluded, and `EnsureCookielessResponse` strips `Set-Cookie` as the actual guarantee. |
| **401** for a bad signature | `isValid()` fails closed and the controller returns a bare 401 — even when a `XeroWebhookSignatureFailed` listener throws, which is caught and logged instead. |
| Handle an **empty `events` array** | The validation ping carries `"events": []`. `isIntentToReceive()` detects it, returns 200 and queues nothing — before any lock or queue work, so a queue or lock store that is down cannot fail it. |

Excluding the `web` group is necessary but not sufficient for the cookie rule. Sanctum's stateful
middleware starts a session; a global middleware can call `Cookie::queue()`. Removing the header from
the outgoing response is the only thing that actually guarantees it, and there is a test that queues a
cookie and asserts it is gone.

### What the endpoint returns, in every case

| Situation | Status | Queued | Event dispatched |
|---|---|---|---|
| Valid signature, one or more events | 200 | one `ProcessXeroWebhook` | `XeroWebhookReceived` per event, later, from the job |
| Valid signature, the same events already queued or running, first delivery confirmed queued — Xero retrying a slow delivery | 200 | no | none — logged at `info` |
| Valid signature, the same events' first delivery still being queued (on the `sync` driver: its listeners still running) | 503 | no | none — logged at `info`; Xero retries later |
| Valid signature, the uniqueness lock cannot be taken (its cache store is down) | 200 | one `ProcessXeroWebhook`, without the lock | as for any delivery — logged at `warning` |
| Missing or wrong signature | 401 | no | `XeroWebhookSignatureFailed` |
| `XERO_WEBHOOK_KEY` unset | 401 | no | `XeroWebhookSignatureFailed` |
| Either of those, and a `XeroWebhookSignatureFailed` listener throws | 401 | no | `XeroWebhookSignatureFailed` — the listener's exception is logged at `error` |
| Valid signature, empty `events` (validation ping) | 200 | no | none |
| Valid signature, body is not JSON | 200 | no | none — logged at `critical` |
| Valid signature, but the job cannot be queued | 500 | no | none — logged at `error` |
| Valid signature, the job runs on the `sync` driver, and a listener throws | 500 | ran inside the request | up to the listener that threw — logged at `error` |

Most of those rows are worth explaining.

**A signed body that is not JSON returns 200.** It came from Xero — the HMAC proves it — so anything
other than a 2xx counts against the subscription. The package logs it as `critical` and moves on.

**A bad signature always gets its 401.** Intent-to-receive sends incorrectly signed payloads on purpose
and passes only if each one gets a 401, so a 500 fails it as surely as a 200 would.
`XeroWebhookSignatureFailed` is dispatched inside the request, before the 401 is sent. A listener that
throws — including a queued listener that cannot be pushed because the queue is down — is caught and
logged as `xero-bridge: a XeroWebhookSignatureFailed listener failed; answered 401 regardless.`, and the
401 goes out. That exception reaches the log only, not your exception handler or error tracker.

**A retry of a delivery that is already queued gets a 200 and is not queued again.** A retry that
arrives before the first delivery is confirmed queued gets a 503 instead, so Xero retries it later. See
[When Xero retries a delivery](#when-xero-retries-a-delivery).

**A failed `dispatch()` returns 500 on purpose.** We did not accept the delivery, so we should not claim
we did. A 500 makes Xero retry; a 200 would drop the event silently and for ever. The log line is
`xero-bridge: could not queue a Xero webhook, or (on the sync driver) a listener failed while processing
it.`, with `exception` and `exception_class` in its context. The uniqueness lock is given back before
the 500, so the retry is queued normally; if giving it back fails, that is logged at `critical`,
because until the lock expires Xero's retries of those events are answered 503 without being queued.

What a 500 sets off at Xero's end:

- Xero retries straight away, then every 15 minutes.
- After 24 hours without a 2xx it **disables the subscription** and emails every collaborator on the
  Xero app — so make whoever needs to hear about it a collaborator.
- Events raised while the subscription is retrying or disabled are kept for up to **31 days** and
  replayed, in order, once it is healthy again. A disabled subscription is healthy again only once
  someone re-enables it, which puts it through intent-to-receive again.
- The subscription's status — **Retry** or **Disabled** — is shown on the app's **Webhooks** tab at
  <https://developer.xero.com/myapps>, which is also where a disabled one is re-enabled.

The package keeps no copy of an envelope it could not queue. Xero's retries and its replay are the
durable copy.

**On the `sync` driver, a listener that throws is a 500.** The job runs inside the request there, so
a listener's exception arrives where a failed push would, and Xero retries the whole envelope. See the
[sync-driver warning](#why-the-controller-never-dispatches-events-inline) below.

### Why the controller never dispatches events inline

Xero requires a 2xx within five seconds, and **disables a subscription after 24 hours of failures**.
A consuming application that registers a synchronous listener — one that calls the Xero API, or sends an
email, or writes to a slow report table — would blow that budget and take the whole integration offline.

So the controller cannot be allowed to run listener code at all. It queues
`Peoplelogy\XeroBridge\Jobs\ProcessXeroWebhook` and returns; the job fans the envelope out into one
`XeroWebhookReceived` per event. However badly a listener behaves, it cannot hold the HTTP response
open — on any queue driver but `sync` (see the warning below). There is a test asserting that no
`XeroWebhookReceived` is dispatched during the request.

```
POST /xero/webhook
      │
      ├─ verify HMAC over raw body ──── fail ──► 401 + XeroWebhookSignatureFailed
      │
      ├─ not JSON? ──────────────────── yes ───► 200 (logged, nothing queued)
      │
      ├─ empty events? ──────────────── yes ───► 200 (nothing queued)
      │
      ├─ take the uniqueness lock ───── held ──► 200 if the first delivery is confirmed queued,
      │                                          otherwise 503 (Xero retries later)
      │
      ├─ queue ProcessXeroWebhook ───── fails ─► 500 (lock given back; Xero retries)
      │
      └────────────────────────────────────────► 200
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
Xero replays stored events in sequence and consumers may rely on that. An event is left out only when
the [replay table](09-persistence.md#webhook-replay-dedupe) shows it was dispatched already, or when
[`XERO_WEBHOOK_UNKNOWN_TENANTS=ignore`](#organisations-you-have-not-connected) skips it.

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

> ⚠️ **On the `sync` driver the fan-out happens inside the request.** That is the case whenever the job's
> queue connection is `sync`: `XERO_WEBHOOK_QUEUE_CONNECTION=sync`, or `QUEUE_CONNECTION=sync` while
> `XERO_WEBHOOK_QUEUE_CONNECTION` is unset. Intent-to-receive is not at risk — its ping never queues the
> job — but every real delivery runs all of its listeners before the response is sent:
>
> - Listener time counts against Xero's five seconds. A delivery that overruns is retried; a retry that
>   arrives while the first request is still running is answered 503, and one that arrives after it
>   has finished runs every listener again. So Xero's next retry after that 503 runs the listeners a
>   second time: a later duplicate, never a drop.
> - A listener that throws turns the response into a 500, so Xero retries the whole envelope, and the
>   listeners that had already succeeded run again — except for events the
>   [replay table](09-persistence.md#webhook-replay-dedupe) recorded as dispatched before the failure.
>
> Use a real queue driver in any environment Xero can reach.

### When Xero retries a delivery

The controller queues the job **before** it answers. So a 200 that misses Xero's five seconds is late,
not lost — the job is already queued when Xero's retry arrives — and queuing the retry as well would
fire every listener twice. To stop that, the controller takes a uniqueness lock on the delivery's events
just before queuing, and a retry that finds the lock held is answered 200 without being queued again —
once the first delivery is confirmed queued.

Confirmed matters because Xero gives up on a request after five seconds and retries at once, so a retry
can arrive while the first delivery's push is still in flight — on the `sync` driver, while its
listeners still run. Answered 200 then, a push that went on to fail would lose the events: Xero never
resends a delivery it was answered 200 for, and the first request's 500 goes to a connection Xero has
already abandoned. So only after a successful push does the controller write a marker,
`xero-bridge:webhook-queued:<the job's uniqueId>`, beside the lock in the same store, for
`webhooks.unique_for` seconds. A retry that finds the lock held gets 200 when the marker is there, and
503 (logged at `info`) when it is not — a marker that cannot be read counts as not there. Xero retries
a 503 on its own schedule, by when the first delivery's outcome is known: queued, and that retry gets
200 while the lock is still held, or is queued again once the job has given the lock back — a duplicate
the [replay table](09-persistence.md#webhook-replay-dedupe) absorbs; failed, and the lock is free, so
the retry queues the events itself. The worst case is a later duplicate, never a loss.

(`ProcessXeroWebhook` has declared itself unique since 1.4.0, but Laravel takes that lock itself only
for a job sent through the `dispatch()` helper or the job's own static `dispatch()`. The controller
pushes the job through the bus dispatcher, so before 1.5.0 the lock was never taken.)

The lock is keyed on the events themselves, in order — each one's `tenantId`, `resourceId`, `eventType`
and `eventDateUtc` — never on `entropy`, which Xero changes on every delivery. A retry carrying the same
events finds it; an envelope with an event added, removed or reordered is a different delivery.

What a delivery of the same events meets:

| When it arrives | What happens |
|---|---|
| The first delivery's push is still in flight — on the `sync` driver, its listeners still running | 503, not queued — logged at `info`. Xero retries later; see above |
| The first job is still queued, running, or waiting between attempts | 200, not queued — logged at `info` |
| The first job has finished, or its last attempt has failed | Queued as usual: the job gave the lock back. The lock stops a retry storm, not a replay days later — that is for your idempotent listeners and the optional [replay table](09-persistence.md#webhook-replay-dedupe) |
| The first job's worker died and the job never completes | 200, not queued, until the lock expires: `webhooks.unique_for` seconds after it was taken (`XERO_WEBHOOK_UNIQUE_FOR`, 900 by default, at least 60) |
| The first delivery's push failed | Queued as usual: the marker was forgotten and the lock given back before the 500 |
| The marker could not be written after the first delivery was queued | 503, not queued, while the lock is held; queued again once it is free — a possible duplicate, never a loss. The first delivery still got its 200, and the failure is logged at `warning` |
| The lock's cache store cannot be reached | Queued without a lock, logged at `warning`, answered 200. A retry may then be queued twice — a duplicate, never a loss |

`XERO_WEBHOOK_UNIQUE_FOR` is a ceiling, not a store. The lock lives in the cache store `XERO_LOCK_STORE`
names — the store token refreshes lock in — or in the default cache store while that is unset or blank.
A name that cannot be resolved, a store that cannot lock and the `null` driver all fall back to the
default store rather than fail a webhook; `xero-bridge:status` reports each of them. A lock is only as
good as its store: `array` holds it inside one process and `file` inside one server, so with more than
one web server set `XERO_LOCK_STORE` to redis, memcached or database. The web server takes the lock and
the queue worker gives it back, so both need to reach the store. The store must hold plain cache entries
as well as locks, for the marker: a `database` lock store needs Laravel's `cache` table, not only
`cache_locks`. Without it every delivery logs `xero-bridge: could not record that a Xero webhook was
queued, ...` at `warning`, and every retry that finds the lock held is answered 503 and queued again
later.

On the `sync` driver the job runs, and gives the lock back, inside the first request. A retry that
arrives while that request is still running is answered 503, and Xero's next retry finds the lock free
and runs the listeners again: a later duplicate, never a drop.

### The 31-day replay window

Xero retries a delivery that did not get a 2xx, and while a subscription is retrying or disabled it
keeps that subscription's events for up to **31 days**, replaying them in order once your endpoint
recovers — see [what a 500 sets off](#what-the-endpoint-returns-in-every-case).

> ⚠️ **Listeners must be idempotent.** The same `resourceId` will arrive more than once — after an
> outage, after a retry, and after a deployment that briefly 502s. Processing it twice must produce the
> same result as processing it once. The uniqueness lock above and the optional
> [replay table](09-persistence.md#webhook-replay-dedupe) make that rarer; neither makes it impossible.

Practical ways to get there:

- Key your own record on `resourceId` and `updateOrCreate()` rather than `create()`.
- Compare `UpdatedDateUTC` on the fetched record with what you stored, and skip when it has not moved.
- Fetch the record rather than trusting the notification's ordering. The webhook is a hint that
  something changed; the record is the truth, and fetching late simply gets you the newest state.

Note that `entropy` differs between deliveries of the same logical event, so it is useless as a
deduplication key. Use `resourceId` plus `eventDateUtc`, or the record's own `UpdatedDateUTC`.

### Organisations you have not connected

Xero sends events for every organisation connected to your **Xero app**, not only for the ones this
application has stored. Events from an organisation with no stored connection here are normal when:

- one Xero app serves more than one environment or deployment, each with organisations of its own;
- an organisation was forgotten here — the test console's "Forget connection" — but never
  disconnected at Xero.

By default they reach your listeners like any other event, and each listener decides:
[`$event->connection()`](#xerowebhookreceived) answers "is this one of ours?". To drop them before any
listener runs instead:

```dotenv
XERO_WEBHOOK_UNKNOWN_TENANTS=ignore
```

| `webhooks.unknown_tenants` | Events for an organisation with no stored connection |
|---|---|
| `dispatch` (the default) | Dispatched like any other event, as every release before 1.5.0 did. Costs no query. |
| `ignore` | Skipped before any listener runs: never dispatched, and never recorded or counted in the [replay table](09-persistence.md#webhook-replay-dedupe). |

Only `ignore` switches the filter on, in any case and with stray spaces ignored. Anything else — the
key missing, an empty value, a typo — means `dispatch`, because a filter switched on by a misspelling
would drop real notifications. The key reaches a `config/xero-bridge.php` you published earlier by
itself; you only set the variable.

Under `ignore`:

- Only `ORGANISATION` events are looked up — which is also what an event counts as when Xero omits
  `tenantType`. Every other type passes untouched: an App Store subscription event carries
  `APPLICATION` and the app's own id where a tenant id would be, so it could never match a stored row.
- It asks only whether a row exists. An invalidated connection counts as known — the organisation is
  connected here and needs a reconnect, so its listeners keep hearing about it — and no token is
  decrypted. Keep the `isUsable()` check in your listener.
- Each organisation is looked up once each time the job runs and never remembered longer, so one
  connected since an earlier delivery is seen on its next event.
- A lookup that fails dispatches the event anyway, logs
  `xero-bridge: could not look up the organisation of a Xero webhook event, so it was dispatched
  anyway.` at `warning`, and is tried again for the next event. A database blip must not lose
  notifications.
- The events that remain keep their order. A delivery that skipped anything logs one `info` line —
  `xero-bridge: skipped Xero webhook events for organisations with no stored connection
  (webhooks.unknown_tenants is "ignore").` — with the skipped `tenant_ids` and their `count`; a
  delivery that skipped nothing logs nothing.

The queue worker reads the setting, so after changing it rebuild or clear the config cache and run
`php artisan queue:restart`.

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

Seven event classes, all under `Peoplelogy\XeroBridge\Events`, all using
`Illuminate\Foundation\Events\Dispatchable`. None of them broadcast, none of them queue themselves.

| Event | Fires when | Hang alerting on it? |
|---|---|---|
| `XeroConnected` | An organisation finished the consent flow and was stored | No — informational, and the place to record who connected |
| `TokenRefreshed` | Rotated tokens were durably saved | No — observability only |
| `ConnectionExpired` | A connection is terminally dead and needs a human | **Yes. This is the one.** |
| `InvoiceCreated` | An invoice was accepted by Xero | No — but do persist the ID |
| `XeroWriteBlocked` | The write ledger refused a write that was already made, or is in flight | **Yes, when `$pending` is true and `$claimedAt` is old** — a stuck claim. Otherwise no |
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

> ⚠️ **A queued listener on `XeroConnected`, `TokenRefreshed` or `ConnectionExpired` puts the whole
> connection row on the queue.** No event in this package uses `SerializesModels`, so the
> `XeroConnection` it carries is serialised whole into the job payload — and into `failed_jobs` if the
> listener fails — with the `access_token` and `refresh_token` ciphertext in it. The model's `$hidden`
> does not reach that; it only shapes `toArray()` and JSON. Either implement `ShouldBeEncrypted` beside
> `ShouldQueue`, so Laravel encrypts the payload, or listen synchronously and queue a job of your own
> that carries only the named values it needs, such as the key and the tenant id. The other four
> events carry no model.

---

### XeroConnected

An organisation finished the consent flow and its tokens were stored.

```php
// property and constructor exactly as in the source (the property's docblock left out)
public ?Actor $actor = null;

public function __construct(
    public readonly XeroConnection $connection,
    /** True when an existing key was repointed at a different organisation. */
    public readonly bool $wasRepointed = false,
    ?Actor $actor = null,
) {
    $this->actor = $actor;
}
```

**Parameters**

| Property | Type | Meaning |
|---|---|---|
| `$connection` | `XeroConnection` | The stored row, including `key`, `tenant_id`, `tenant_name`, `scopes`. |
| `$wasRepointed` | `bool` | `true` when this key already existed and pointed at a **different** Xero organisation. |
| `$actor` | `?Actor` | Who completed the consent: a `Peoplelogy\XeroBridge\OAuth\Actor`, or `null` when nobody was signed in. See below. |

**Who connected — `$actor`**

The user signed in on the callback request, as three scalars. The OAuth state was issued into that
same session, so in practice it is the user who started the flow.

| `Actor` property | Type | Meaning |
|---|---|---|
| `id` | `int\|string` | The guard's auth identifier, in the type the guard returned it: an `int` for an incrementing key, a `string` for a uuid or ulid. Some user providers return numeric keys as strings, so compare loosely or cast. |
| `type` | `string` | The user model's morph alias when one is mapped, so it matches the `*_type` columns you already store; otherwise its class name — also under `Relation::enforceMorphMap()` when the user model is not in the map. |
| `guard` | `?string` | The guard the user was signed in through: your default guard — `web` in a stock Laravel app — under the default `web,auth` middleware, and `sanctum` under `web,auth:sanctum`. |

It is `null` when:

- nobody was signed in on the callback request. The `auth` in the default `web,auth` middleware makes
  sure someone is; with it removed from `XERO_ROUTES_MIDDLEWARE`, a consent completed by someone who is
  not signed in arrives with `null`;
- the guard's identifier is neither an `int` nor a `string`;
- resolving the user threw. That is logged as a warning —
  `xero-bridge: could not resolve the signed-in user; XeroConnected carries no actor.` — and never
  affects the connection;
- a queued listener's copy of the event was queued before the upgrade to 1.5.0. It arrives with
  `null`: that is why `$actor` is a property with a default rather than a `readonly` one, which would be
  left uninitialised and throw on its first read. Do not write to it.

So read it with `?->`: `$event->actor?->id`.

Scalars, never the user model, because no event in this package uses `SerializesModels`: a model on the
event would be serialised whole into every queued listener's payload, password hash and
`remember_token` included. `Actor` has a public constructor, so a test can build the event directly:
`new XeroConnected($connection, false, new Actor(42, 'user', 'web'))`.

**When it fires**

At the end of `XeroCallbackController`, after the token exchange succeeded, a tenant was selected and
the row was written. It never fires on a failed or cancelled consent — those redirect with a flash
message instead.

The row is committed by then, so a listener that throws does not fail the connect. Its exception goes
to `report()`, the package logs `xero-bridge: a XeroConnected listener failed; the connection itself was
stored.` with the key and tenant id, and the administrator is still redirected with the success
message. As with any Laravel event, the listeners after the one that threw do not run, and a queued
listener that could not be pushed never runs. A listener cannot change where the administrator lands by
throwing either — that is what [`redirectAfterConnectUsing()`](01-getting-started.md#redirectafterconnectusing)
is for.

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

To keep an audit trail of who connected what, store named scalars in a table of your own — never the
model, and never the user:

```php
<?php

namespace App\Listeners;

use Illuminate\Support\Facades\DB;
use Peoplelogy\XeroBridge\Events\XeroConnected;

class AuditXeroConnection
{
    public function handle(XeroConnected $event): void
    {
        DB::table('xero_connection_audit')->insert([
            'connection_key' => $event->connection->key,
            'tenant_id' => $event->connection->tenant_id,
            'repointed' => $event->wasRepointed,
            'actor_type' => $event->actor?->type,
            'actor_id' => $event->actor?->id,
            'actor_guard' => $event->actor?->guard,
            'connected_at' => now(),
        ]);
    }
}
```

**Notes / gotchas**

- `tenant_name` is nullable in Xero's API. Use `$event->connection->displayName()`, which falls back to
  the tenant ID, rather than interpolating `tenant_name` raw.
- A reconnect of an **invalidated** connection also fires this, and clears `invalidated_at`. Treat it as
  "this connection is now usable", not "this connection is new".
- `$wasRepointed` is only ever `true` under `XERO_ON_KEY_CONFLICT=replace` (the default). Under
  `error`, the conflicting case throws instead. That holds for `XERO_ON_TENANT_CONFLICT=rekey` too:
  moving an organisation onto a key that holds a different one deletes that row under `replace` and
  reports `true`, and under `error` refuses and changes nothing. A rekey onto an unused key renames
  the row and reports `false`; the event does not say which key the row left — see
  [Auditing connections](#auditing-connections).

---

### Auditing connections

`XeroConnected` records every connection that is stored — a first connect, a reconnect, a repoint or a
rekey — with [`$actor`](#xeroconnected) saying who. The rest of a connection's history is in the model's
own Eloquent events.

**Rows are deleted in two places only**, and the token lifecycle is neither: an `invalid_grant` only
sets `invalidated_at`, which a reconnect clears.

| Deleted by | What is deleted |
|---|---|
| The test console's **Forget connection** (`connection.forget`) | The row under that key. Nothing is revoked at Xero, and every call through the key fails until someone reconnects. |
| A connect under `XERO_ON_TENANT_CONFLICT=rekey` with `XERO_ON_KEY_CONFLICT=replace` (the default), onto a key that holds a different organisation | The row that held the key — the organisation being moved takes its place. |

Both are Eloquent instance deletes, so the model's `deleting` and `deleted` events fire: an observer on
the model `xero-bridge.model` names (`XeroConnection` by default) sees them, and can read
`auth()->user()` while they happen. The other changes to what a row points at are Eloquent **updates**:
a repoint under `replace` overwrites the row in place, and a rekey onto an unused key renames it.
During the `updated` event, `$connection->getOriginal('tenant_id')` and `getOriginal('key')` still hold
what the row said before.

A forget is also logged, at `warning`:
`xero-bridge: the test console deleted a stored Xero connection. Calls through it fail until someone
reconnects; the authorisation stays live in Xero until it is removed there.` Its context holds the
`connection` key, the `tenant_id`, Xero's `connection_id` — which is what finishing the disconnect at
Xero needs, and which goes with the row — and `actor_id`, `actor_type` and `actor_guard`, the same
scalars as [`$actor`](#xeroconnected). A `deleting` listener that returns `false` cancels the forget:
nothing is deleted, nothing is logged, and the console reports the row as not deleted.

**A disconnect made inside Xero cannot be seen.** Nothing announces it to the package. The next API
call for that organisation fails with a 403, which arrives as `XeroAuthenticationException`, and that
changes nothing: the row stays, nothing is invalidated, and no event fires.
[`ConnectionExpired`](#connectionexpired) fires only when a token refresh is refused with
`invalid_grant`.

Do not reach for an activity-log trait on the model instead: it would write both tokens in clear into
its log table, which is why the model forbids one. Events and observers that copy named scalars are
the supported way.

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
will stay down until a person opens a browser". A connection that drops silently can go unnoticed for
months; this event exists so that it cannot.

**Listener**

It is queued, so it is encrypted too: the event carries the connection row, token ciphertext included —
see [Registering listeners](#registering-listeners).

```php
<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use Peoplelogy\XeroBridge\Events\ConnectionExpired;

class AlertOnXeroDisconnect implements ShouldBeEncrypted, ShouldQueue
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

- Nothing in the token lifecycle deletes a connection row. The most destructive thing that happens here
  is `invalidated_at` being set, and reconnecting clears it — so no data is lost while you wait for
  someone to act on the alert. (Only the test console's forget and a rekey delete rows — see
  [Auditing connections](#auditing-connections).)
- **It does not fire for a disconnect made inside Xero.** When someone removes the app from the
  organisation in Xero, the next API call for it fails with a 403, which arrives as
  `XeroAuthenticationException` — and that invalidates nothing and fires nothing. Alert on that
  exception from your own jobs as well, and treat it as terminal: only a reconnect fixes it.
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

### XeroWriteBlocked

The [write ledger](09-persistence.md#write-dedupe) refused a write because it has already been made, or
is being made right now.

```php
// constructor exactly as in the source
public function __construct(
    public readonly string $connectionKey,
    /** 'invoice.create', 'contact.create' or 'payment.create'. */
    public readonly string $operation,
    /** The morph class of the record named with for(). */
    public readonly ?string $ownerType,
    public readonly ?string $ownerId,
    /** The reference passed to for() or reference(), if any. */
    public readonly ?string $reference,
    public readonly bool $pending,
    /** The id the earlier write produced; null while it is pending. */
    public readonly ?string $xeroId,
    /** When the existing claim was taken; null if it could not be read. */
    public readonly ?CarbonImmutable $claimedAt,
) {}
```

**Parameters**

| Property | Type | Meaning |
|---|---|---|
| `$connectionKey` | `string` | The connection the write was for. |
| `$operation` | `string` | `'invoice.create'`, `'contact.create'` or `'payment.create'`. |
| `$ownerType` | `?string` | The morph class of the record named with `for()`. Only such writes are deduplicated, so in practice it is always set. |
| `$ownerId` | `?string` | That record's key, as a string. |
| `$reference` | `?string` | The reference passed to `for()` or `reference()`, if any. |
| `$pending` | `bool` | The same as the exception's `isPending()`: the earlier attempt's outcome was never recorded. |
| `$xeroId` | `?string` | The same as the exception's `xeroId()`: the id the earlier write produced, or `null` while it is pending. |
| `$claimedAt` | `?CarbonImmutable` | When the existing claim was taken. `null` when its row could not be read. |

**When it fires**

Synchronously, inside the write call, immediately before `XeroWriteAlreadyClaimedException` is thrown —
for `invoices()`, `contacts()` and `payments()` writes named with `for()`. Never for a write that went
through, and never while the ledger is off. The exception tells the caller to stop; this tells everyone
else, from one listener for all three kinds of write.

**How to read it**

| `$pending` | `$claimedAt` | What it means | Page someone? |
|---|---|---|---|
| `false` | any | **Confirmed.** The write completed earlier, and `$xeroId` is what it produced. The ledger working. | No — count it |
| `true` | more than a few minutes ago | **A stuck claim.** A worker sent something to Xero and never recorded the outcome, and every later attempt for this record is refused until a person checks Xero and resolves the row. | **Yes** |
| `true` | seconds ago | Two workers raced for the same write and the loser was stopped. | No |
| `true` | `null` | Pending, of unknown age — the existing row could not be read. | Treat it as stuck |

`$pending` is also `true` when the earlier write succeeded but recorded no id. This event is the only
signal for a stuck claim at the moment it blocks: the
[catch recipe](09-persistence.md#write-dedupe) returns quietly on a pending block, and must, because
re-sending could create the duplicate. `xero-bridge:prune`, and `xero-bridge:status` while the ledger is
on, report a claim only once it has been pending for over an hour.

**Listener**

```php
<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use Peoplelogy\XeroBridge\Events\XeroWriteBlocked;

class PageOnStuckXeroClaim
{
    public function handle(XeroWriteBlocked $event): void
    {
        if (! $event->pending) {
            return; // confirmed: the duplicate protection working
        }

        if ($event->claimedAt !== null && $event->claimedAt->greaterThan(now()->subMinutes(10))) {
            return; // seconds old: two workers raced, and the loser was stopped
        }

        Log::critical('A Xero write is blocked by a stuck claim. Check Xero, then resolve the row.', [
            'connection' => $event->connectionKey,
            'operation' => $event->operation,
            'owner' => "{$event->ownerType}#{$event->ownerId}",
            'reference' => $event->reference,
            'claimed_at' => $event->claimedAt?->toIso8601String(),
        ]);
    }
}
```

**Notes / gotchas**

- A listener that throws can never replace the exception the caller is waiting for. It is caught and
  logged at `warning` — `xero-bridge: a XeroWriteBlocked listener failed. The write is still refused as
  a duplicate.` — and the caller still gets `XeroWriteAlreadyClaimedException`.
- It runs inside the write call, so anything slow belongs in a `ShouldQueue` listener. The event holds
  only strings, a boolean and a `CarbonImmutable` — no model, no token — so it queues cleanly.
- `XeroWriteAlreadyClaimedException` carries the connection too: `connectionKey()`, and `connection` in
  `context()`.

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

Two shortcuts, and the stored connection:

```php
// signatures exactly as in the source
public function tenantId(): string     // $this->event->tenantId
public function resourceId(): string   // $this->event->resourceId
public function connection(): ?XeroConnection
```

`connection()` is the stored row for the event's organisation, or `null` when none is stored here — the
"is this one of ours?" question every listener asks:

- It is looked up when you call it, through the bound `ConnectionRepository` by tenant id, and every
  call is a query. Call it once per listener.
- It returns an invalidated connection too, so check `isUsable()` before calling Xero with it.
- It is always `null` for an App Store subscription event (`tenantType` `APPLICATION`), which carries
  the app's own id where a tenant id would be.
- A queued listener sees the row as it is when the listener runs, not when the job ran. In between, a
  key can be repointed at another organisation, or an organisation moved to another key; the tenant id
  is what stays put.
- It adds nothing to the event, so an event queued for a listener before the upgrade to 1.5.0 still
  unserialises and works. A database error reaches your listener.

**When it fires**

Inside `ProcessXeroWebhook::handle()`, once per element of `events`, in payload order — except an
event the [replay table](09-persistence.md#webhook-replay-dedupe) shows was already dispatched, and,
with `XERO_WEBHOOK_UNKNOWN_TENANTS=ignore`, an event for an
[organisation you have not connected](#organisations-you-have-not-connected). On a real queue driver,
never during the HTTP request; on `sync`, the job runs inside it.

**Listener**

The notification does not carry the changed record, so the listener's job is: work out which connection
this tenant is, then fetch.

```php
<?php

namespace App\Listeners;

use App\Jobs\SyncXeroInvoice;
use Peoplelogy\XeroBridge\Events\XeroWebhookReceived;

class QueueXeroResourceSync
{
    public function handle(XeroWebhookReceived $event): void
    {
        if (! $event->event->isInvoice()) {
            return;
        }

        // The webhook says WHICH organisation, not which of your connection
        // keys -- map it, and ignore anything you have never connected or
        // that needs reconnecting.
        $connection = $event->connection();

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
  `XERO_WEBHOOK_UNKNOWN_TENANTS=ignore` drops such events before any listener runs — see
  [Organisations you have not connected](#organisations-you-have-not-connected) — but keep the
  `isUsable()` check either way: an invalidated connection still counts as known.
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

So its listeners run **inside the webhook request**, before the 401 is sent — during intent-to-receive
too, inside Xero's five seconds. Keep them fast, or queue them. A listener that throws, including a
queued one that cannot be pushed, is caught and logged at `error` as
`xero-bridge: a XeroWebhookSignatureFailed listener failed; answered 401 regardless.`, and the 401 still
goes out. The exception does not reach your exception handler.

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

If you have moved the webhook URL, post to `route('xero-bridge.webhook')` rather than the literal path.

Under `Queue::fake()` the job never runs, so the [uniqueness lock](#when-xero-retries-a-delivery) it
took is never given back: post the same events twice in one test and **one** job is pushed — the second
post is answered 200 as a retry. Give a second delivery events of its own. The lock lives in a cache
store — the one `XERO_LOCK_STORE` names, or the default — so let your test environment use `array` for
it, as Laravel's `phpunit.xml` does with `CACHE_STORE=array`; a lock left in a shared store by one test
is still held in the next.

**Test listeners by running the job**, not by posting. That separates "did the endpoint accept it" from
"did my listener do the right thing":

```php
use Illuminate\Support\Facades\Event;
use Peoplelogy\XeroBridge\Events\XeroWebhookReceived;
use Peoplelogy\XeroBridge\Jobs\ProcessXeroWebhook;

it('dispatches one event per item, in order', function () {
    Event::fake([XeroWebhookReceived::class]);

    $payload = [
        'events' => [[
            'resourceId' => 'ed255415-e141-4150-aab7-89c3bbbb851c',
            'eventDateUtc' => '2026-09-28T01:15:39.902',
            'eventType' => 'UPDATE',
            'eventCategory' => 'INVOICE',
            'tenantId' => 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d',
            'tenantType' => 'ORGANISATION',
        ]],
    ];

    (new ProcessXeroWebhook($payload))->handle();

    Event::assertDispatchedTimes(XeroWebhookReceived::class, 1);
});
```

Running the job by hand takes no lock.

To exercise the real delivery path locally, expose your machine over https on port 443 with a tunnel and
set `APP_URL` to the tunnel's hostname before running `php artisan xero-bridge:install`, so the URL it
prints is the one to paste into Xero.
