# API call capture

Every Xero and LHDN request and response, recorded into a table your own
application can read and render.

The package ships **no viewer** for this. That is deliberate: every project has
its own roles and its own idea of who may look at customer data, and a package
cannot know either. What it gives you is a stable table, an Eloquent model and
the guarantee that nothing dangerous is in it.

The [test console](07-test-console.md) at `/xero/console` is a developer tool,
off unless `XERO_CONSOLE_ENABLED=true`, and it does not show this data.

---

## Turning it on

Three steps. Nothing happens until all three are done, and a project that
upgrades and does none of them keeps exactly the behaviour it had.

```bash
php artisan vendor:publish --tag=xero-bridge-migrations
php artisan migrate
```

```dotenv
XERO_CAPTURE=true
```

That is the whole setup. The defaults are chosen to be the ones most projects
should keep.

| Key | Default | What it does |
| --- | --- | --- |
| `XERO_CAPTURE` | `false` | The switch. |
| `XERO_CAPTURE_MODE` | `writes` | `all`, `writes` or `errors`. See below. |
| `XERO_CAPTURE_RETAIN_DAYS` | `90` | Pruned by `xero-bridge:prune`. |
| `XERO_CAPTURE_MAX_BODY_BYTES` | `65536` | Per body, after redaction. |
| `XERO_CAPTURE_TABLE` | `xero_api_calls` | Bare name; your connection prefix applies. Set it before migrating if the name is taken — see below. |
| `XERO_CAPTURE_XERO_API` | `true` | Record Xero Accounting API calls. |
| `XERO_CAPTURE_XERO_IDENTITY` | `true` | Record token exchanges with identity.xero.com (every credential in them is redacted). |
| `XERO_CAPTURE_MYINVOIS_API` | `true` | Record LHDN MyInvois API calls. |
| `XERO_CAPTURE_MYINVOIS_TOKEN` | `true` | Record MyInvois token requests (credentials redacted). |
| `XERO_CAPTURE_PLACEHOLDER` | `[redacted]` | What a removed value becomes. |

**Until the table exists, the switch records nothing.** The package asks the
database once per process whether the table is there, logs one line naming the
publish command, and keeps a "no table" answer for the life of the process — so
a queue worker or Octane server that asked before you migrated captures nothing
until it restarts (`php artisan queue:restart`). A check that fails outright,
because the database could not be asked, counts as "no" for a minute and is then
asked again.

**If the name is already taken.** On a connection with `'prefix' => 'app_'` the
table is `app_xero_api_calls`. If a table of that name already exists and is not
the package's, set `XERO_CAPTURE_TABLE` to an unused bare name **before** you
migrate, in every environment — the model reads it at runtime too — and leave it
set. The migration checks rather than trusts the name: over a same-named table
that lacks any column it would have created, it stops before changing anything,
names the table, the missing columns and `XERO_CAPTURE_TABLE`, and is not
recorded as run, so the next `php artisan migrate` resumes at it once the
variable is set. Rolled back, it drops only a table it could have created, never
a stranger's. A copy published before 1.5.0 does neither, and `composer update`
does not change a file you have published. While capture is on,
`xero-bridge:status` warns about a table of that name that is not the package's.

**Modes.** `writes` is the default because reads are the volume and the flood of
`GET /Invoices` during a sync answers no question a human asks. A **failed** call
is captured in every mode, including a failed read — and note that a Xero error
can arrive as an HTTP **200** (a rejected batch does exactly that), which is why
the rule is not keyed on the status code alone.

Schedule the prune, or the table grows forever:

```php
// routes/console.php
Schedule::command('xero-bridge:prune')->dailyAt('02:00');
```

Prune deletes only from the package's own table. A table of yours named `xero_api_calls` — one
lacking any of the package's `logical_call_id`, `channel` and `created_at` columns — is skipped with an error naming
`XERO_CAPTURE_TABLE`, whether capture is on or off. Up to 1.4.3 prune deleted its rows older than
`XERO_CAPTURE_RETAIN_DAYS`.

---

## Reading it

Use the model. The table is stable, but the model is the supported interface and
the scopes are where the useful questions already live.

```php
use Peoplelogy\XeroBridge\Models\XeroApiCall;

// Everything we sent for one of YOUR records.
XeroApiCall::forOwner($order)->latest()->get();

// What went wrong today.
XeroApiCall::failed()->whereDate('created_at', today())->get();

// One logical call, including the 401 replay, in order.
XeroApiCall::logicalCall($row->logical_call_id)->get();

// Just the LHDN traffic.
XeroApiCall::channel('myinvois.api')->latest()->paginate(50);
```

### Attributing a call to your own record

This is what makes a dashboard possible — without it you cannot join these rows
to your orders or your customers.

```php
app(ApiCallRecorder::class)->forOwner($order, function () use ($order) {
    XeroBridge::connection()->invoices()->create($payload);
});
```

Every call made inside the closure carries `owner_type` and `owner_id`. The
owner is restored afterwards, so it cannot leak into the next job on a
long-running worker.

### The columns

| Column | Notes |
| --- | --- |
| `channel` | `xero.api`, `xero.identity`, `myinvois.api`, `myinvois.token` |
| `connection_key`, `tenant_id` | Which credential, which organisation |
| `logical_call_id`, `attempt` | One logical call can be two wire attempts |
| `method`, `url` | The URL is redacted like a body |
| `request_headers`, `request_body` | Redacted |
| `status` | **`null` means the call never got an answer** |
| `response_headers`, `response_body` | Redacted; body only when it was JSON |
| `content_type`, `response_bytes` | Kept even when the body was not |
| `duration_ms`, `error` | |
| `redacted_keys` | Which rules fired on this row |
| `idempotency_key` | Joins to `xero_write_records` |
| `correlation_id` | The id LHDN support asks for |
| `owner_type`, `owner_id` | Your record |

