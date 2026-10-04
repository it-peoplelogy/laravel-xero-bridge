# 9. Persistence

Three optional tables, all **off by default**, that record what the package did.

- [Why the package owns these](#why-the-package-owns-these)
- [Turning them on](#turning-them-on)
- [Write dedupe](#write-dedupe)
  - [When the ledger itself fails](#when-the-ledger-itself-fails)
- [Webhook replay dedupe](#webhook-replay-dedupe)
- [MyInvois verdicts](#myinvois-verdicts)
- [Pruning](#pruning)
- [Upgrading](#upgrading)

---

## Why the package owns these

Each answers a question the package created and the application cannot answer alone.

Xero honours an idempotency key for **six minutes**. No realistic queue backoff stays inside that,
so a job retried an hour later creates a **second invoice**. The documentation has said for a long
time that the real defence is to persist the returned `InvoiceID` — which meant every consumer
building the same table, and getting the unique index right, independently.

Xero replays undelivered webhooks for **31 days**. `entropy` differs between deliveries, so it is
useless as a key; the correct one is `tenantId + resourceId + eventType + eventDateUtc`. That is
knowledge about Xero's protocol, not about your domain.

LHDN asks you to validate a TIN once and reflect it in your system rather than calling repeatedly.

None of it works without a table. All of it is the same table for every consumer.

---

## Turning them on

```bash
php artisan vendor:publish --tag=xero-bridge-migrations   # the Xero tables
php artisan vendor:publish --tag=myinvois-migrations      # the MyInvois table
php artisan migrate
```

The Xero tag holds all four of the package's Xero migrations: the write ledger and the webhook replay
table on this page, the [API capture](10-api-capture.md) table, and the connections table that
`xero-bridge:install` publishes. A migration you have already published is left as it is.

Then switch on only what you want:

```bash
XERO_WRITES_LEDGER=true      # duplicate protection for writes
XERO_WEBHOOK_DEDUPE=true     # durable replay dedupe
MYINVOIS_AUDIT=true          # TIN verdict record
```

**Nothing happens until both.** A flag with no table is a no-op — with one exception, the write ledger
in [strict mode](#when-the-ledger-itself-fails). The package asks the database once per process whether
the table is there, logs a single line naming the publish command, and keeps a "no table" answer for
the life of the process: a queue worker or Octane server that asked before you migrated records nothing
until it restarts (`php artisan queue:restart`). A check that fails outright, because the database
could not be asked, counts as "no" for a minute and is then asked again. A table with no flag is an
empty table.

### If a table name is already taken

Each table is created under a **bare** name, and your database connection adds its own prefix: on a
connection with `'prefix' => 'app_'`, `xero_write_records` becomes `app_xero_write_records`. If your
database already has a table of that name that is not the package's, set its variable to an unused
bare name **before** you migrate:

| Table | Variable | Config key |
|---|---|---|
| `xero_write_records` | `XERO_WRITES_TABLE` | `xero-bridge.writes.table` |
| `xero_webhook_events` | `XERO_WEBHOOK_DEDUPE_TABLE` | `xero-bridge.webhooks.dedupe.table` |
| `myinvois_validations` | `MYINVOIS_AUDIT_TABLE` | `myinvois.audit.table` |

The package's models read the same variable at runtime, so set it in every environment, and run
`php artisan config:clear` first if your configuration is cached. Then leave it: changed after
migrating, it points the package at a table that does not exist, while the real one stays where it
was. The capture and connections tables work the same way —
[Getting started](01-getting-started.md#3-publish-and-migrate) lists every variable.

The migrations check rather than trust the name. One that finds a same-named table missing any column
it would have created refuses before changing anything: its message names the table, the missing
columns and the variable to set, the migration is not recorded as run, and the next
`php artisan migrate` resumes at it once the variable is set. Columns you add to the package's own
table do not count against it. On rollback, a migration drops only a table it could have created, never
a stranger's.

That is how migrations published from 1.5.0 on behave; a copy published earlier is your own file, and
`composer update` does not change it. Either way, `xero-bridge:status` reports a write ledger or replay
table that is not the package's while its feature is on.

---

## Write dedupe

```php
XeroBridge::invoices()->for($order)->create($payload);
```

A second attempt for the same record throws `XeroWriteAlreadyClaimedException` carrying the id
already created. In a queued job:

```php
use Peoplelogy\XeroBridge\Exceptions\XeroWriteAlreadyClaimedException;
use Peoplelogy\XeroBridge\Exceptions\XeroWriteLedgerUnavailableException;
use Peoplelogy\XeroBridge\Facades\XeroBridge;

try {
    $invoice = XeroBridge::invoices()->for($order)->create($payload);
} catch (XeroWriteLedgerUnavailableException $e) {
    // Strict mode only: the ledger could not record the claim, so nothing
    // was sent and retrying is safe. The package does not log the refusal.
    report($e);
    $this->release(60);

    return;
} catch (XeroWriteAlreadyClaimedException $e) {
    if ($e->isPending()) {
        // Something was sent and the outcome was never recorded. Do not
        // re-send. XeroWriteBlocked has already told your monitoring.
        return;
    }

    $invoiceId = $e->xeroId();   // already created; carry on
}
```

The two exceptions ask for opposite things. Both extend `XeroBridgeException`, so a catch-all that
marks a record failed has to single the second one out as retryable:

| Exception | What it means | What the job does |
|---|---|---|
| `XeroWriteAlreadyClaimedException` | The ledger **worked**: this write has already been made, or is in flight. | **Never writes again.** It reads `xeroId()`, or leaves a pending claim to a person: retrying loops for ever, or asks for the very duplicate the ledger just prevented. |
| `XeroWriteLedgerUnavailableException` | The ledger **could not answer**, in [strict mode](#when-the-ledger-itself-fails). Nothing was sent. | **Retries**, once the table is migrated and the database is reachable. `retryAfter()` is `null`, so the job's own backoff applies. |

Both carry the connection the write was for: `$e->connectionKey()`, and `connection` in
`$e->context()`.

A pending block returns quietly in that recipe — it has to, since re-sending could duplicate the
invoice — so the package announces it instead. Just before the exception it dispatches
[`XeroWriteBlocked`](04-webhooks-and-events.md#xerowriteblocked), with `pending` and `claimedAt`, the
time the existing claim was taken. A pending claim that is more than a few minutes old is a stuck one,
and worth paging on:

```php
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Peoplelogy\XeroBridge\Events\XeroWriteBlocked;

Event::listen(function (XeroWriteBlocked $blocked) {
    $stuck = $blocked->pending
        && ($blocked->claimedAt === null || $blocked->claimedAt->lessThan(now()->subMinutes(10)));

    if ($stuck) {
        Log::critical('A Xero write is blocked by a stuck claim.', [
            'operation' => $blocked->operation,
            'owner' => "{$blocked->ownerType}#{$blocked->ownerId}",
            'connection' => $blocked->connectionKey,
            'claimed_at' => $blocked->claimedAt?->toIso8601String(),
        ]);
    }
});
```

A pending claim seconds old is two workers racing, and a confirmed block — `pending` false — is the
protection working: count those, do not page on them.

Works for `invoices()->create()`, `contacts()->create()` and `payments()->create()` —
`createForInvoice()` included, since it calls `create()`. **Not** for `createMany()`, which takes no
claim: `for()->createMany()` is sent unprotected and logs a warning saying so. Call `create()` once per
invoice to protect each one.

Two legitimate writes for one record are distinguished by a reference:

```php
XeroBridge::invoices()->for($order, 'deposit')->create($depositInvoice);
XeroBridge::invoices()->for($order, 'final')->create($finalInvoice);
```

A write with **no** `for()` is recorded but never deduplicated — there is nothing to deduplicate
against.

### What happens when it goes wrong

The claim row is inserted **before** the request leaves. That is deliberate, and it is the whole
design:

| Xero said | The claim | Why |
|---|---|---|
| 200 | confirmed, with the id | the normal path |
| 400 rejected the payload | **deleted** | non-creation is proven, so the slot frees for a corrected retry |
| 500, timeout, killed worker | **stays pending, for ever** | nothing proves the invoice does not exist |

A pending claim blocks further writes for that record **at any age**, and nothing re-sends on its
own. It has no expiry on purpose: expiring it would re-send after a worker died mid-write, which is
exactly the duplicate this prevents.

So the failure mode is swapped, knowingly — from *a duplicate invoice in a customer's ledger*,
which is money and is found by the customer, to *one stuck row*, which a human clears. Three things
report one: `XeroWriteBlocked`, the moment it blocks a write; and once it has been pending for over an
hour, `xero-bridge:prune`, which exits `1`, and — while `XERO_WRITES_LEDGER` is on —
`xero-bridge:status`, which warns and, under `--strict`, exits `1`.

**Only a duplicate is a duplicate.** A claim counts as already held only when its insert fails on the
claim's unique index, which Laravel raises as `UniqueConstraintViolationException`. Any other failure —
a lost connection, a `NOT NULL` or foreign-key violation from a column you added — is the ledger
failing, and is handled as [below](#when-the-ledger-itself-fails), never mistaken for a write in
flight. One safeguard: when an owned write's insert fails on any integrity constraint (SQLSTATE class
23), the ledger first looks the claim up, and if a row already holds it, refuses the write as the
duplicate it is. SQLite, for one, checks `NOT NULL` before the unique index, so a duplicate can surface
as the other error.

> **Do not wrap a Xero write in a database transaction.** The claim must be committed before the
> HTTP call. Inside a transaction it is invisible to other workers until you commit, and a rollback
> after Xero accepted the invoice erases the only record that it exists. The package logs a warning
> when it finds a transaction open on the ledger's own connection — `XERO_DB_CONNECTION`, or the
> default connection while that is unset. It stays a warning in strict mode too, because a test suite
> using `RefreshDatabase` runs every test inside one.

### When the ledger itself fails

The ledger can fail too: its table is missing, the database is unreachable, or the insert fails for
any reason other than a duplicate. `XERO_WRITES_STRICT` decides what the write does then.

| `XERO_WRITES_STRICT` | When a claim cannot be recorded |
|---|---|
| `false` (the default) | The write is sent **unprotected**, as every release before 1.5.0 did. A failed insert logs an error each time — `xero-bridge: could not claim a write, proceeding WITHOUT duplicate protection.` — and a missing table gets the one-line warning described under [Turning them on](#turning-them-on). |
| `true` | The write is **refused before anything is sent**, with `XeroWriteLedgerUnavailableException`. Nothing reached Xero, so retrying is safe once the table is migrated and the database is reachable. |

Strict mode refuses only a write that names an owner — `for($order)` — because only those are
deduplicated. A write with no owner has nothing for the ledger to protect, and always goes out. Strict
mode needs the ledger itself on (`XERO_WRITES_LEDGER=true`), and it does not use the once-per-process
table check: the insert decides. So a table migrated under a running worker protects its writes at
once, and one failed check cannot go on refusing writes after the database is back.

The exception names the write and its record — `The write ledger could not record the claim for
invoice.create (App\Models\Order#42), so the write was REFUSED and nothing was sent to Xero
(XERO_WRITES_STRICT is on). …` — and `getPrevious()` is what stopped the claim, usually a
`QueryException`. The package does not log the refusal: the exception is the signal, so a job that
catches it to retry should log or report it, as the recipe above does.

What strict mode does not change:

- A write inside a database transaction is still only warned about.
- `createMany()` takes no claim, and `XeroBridge::request()` and `raw()` never touch the ledger.
- Recording Xero's answer never throws, in either mode. If a write succeeded but its row cannot be
  updated, the row stays pending — it blocks, which is the safe direction — and an error is logged.
  The write exists in Xero by then, so throwing would report a created invoice as a failure.
- A genuine duplicate is still `XeroWriteAlreadyClaimedException`.

> ⚠️ **A column you add to `xero_write_records` needs a default, or must be nullable.** Without one
> every insert fails, and every owned write is then sent unprotected — or, in strict mode, refused.

`xero-bridge:status` shows whether the protection is actually in force. While `XERO_WRITES_LEDGER` is on,
it warns about a missing, unreadable or foreign ledger table — saying so when strict mode is refusing
writes because of it — and about stuck claims, and `--strict` exits `1` on any of them. Its `--json`
output has a `write_ledger` object, with `enabled`, `strict`, `table`, `table_present` and `stuck`, read
exactly as the ledger reads them: it is the place to confirm that `XERO_WRITES_STRICT` took.

---

## Webhook replay dedupe

With `XERO_WEBHOOK_DEDUPE=true`, an event already dispatched is skipped and its `delivery_count`
incremented — which is the only direct evidence that the 31-day window is being exercised.

```php
XeroWebhookEvent::where('delivery_count', '>', 1)->count();   // how often replays actually happen
```

The row is written **after** the dispatch, the opposite of the write ledger. Claiming first would
mean a worker killed between claim and dispatch suppresses that notification permanently, and Xero
will not send it again once your endpoint has 200'd. Dispatching first makes the worst case a
duplicate — which listeners are already required to tolerate.

**This does not make your listeners idempotent**, and it is not a substitute for writing them that
way. It closes the common case; a database outage or a disabled table reopens it.

Recording never fails the job, because it runs after the listeners: an exception there would retry the
job and dispatch the event again. A duplicate key is a redelivery and is counted. Anything else — an
altered table, a column you added without a default, a lost connection — is logged as
`xero-bridge: could not record a dispatched webhook event.` at `warning`, the job carries on, and a
later delivery of that event may be dispatched again. With `XERO_WEBHOOK_UNKNOWN_TENANTS=ignore`, an
event [skipped for an organisation you have not connected](04-webhooks-and-events.md#organisations-you-have-not-connected)
is never recorded, so it can never suppress a later delivery once that organisation is connected.

There is also protection that needs **no table at all**. The controller queues the job before returning
its 200, so a 200 that misses Xero's five-second budget is followed by a retry while the first job is
still queued or running. The controller takes a uniqueness lock on the delivery's events before
queuing, and that retry is answered 200 without being queued a second time — or 503, so Xero tries
again later, when it arrives before the first delivery is confirmed queued. The job retries too:
5 attempts, backing off 10s / 30s / 2m / 10m. The lock lasts minutes, not days — it stops a retry storm,
and this table catches the late replay. How the lock behaves, and which cache store holds it, is in
[When Xero retries a delivery](04-webhooks-and-events.md#when-xero-retries-a-delivery).

---

## MyInvois verdicts

With `MYINVOIS_AUDIT=true`, every validation is recorded — both answers, because "LHDN has no
record of this pair" is exactly what somebody asks about later.

```php
MyInvois::for($customer)->validate($tin, IdType::BRN, $brn);
```

A verdict that cannot be stored is logged as `xero-bridge: could not record a MyInvois verdict.` at
`warning`, and the validation goes on regardless. Two processes recording the same subject at once is
the unique index doing its job, and stays silent — but only a genuine duplicate key counts as that; a
`NOT NULL` or foreign-key failure is reported like any other.

### What it stores, and what it refuses to

**Not the TIN, and not the identifier.** They appear only as a keyed HMAC.

A plain hash would be theatre. A Malaysian registration number is twelve digits; an NRIC is twelve
digits whose first six are a date of birth and whose middle two are a state code, leaving well under
10⁸ possibilities. A bare SHA-256 of either is exhausted on a laptop in **under a second** — anyone
with the table would have the identifiers. The HMAC is keyed with a sub-key derived from your
application key, so a stolen backup or an open replica is not enough on its own.

**The cost, stated plainly:** rotate `APP_KEY` and every stored hash becomes unmatchable. Old rows
orphan and the next check writes a new one.

Readable columns are the id **type**, the **last four** characters of the TIN, the verdict, the HTTP
status, LHDN's `correlation_id`, the environment and the rules version.

### Erasure

```php
app(MyInvoisAudit::class)->forgetOwner($customer);            // usually from a deleting hook
app(MyInvoisAudit::class)->forgetSubject($tin, $idType, $id); // when you still hold the values
```

Only the caller can compute the hash, because the table deliberately cannot.

### Rules versions

`rules_version` records which LHDN rules produced a verdict. Version 2 is from **1 August 2026**,
when LHDN began validating the TIN and the identifier as a *pair*. A verdict recorded under version
1 answered a weaker question:

```php
$verdict->isStale(MyInvoisAudit::CURRENT_RULES_VERSION);
```

LHDN has changed the rules on a month's notice before. When they do it again, the constant is bumped
and every earlier verdict becomes identifiably stale rather than quietly wrong.

---

## Pruning

```bash
php artisan xero-bridge:prune            # all four tables
php artisan xero-bridge:prune --dry-run  # report only
```

```php
Schedule::command('xero-bridge:prune')->daily();
```

| Table | Kept | Key |
|---|---|---|
| `xero_write_records` | `XERO_WRITES_RETAIN_DAYS`, default 90 | **succeeded rows only** |
| `xero_webhook_events` | `XERO_WEBHOOK_DEDUPE_RETAIN_DAYS`, default 45 | clamped to a **minimum of 32** |
| `xero_api_calls` | `XERO_CAPTURE_RETAIN_DAYS`, default 90 | every row — see [API call capture](10-api-capture.md) |
| `myinvois_validations` | `MYINVOIS_AUDIT_RETAIN_DAYS`, default 400 | since last checked |

The webhook clamp is not a suggestion: pruning inside Xero's 31-day replay window would delete
exactly the rows that make a late replay detectable, which is the only thing that table is for.

**Pending write claims are never pruned**, at any age. The command **exits 1** when any has been
pending over an hour — wire that to monitoring, because each one blocks further writes for its
record. `xero-bridge:status` reports the same claims while `XERO_WRITES_LEDGER` is on.

A table that is not there is skipped without a word, on screen or in the log, so a feature you never
switched on costs nothing; `-v` lists each one with the publish tag that creates it. A table whose
check fails prints an error and the run carries on, without changing the exit code. A table under one of
these names that is not the package's — it lacks the package's own columns, such as `claim_key` or
`logical_call_id` — is never pruned: it is skipped with an error naming the table and the setting to
change, whether or not its feature is on. Up to 1.4.3 prune deleted such a table's old rows. The command
and its output are in [Commands](05-commands-and-errors.md#xero-bridgeprune).

---

## Upgrading

`composer update peoplelogy/laravel-xero-bridge` creates no tables and switches nothing on here. The
migrations ship as stubs and are published deliberately, and every recorder is inert until both its
flag and its table exist.

A setting a release adds to these features reaches a `config/xero-bridge.php` or `config/myinvois.php`
you published earlier by itself: the package fills in any key your copy lacks, and never changes one it
has. That includes the whole `webhooks.dedupe` block for a config published before 1.4.0, which had
none — so the `XERO_WEBHOOK_DEDUPE*` variables work there too. While your configuration is cached, a
variable you set takes effect at the next `php artisan config:cache`, as any would.

`ProcessXeroWebhook` retries: 5 attempts in all (`XERO_WEBHOOK_TRIES`), backing off 10s / 30s / 2m /
10m. Before 1.4.0 it had no `$tries`, so Laravel's default of a single attempt applied — one failing
listener and the delivery was lost. If you relied on that, set `XERO_WEBHOOK_TRIES=1`.

The uniqueness lock on that job is held in the cache store `XERO_LOCK_STORE` names — the store token
refreshes lock in — or in the default store while that is unset. `XERO_WEBHOOK_UNIQUE_FOR` is not a
store setting: it is how long the lock may be held, in seconds, if the job never finishes. `array`
locks only inside one process and `file` only within one server, so once your web servers and queue
workers run on more than one server, set `XERO_LOCK_STORE` to redis, memcached or database — the same
caveat that applies to token refresh locking.
