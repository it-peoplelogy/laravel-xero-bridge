# 9. Persistence

Three optional tables, all **off by default**, that record what the package did.

- [Why the package owns these](#why-the-package-owns-these)
- [Turning them on](#turning-them-on)
- [Write dedupe](#write-dedupe)
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
php artisan vendor:publish --tag=xero-bridge-migrations   # the two Xero tables
php artisan vendor:publish --tag=myinvois-migrations      # the MyInvois table
php artisan migrate
```

Then switch on only what you want:

```bash
XERO_WRITES_LEDGER=true      # duplicate protection for writes
XERO_WEBHOOK_DEDUPE=true     # durable replay dedupe
MYINVOIS_AUDIT=true          # TIN verdict record
```

**Nothing happens until both.** A flag with no table is a no-op — `TableGuard` checks once per
process and logs a single line naming the publish command. A table with no flag is an empty table.

---

## Write dedupe

```php
XeroBridge::invoices()->for($order)->create([...]);
```

A second attempt for the same record throws `XeroWriteAlreadyClaimedException` carrying the id
already created:

```php
try {
    $invoice = XeroBridge::invoices()->for($order)->create([...]);
} catch (XeroWriteAlreadyClaimedException $e) {
    if ($e->isPending()) {
        // Something was sent and the outcome was never recorded. Do not re-send.
        return;
    }

    $invoiceId = $e->xeroId();   // already created; carry on
}
```

Works for `invoices()->create()`, `contacts()->create()` and `payments()->create()`.

Two legitimate writes for one record are distinguished by a reference:

```php
XeroBridge::invoices()->for($order, 'deposit')->create([...]);
XeroBridge::invoices()->for($order, 'final')->create([...]);
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
which is money and is found by the customer, to *one stuck row*, which a human clears and
`xero-bridge:prune` reports.

> **Do not wrap a Xero write in a database transaction.** The claim must be committed before the
> HTTP call. Inside a transaction it is invisible to other workers until you commit, and a rollback
> after Xero accepted the invoice erases the only record that it exists. The package logs a warning
> if it notices one.

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

There is also a fix that needs **no table at all** and arrives with the upgrade: `ProcessXeroWebhook`
is now a unique job with retries. The controller dispatches before returning its 200, so a response
that misses Xero's five-second budget previously meant the retry queued a second identical job and
every listener fired twice.

---

## MyInvois verdicts

With `MYINVOIS_AUDIT=true`, every validation is recorded — both answers, because "LHDN has no
record of this pair" is exactly what somebody asks about later.

```php
MyInvois::for($customer)->validate($tin, IdType::BRN, $brn);
```

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
php artisan xero-bridge:prune            # all three tables
php artisan xero-bridge:prune --dry-run  # report only
```

```php
Schedule::command('xero-bridge:prune')->daily();
```

| Table | Kept | Key |
|---|---|---|
| `xero_write_records` | `XERO_WRITES_RETAIN_DAYS`, default 90 | **succeeded rows only** |
| `xero_webhook_events` | `XERO_WEBHOOK_DEDUPE_RETAIN_DAYS`, default 45 | clamped to a **minimum of 32** |
| `myinvois_validations` | `MYINVOIS_AUDIT_RETAIN_DAYS`, default 400 | since last checked |

The webhook clamp is not a suggestion: pruning inside Xero's 31-day replay window would delete
exactly the rows that make a late replay detectable, which is the only thing that table is for.

**Pending write claims are never pruned**, at any age. The command **exits 1** when any has been
pending over an hour — wire that to monitoring, because each one blocks further writes for its
record.

---

## Upgrading

A consumer who runs `composer update` and nothing else gets **no new tables and no behaviour
change**. The migrations ship as stubs and are published deliberately; every recorder is inert until
both its flag and its table exist.

One thing does change without asking: `ProcessXeroWebhook` now retries (5 attempts, backing off
10s / 30s / 2m / 10m). Before, it had no `$tries`, so Laravel's default of a single attempt applied
— one failing listener and the delivery was lost. If you relied on that, set `XERO_WEBHOOK_TRIES=1`.

The uniqueness lock uses your cache store. `array` and `file` give no cross-process guarantee, so
with several queue workers point `XERO_WEBHOOK_UNIQUE_FOR`'s store at redis, memcached or database —
the same caveat that already applies to token refresh locking.