---

## What is never stored

Redaction happens **before the insert**, never on the way out. A raw row would
reach the binary log, the nightly backup, the read replica and every `SELECT *`
in a support tool; a bug in a write-side redactor loses a field, a bug in a
read-side one is a leak that has already happened.

**Credentials.** The `Authorization` header on every call, `access_token`,
`refresh_token`, `id_token`, `client_secret`, the OAuth `code`, the revocation
`token`, the webhook HMAC header, and `Organisation.APIKey` — which sits on an
endpoint everyone thinks of as harmless settings data.

**Bank details.** `BankAccountNumber` (your organisation's on an Account, a
supplier's on a Payment), `BankAccountDetails` (your customer's, echoed back on
every contact read), the whole `BatchPayments` / `BatchPayment` object, and
`BankAccountName`.

**Neither of those groups can be switched back on.** `capture.redact.keep` does
not reach them. Two exceptions in this package promise in writing that nothing
it produces carries a token, and "save everything except bank account" is a
requirement rather than a preference — neither should be undoable by one line of
configuration.

**Identifiers.** The MyInvois TIN, which lives in the URL **path**; `idValue`,
the NRIC, passport or BRN, which lives in the **query string**; the `onbehalfof`
header; `Organisation.TaxNumber`; `XeroNetworkKey`; and `OnlineInvoiceUrl`, a
public capability link that anyone holding can use to view and pay the invoice.

`Contact.TaxNumber` — the Malaysian TIN — is **masked to its last four**
(`****2020`) rather than blanked, because that is already this package's accepted
disclosure level for a TIN and it is what `myinvois_validations.tin_last4` holds,
so a captured row still joins to the verdict row for the same check.

**Binary responses.** `GET /Invoices/{id}` as a PDF keeps its `content_type` and
`response_bytes` and nothing else. The rendered invoice prints your own bank
details out of the branding theme, and no field rule can reach inside it.

### What is deliberately kept

Every one of these is a field someone will be tempted to add. Each costs more
than it saves:

| Kept | Why |
| --- | --- |
| `Contact.AccountNumber` | **Not a bank account.** Xero calls it "a user defined account number" — in practice your own customer code, and the first thing support looks for. |
| `BankAccountType` | The `BANK` / `CREDITCARD` enum that explains a rejected payment. |
| `Code`, `AccountID` | `Payments::create()` sends `Account.Code`. Without it a row cannot say which ledger account was hit. |
| `CompanyNumber` | The BRN is public SSM register data, and when LHDN rejects a TIN/BRN pair it is almost always the BRN. |
| `correlationId` | Unrecoverable afterwards, and the one id LHDN support asks for. |
| `Idempotency-Key` | The only way to tell a retry from a genuine duplicate. |
| `Xero-tenant-id` | Without it a row cannot be attributed to an organisation. |
| `client_id` | Not secret, and it is how you tell sandbox from production. |
| `idType` | With `idValue` gone, the only clue what was checked. |
| `EmailAddress` | "Who did Xero email this to?" has no API to read back. |

A `where` clause keeps its **structure** but not its operands, because this
package builds those clauses itself — `Contacts::findByEmail()` sends
`where=EmailAddress=="…"`, so the stored URL reads
`EmailAddress=="[redacted]"`. `SearchTerm` has no structure to keep and is
removed outright.

### Adding to the list

Deltas, not a replacement list — so a project that publishes the config today
still receives every field name added in later versions.

```php
'redact' => [
    // For a stricter PDPA posture:
    'add' => ['phonenumber', 'addressline1'],

    // If your organisation types account references into the NZ bank
    // reference fields, add 'particulars' and 'details' too.

    // Removable: taxnumber, idvalue, onbehalfof, searchterm, target,
    // xeronetworkkey, onlineinvoiceurl. Credentials and bank fields are not.
    'keep' => [],
],
```

---

## Two things to know before you rely on it

**This is not a ledger.** The row is written *after* the response, so a worker
killed mid-call leaves no row at all. "Did that invoice reach Xero?" is answered
by `xero_write_records`, which is inserted *before* the request precisely so it
survives that. Never answer it from here.

**Recording is best-effort.** If the insert fails, the recorder warns once per
process — `xero-bridge: could not record an API call, so the capture table will
have gaps. The call itself was unaffected.` — and the Xero call carries on
unaffected. That is the correct trade — capture must never be the thing that
breaks a payment run — but it means the table can have gaps, and it is not
evidence of absence. A missing table is just as quiet after its one line (see
[Turning it on](#turning-it-on)), and a column you add to the table without a
default makes every insert fail.

---

## Volume

One invoice is roughly three to four API calls, each a row in `all` mode. At 100
invoices a day that is about 400 rows a day, and `writes` mode cuts it to the
calls that changed something plus anything that failed. With the default 90-day
retention the table settles at a few tens of thousands of rows; the bodies
dominate the size, which is what `max_body_bytes` is for.

## Related

- [09-persistence.md](09-persistence.md) — the write ledger, webhook dedupe and MyInvois verdict tables
- [08-myinvois-tin-validation.md](08-myinvois-tin-validation.md) — the LHDN module
- [07-test-console.md](07-test-console.md) — the developer console, which does not show this data
