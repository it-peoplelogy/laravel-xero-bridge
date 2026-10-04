# Invoices

`XeroBridge::invoices()` wraps Xero's `/Invoices` endpoints. It takes and returns plain PHP arrays in
Xero's own shape — there is no model layer, no camelCase translation and no date casting. What you pass
in is what Xero receives (plus the connection defaults described below); what you get back is Xero's
JSON decoded as an associative array, with PascalCase keys and .NET dates such as
`/Date(1772409600000+0000)/`.

```php
use Peoplelogy\XeroBridge\Facades\XeroBridge;

$invoices = XeroBridge::invoices();                          // the default connection
$invoices = XeroBridge::connection('acme')->invoices();      // a named organisation
```

Read `/Date(...)` values with the helper rather than parsing them yourself:

```php
use Peoplelogy\XeroBridge\Support\XeroDate;

$due = XeroDate::from($invoice, 'DueDate');   // ?CarbonImmutable, prefers DueDateString
```

## Contents

- [Five things to know first](#five-things-to-know-first)
- [Connection defaults](#connection-defaults)
- [The two safety guards](#the-two-safety-guards)
- [Status transitions](#status-transitions)
- [Methods](#methods)
- [BatchResult](#batchresult)
- [Idempotency keys](#idempotency-keys)
- [InvoiceFilter](#invoicefilter)
- [Exceptions](#exceptions)
- [Events](#events)
- [Testing against a fake](#testing-against-a-fake)

---

## Five things to know first

1. **`Type` is never defaulted.** `ACCREC` is a sale, `ACCPAY` is a bill. Omitting it throws before any
   request is sent.
2. **Only `ContactID` may travel in the `Contact` block.** Any other field there updates the *contact
   record* and deletes `ContactPersons` you left out. Refused by default.
3. **`LineItems` on update is destructive.** A line without a `LineItemID` is deleted and recreated; a
   line you omit is deleted outright. Refused by default.
4. **There is no HTTP DELETE for invoices.** `delete()` and `void()` are `Status` changes, and which one
   is legal depends on the current status.
5. **Idempotency keys last six minutes.** They protect an immediate network retry, not a queued job that
   retries later. Persist the returned `InvoiceID` as your real defence against duplicates, or let the
   write ledger do it for you — see [Idempotency keys](#idempotency-keys).

> ⚠️ **The resource instance is shared.** `XeroBridge::invoices()` is memoised per connection key, so two
> calls return the *same* object — and in a queue worker, the same object for every job until the worker
> restarts. That is safe because nothing you call on it changes it: `withContactMutation()`,
> `replacingLineItems()` and the write ledger's `for()` each return a **copy**, and a resource taken
> through `withDefaults()` is built fresh. Chain them into the call that needs them; on a line of their
> own they do nothing. See [The two safety guards](#the-two-safety-guards).

---

## Connection defaults

Each connection carries four optional defaults, configured in `config/xero-bridge.php`:

```php
'connections' => [
    'default' => [
        'account_code'      => env('XERO_ACCOUNT_CODE'),    // no default: it differs per organisation
        'tax_type'          => env('XERO_TAX_TYPE'),        // null on purpose
        'currency'          => env('XERO_CURRENCY', 'MYR'),
        'branding_theme_id' => env('XERO_BRANDING_THEME_ID'),
    ],
],
```

| Config key | Filled into | Applies to |
|---|---|---|
| `account_code` | `LineItems[].AccountCode` | every line item |
| `tax_type` | `LineItems[].TaxType` | every line item, subject to the SST rule below |
| `currency` | `CurrencyCode` | the invoice |
| `branding_theme_id` | `BrandingThemeID` | the invoice |

Named connections inherit the `default` block; an explicit key wins even when it is set to `null`.

**The rules, exactly as implemented:**

- Defaults are applied by `create()` and `createMany()` only. **`update()` applies no defaults at all.**
- A value is only filled in when the caller left it out. `null` and `''` count as absent; the string
  `'0'` counts as present, because `0` is a legitimate account code.
- The key check is case-insensitive. If you write `'accountCode'`, the package leaves your key alone and
  does **not** add a second, conflicting `'AccountCode'`.
- Account codes and tax types differ per Xero organisation — a `200` sales account in one organisation
  may be `4000` in another. Discover them with `XeroBridge::connection($key)->settings()->accounts()`
  rather than hardcoding. That is why `account_code` ships with no default: with `XERO_ACCOUNT_CODE`
  unset, nothing is filled in and the line goes to Xero without an `AccountCode`.

### The SST rule: a line that already states tax is never touched

`tax_type` is deliberately unset by default. Malaysian SST is charged **per line** — training at 8%,
education and rental at 6% — so one invoice can legitimately carry two rates, and a connection-wide
default would stamp the wrong rate on a venue recharge.

The guard: a `TaxType` is injected only when the line states tax in **neither** form. A line carrying
`TaxType` *or* `TaxAmount` is left exactly as you wrote it.

```php
// With 'tax_type' => 'OUTPUT' configured:
XeroBridge::invoices()->create([
    'Type' => 'ACCREC',
    'Contact' => ['ContactID' => '11111111-1111-1111-1111-111111111111'],
    'LineItems' => [
        ['Description' => 'Leadership training, 2 days', 'TaxAmount' => 384],  // untouched
        ['Description' => 'Venue recharge', 'TaxType' => 'SST6'],              // untouched
        ['Description' => 'Courseware'],                                       // gets TaxType: OUTPUT
    ],
]);
```

### Overriding defaults

```php
$invoice = XeroBridge::withDefaults(['account_code' => '4000'])
    ->invoices()
    ->create($payload);
```

**An override applies to that expression only.** `withDefaults()` returns a clone of the manager, and
every resource taken from the clone is built fresh, with the override layered over the connection's
configured defaults. So the override is honoured even when `XeroBridge::invoices()` has already been
resolved for this connection, and it never reaches a later plain `XeroBridge::invoices()` call. Hold the
clone in a variable and the override goes wherever that variable goes — and nowhere else:

```php
$xero = XeroBridge::withDefaults(['account_code' => '4000', 'payment_account_code' => '090']);

$xero->invoices()->create($payload);                    // AccountCode 4000
$xero->payments()->createForInvoice($invoiceId, 100);   // Account.Code 090
XeroBridge::invoices()->create($payload);               // the configured default again
```

`tests/Unit/WithDefaultsTest.php` pins all of this, including that an override stays with its own
connection. `withDefaults()` and `connection()` chain in either order.

The keys that take effect are the four above, plus `payment_account_code`, which
`payments()->createForInvoice()` reads. Any other key is carried along — `defaults()->get()` returns it —
but never applied, so a misspelt key silently leaves the configured value in force.

> ⚠️ **A plain `XeroBridge::invoices()` reads the defaults once.** The memoised resource takes the
> connection's defaults from config when it is first built, and keeps them. A
> `config()->set('xero-bridge.connections.default.account_code', '4000')` made after that — in a queue
> worker, after an earlier job has already used `invoices()` on that connection — does not reach it,
> although `XeroBridge::defaults()` reports the new value. For a value that changes at runtime, use
> `withDefaults()` or put it on the payload.

---

## The two safety guards

### The contact guard

Xero's documented behaviour is that if you send contact details alongside a `ContactID`, those fields are
applied to the contact record itself **and any `ContactPersons` not included in the request are deleted**.
That is silent, irreversible, and typically noticed weeks later, so the package refuses it.

```php
// Throws UnsafeContactPayloadException — nothing is sent.
XeroBridge::invoices()->create([
    'Type' => 'ACCREC',
    'Contact' => ['ContactID' => '1111...', 'Name' => 'Acme Sdn Bhd'],
    'LineItems' => [['Description' => 'Training', 'Quantity' => 1, 'UnitAmount' => 4800]],
]);
```

The guard only fires when a `ContactID` is present. A `Contact` block with no `ContactID` is an inline
contact creation, which is safe and allowed:

```php
'Contact' => ['Name' => 'Brand New Customer'],    // fine
```

This guard runs on `create()`, `createMany()` **and** `update()`.

### The line-items guard

On update, Xero deletes and recreates any line missing a `LineItemID`, and deletes any line you leave out
entirely — "partial update" is a lie for `LineItems`. So `update()` refuses a `LineItems` array unless
every element carries a `LineItemID`:

```php
// Throws InvalidInvoicePayloadException — nothing is sent.
XeroBridge::invoices()->update($invoiceId, [
    'LineItems' => [['Description' => 'Changed']],
]);

// Accepted: every line identifies itself.
XeroBridge::invoices()->update($invoiceId, [
    'LineItems' => [['LineItemID' => '33333333-3333-3333-3333-333333333333', 'Description' => 'Changed']],
]);
```

### Opting in

```php
XeroBridge::invoices()->withContactMutation()->create($payload);   // I do mean to rewrite the contact
XeroBridge::invoices()->replacingLineItems()->update($id, $data);  // I do mean to replace every line
```

Each opt-in returns a **copy** of the resource with that one guard relaxed — never the shared instance,
the same idiom as the write ledger's [`for()`](09-persistence.md#write-dedupe). So:

- **It covers every call made through the copy, and nothing else.** That includes every invoice in a
  `createMany()`. No other caller of `XeroBridge::invoices()` is affected — not later in the request, and
  not a later job on the same queue worker.
- **It works on either side of `for()`.** `invoices()->for($order)->withContactMutation()->create(...)`
  and `invoices()->withContactMutation()->for($order)->create(...)` do the same thing.
- **On a line of its own it does nothing.** `$invoices->replacingLineItems();` returns a copy that the
  statement discards, so the next `$invoices->update(...)` is still guarded: the guard throws and nothing
  is sent. Chain the opt-in into the call — the exception message says exactly that.
- **A copy kept in a variable stays opted in** for every call made through it. Keep one only while every
  one of those calls is meant to rewrite the contact or replace the lines.

```php
$invoices = XeroBridge::invoices();

$invoices->withContactMutation();             // does nothing: the copy is discarded
$invoices->create($payloadWithContactName);   // throws UnsafeContactPayloadException, nothing sent

$invoices->withContactMutation()->create($payloadWithContactName);   // sent
```

`tests/Unit/InvoicesTest.php` pins each of these, and `tests/Feature/WriteLedgerTest.php` the two orders
around `for()`.

---

## Status transitions

Both "delete" and "void" are a `POST` that sets `Status`. `Peoplelogy\XeroBridge\Support\InvoiceTransitions`
holds Xero's table and the package checks it locally, so the failure names the current status instead of
relaying an opaque Xero error.

| From | Can become |
|---|---|
| `DRAFT` | `DRAFT`, `SUBMITTED`, `AUTHORISED`, `DELETED` |
| `SUBMITTED` | `SUBMITTED`, `AUTHORISED`, `DRAFT`, `DELETED` |
| `AUTHORISED` | `AUTHORISED`, `VOIDED` — and `VOIDED` only while no payment is applied |
| `PAID` | nothing |
| `VOIDED` | nothing |
| `DELETED` | nothing |

Two consequences worth internalising: `DELETED` is reachable from `SUBMITTED` as well as `DRAFT`, and a
`PAID` invoice cannot be changed at all — remove the payment first with
`XeroBridge::payments()->delete($paymentId)`.

The table is public if you need it for your own UI:

```php
use Peoplelogy\XeroBridge\Support\InvoiceTransitions;

InvoiceTransitions::allows('DRAFT', 'DELETED');   // true
InvoiceTransitions::isVoidable('DRAFT');          // false
InvoiceTransitions::isDeletable('SUBMITTED');     // true
InvoiceTransitions::allowedFrom('AUTHORISED');    // ['AUTHORISED', 'VOIDED']
```

---

## Methods

### create()

Creates one invoice and returns it as Xero stored it.

```php
public function create(array $invoice, ?string $idempotencyKey = null): array
```

**Parameters**

| Name | Type | Notes |
|---|---|---|
| `$invoice` | `array<string, mixed>` | A Xero invoice payload. `Type` is mandatory. |
| `$idempotencyKey` | `?string` | Defaults to a generated key. Max 128 characters. |

**Request**

```php
use Peoplelogy\XeroBridge\Facades\XeroBridge;

$invoice = XeroBridge::invoices()->create([
    'Type' => 'ACCREC',
    'Contact' => ['ContactID' => '11111111-1111-1111-1111-111111111111'],
    'Date' => '2026-03-02',
    'DueDate' => '2026-04-01',
    'Reference' => 'CCF-2026-0042',
    'Status' => 'DRAFT',
    'LineItems' => [[
        'Description' => 'Leadership training, 2 days',
        'Quantity' => 1,
        'UnitAmount' => 4800,
        'TaxType' => 'OUTPUT',
    ]],
]);
```

Sent as `POST /api.xro/2.0/Invoices` with an `Idempotency-Key` header and this body — note the
`Invoices` wrapper, and `AccountCode` / `CurrencyCode` filled from the connection defaults (this
connection sets `XERO_ACCOUNT_CODE=200`):

```json
{
  "Invoices": [
    {
      "Type": "ACCREC",
      "Contact": { "ContactID": "11111111-1111-1111-1111-111111111111" },
      "Date": "2026-03-02",
      "DueDate": "2026-04-01",
      "Reference": "CCF-2026-0042",
      "Status": "DRAFT",
      "LineItems": [
        {
          "Description": "Leadership training, 2 days",
          "Quantity": 1,
          "UnitAmount": 4800,
          "TaxType": "OUTPUT",
          "AccountCode": "200"
        }
      ],
      "CurrencyCode": "MYR"
    }
  ]
}
```

**Response** — the method returns the first element of `Invoices`, not the envelope:

```json
{
  "Id": "aaaaaaaa-1111-4aaa-8aaa-aaaaaaaaaaaa",
  "Status": "OK",
  "ProviderName": "Your App Name",
  "DateTimeUTC": "/Date(1772439262481)/",
  "Invoices": [
    {
      "Type": "ACCREC",
      "InvoiceID": "22222222-2222-2222-2222-222222222222",
      "InvoiceNumber": "INV-0042",
      "Reference": "CCF-2026-0042",
      "Payments": [],
      "CreditNotes": [],
      "Prepayments": [],
      "Overpayments": [],
      "AmountDue": 5184.00,
      "AmountPaid": 0.00,
      "SentToContact": false,
      "CurrencyRate": 1.000000,
      "HasErrors": false,
      "IsDiscounted": false,
      "Contact": {
        "ContactID": "11111111-1111-1111-1111-111111111111",
        "ContactStatus": "ACTIVE",
        "Name": "Acme Sdn Bhd",
        "ContactPersons": [],
        "HasValidationErrors": false
      },
      "DateString": "2026-03-02T00:00:00",
      "Date": "/Date(1772409600000+0000)/",
      "DueDateString": "2026-04-01T00:00:00",
      "DueDate": "/Date(1775001600000+0000)/",
      "Status": "DRAFT",
      "LineAmountTypes": "Exclusive",
      "LineItems": [
        {
          "Description": "Leadership training, 2 days",
          "UnitAmount": 4800.00,
          "TaxType": "OUTPUT",
          "TaxAmount": 384.00,
          "LineAmount": 4800.00,
          "AccountCode": "200",
          "Tracking": [],
          "Quantity": 1.0000,
          "LineItemID": "33333333-3333-3333-3333-333333333333",
          "ValidationErrors": []
        }
      ],
      "SubTotal": 4800.00,
      "TotalTax": 384.00,
      "Total": 5184.00,
      "UpdatedDateUTC": "/Date(1772439262000+0000)/",
      "UpdatedDateUTCString": "2026-03-02T08:14:22Z",
      "CurrencyCode": "MYR",
      "StatusAttributeString": "OK"
    }
  ]
}
```

So `$invoice['InvoiceID']`, `$invoice['InvoiceNumber']` and `$invoice['LineItems'][0]['LineItemID']` are
what you persist.

**Notes / gotchas**

- Throws `InvalidInvoicePayloadException` when `Type` is missing or empty — nothing is sent.
- Throws `UnsafeContactPayloadException` when the `Contact` block carries anything besides `ContactID`.
- Throws `XeroBridgeException` when `$idempotencyKey` is empty or longer than 128 characters.
- Dispatches `InvoiceCreated` with the connection key, the created invoice and the key used.
- Named with `for($order)` while the write ledger is on, the write is claimed before it is sent; a second
  `create()` for the same record then throws `XeroWriteAlreadyClaimedException` with nothing sent, unless
  Xero rejected the first with a 400. See [Persistence](09-persistence.md#write-dedupe).
- A validation failure from Xero (unknown `AccountCode`, missing contact) is a 400 and surfaces as
  `XeroValidationException`, whose `validationErrors()` holds Xero's own messages.
- `Status` is yours to set. Omit it and Xero creates a `DRAFT`; send `AUTHORISED` to approve on creation.

---

### createMany()

Creates several invoices in one request and returns a `BatchResult`.

```php
public function createMany(array $invoices, ?string $idempotencyKey = null): BatchResult
```

**Parameters**

| Name | Type | Notes |
|---|---|---|
| `$invoices` | `array<array-key, array<string, mixed>>` | Keyed arrays are accepted; the package re-indexes with `array_values()` because Xero needs a JSON array here, not an object. |
| `$idempotencyKey` | `?string` | One key for the whole batch. |

**Request**

```php
$result = XeroBridge::invoices()->createMany([
    [
        'Type' => 'ACCREC',
        'Contact' => ['ContactID' => '11111111-1111-1111-1111-111111111111'],
        'LineItems' => [['Description' => 'Training, cohort A', 'Quantity' => 1, 'UnitAmount' => 4800]],
    ],
    [
        'Type' => 'ACCREC',
        'Contact' => ['ContactID' => '44444444-4444-4444-4444-444444444444'],
        'LineItems' => [['Description' => 'Training, cohort B', 'Quantity' => 1, 'UnitAmount' => 3600]],
    ],
]);

if ($result->hasFailures()) {
    report(new RuntimeException(implode('; ', $result->errorMessages())));
}

foreach ($result->successful() as $invoice) {
    // persist $invoice['InvoiceID']
}
```

Sent as `POST /api.xro/2.0/Invoices?summarizeErrors=false` with every prepared invoice in one `Invoices`
array.

**Response** — `summarizeErrors=false` means **HTTP 200 even when items failed**. The outcome is per
element, in `StatusAttributeString`:

```json
{
  "Id": "bbbbbbbb-2222-4bbb-8bbb-bbbbbbbbbbbb",
  "Status": "OK",
  "ProviderName": "Your App Name",
  "DateTimeUTC": "/Date(1772442311000)/",
  "Invoices": [
    {
      "InvoiceID": "22222222-2222-2222-2222-222222222222",
      "InvoiceNumber": "INV-0043",
      "Status": "DRAFT",
      "Total": 5184.00,
      "StatusAttributeString": "OK",
      "ValidationErrors": []
    },
    {
      "Type": "ACCREC",
      "HasErrors": true,
      "StatusAttributeString": "ERROR",
      "ValidationErrors": [
        { "Message": "The Contact must contain at least 1 of the following elements to identify the contact: Name, ContactID, ContactNumber" }
      ]
    }
  ]
}
```

**Notes / gotchas**

- **Never judge a bulk call by its status code.** That is precisely why this method returns a
  `BatchResult` instead of an array.
- `InvoiceCreated` is dispatched only for elements Xero accepted — `OK` and `WARNING`, never `ERROR`.
- Xero reports per-element failures under `Message` in some responses and `Description` in others;
  `errorMessages()` reads both.
- All-or-nothing is not available through this method. If you need the batch to fail as a unit, call
  `$result->throwIfAnyFailed()` and compensate yourself — the successful elements are already in Xero.
- Every element is checked — `Type`, the `Contact` block — before anything is sent, so one bad element
  refuses the whole batch. A chained `withContactMutation()` covers every element.
- **Not protected by the write ledger.** Only `create()` takes a claim. `for($order)->createMany(...)` is
  still sent, but without a claim — a retry can create every invoice in it again — and it logs a warning
  saying so, whether or not the ledger is switched on: "xero-bridge: createMany() was called on a
  resource named with for(), but only create() is protected by the write ledger…". When each invoice
  needs that protection, call `create()` once per invoice. See
  [Persistence](09-persistence.md#write-dedupe).

---

### find()

Fetches one invoice by `InvoiceID` or by the human `InvoiceNumber`.

```php
public function find(string $idOrNumber): ?array
```

**Request**

```php
$invoice = XeroBridge::invoices()->find('22222222-2222-2222-2222-222222222222');
$invoice = XeroBridge::invoices()->find('INV-01514');    // equally valid
```

Sent as `GET /api.xro/2.0/Invoices/INV-01514`.

**Response** — the same single-invoice envelope as `create()`. Unlike a list call, a `find()` response
includes the full `LineItems` array and any `Payments`:

```json
{
  "Invoices": [
    {
      "Type": "ACCREC",
      "InvoiceID": "22222222-2222-2222-2222-222222222222",
      "InvoiceNumber": "INV-01514",
      "Status": "AUTHORISED",
      "AmountDue": 5184.00,
      "AmountPaid": 0.00,
      "Payments": [],
      "LineItems": [
        {
          "LineItemID": "33333333-3333-3333-3333-333333333333",
          "Description": "Leadership training, 2 days",
          "Quantity": 1.0000,
          "UnitAmount": 4800.00,
          "TaxType": "OUTPUT",
          "TaxAmount": 384.00,
          "LineAmount": 4800.00,
          "AccountCode": "200"
        }
      ],
      "UpdatedDateUTC": "/Date(1772678469000+0000)/",
      "UpdatedDateUTCString": "2026-03-05T02:41:09Z",
      "CurrencyCode": "MYR"
    }
  ]
}
```

**Notes / gotchas**

- Returns `null` when Xero answers with an empty `Invoices` collection.
- An identifier containing `/`, or an empty string, throws `XeroBridgeException` before any request —
  `%2F` in a path segment is rejected by many edge proxies long before it reaches Xero. To look up several
  numbers, use `InvoiceFilter::make()->invoiceNumbers([...])` with `list()`.
- A genuinely unknown identifier is answered by Xero with a 404, which surfaces as `XeroRequestException`,
  not as `null`. Handle both if the identifier comes from user input.

---

### list()

One page of invoices.

```php
public function list(?InvoiceFilter $filter = null): array
```

**Request**

```php
use Peoplelogy\XeroBridge\Filters\InvoiceFilter;

$page = XeroBridge::invoices()->list(
    InvoiceFilter::make()->statuses(['AUTHORISED', 'PAID'])->pageSize(100)
);
```

Passing no filter sends an unfiltered `GET /api.xro/2.0/Invoices`.

**Response** — the method returns the `Invoices` array only. Note that a *list* response carries empty
`LineItems`; Xero only populates them for a single-invoice fetch:

```json
{
  "Id": "cccccccc-3333-4ccc-8ccc-cccccccccccc",
  "Status": "OK",
  "ProviderName": "Your App Name",
  "DateTimeUTC": "/Date(1772442311000)/",
  "pagination": { "page": 1, "pageSize": 100, "pageCount": 3, "itemCount": 250 },
  "Invoices": [
    {
      "Type": "ACCREC",
      "InvoiceID": "22222222-2222-2222-2222-222222222222",
      "InvoiceNumber": "INV-01514",
      "Reference": "CCF-2026-0042",
      "Status": "AUTHORISED",
      "AmountDue": 5184.00,
      "AmountPaid": 0.00,
      "LineAmountTypes": "Exclusive",
      "LineItems": [],
      "SubTotal": 4800.00,
      "TotalTax": 384.00,
      "Total": 5184.00,
      "DateString": "2026-03-02T00:00:00",
      "Date": "/Date(1772409600000+0000)/",
      "UpdatedDateUTC": "/Date(1772439262000+0000)/",
      "CurrencyCode": "MYR"
    }
  ]
}
```

**Notes / gotchas**

- The `pagination` object is **not** returned to you. If you need it, use `all()`, which consumes it, or
  call `XeroBridge::request('GET', 'Invoices')` for the raw envelope — `request()`'s third argument is a
  request body, not a query, so any query string has to be appended to the URI yourself.
- `modifiedSince()` on the filter travels as the `If-Modified-Since` header, which `list()` passes
  through; everything else is query string.
- Xero caps a page at 1000 rows and silently clamps anything larger, which is why `pageSize()` rejects
  out-of-range values locally.

---

### all()

Every matching invoice, fetched lazily page by page.

```php
public function all(?InvoiceFilter $filter = null, int $maxPages = 1000): LazyCollection
```

**Parameters**

| Name | Type | Notes |
|---|---|---|
| `$filter` | `?InvoiceFilter` | The starting page and page size are taken from it. |
| `$maxPages` | `int` | A runaway guard. Default 1000. |

**Request**

```php
use Illuminate\Support\LazyCollection;
use Peoplelogy\XeroBridge\Filters\InvoiceFilter;

$filter = InvoiceFilter::make()
    ->statuses(['AUTHORISED', 'PAID'])
    ->dateBetween('2026-01-01', '2026-03-31');

XeroBridge::invoices()->all($filter)->each(function (array $invoice) {
    // one invoice at a time; pages are fetched as you iterate
});

// Laziness is real: this sends ONE request, not 99.
$firstFive = XeroBridge::invoices()->all($filter)->take(5)->all();
```

**Response** — each yielded item is one element of `Invoices`; the walk is driven by the envelope's
`pagination` object:

```json
{
  "pagination": { "page": 1, "pageSize": 100, "pageCount": 3, "itemCount": 250 },
  "Invoices": [ { "InvoiceID": "2222...", "InvoiceNumber": "INV-01514", "Status": "AUTHORISED" } ]
}
```

**Notes / gotchas**

- Nothing is sent until you iterate. Building the `LazyCollection` makes no request.
- Paging starts at `$filter->currentPage() ?? 1` with `$filter->currentPageSize() ?? 100`, so
  `InvoiceFilter::make()->page(2)` resumes from page 2.
- It stops at `pagination.pageCount`, which is why an exact multiple of the page size costs no wasted
  request. Responses with no `pagination` object fall back to "stop on a short page".
- An empty page stops the walk immediately.
- Reaching `$maxPages` throws `XeroBridgeException` ("Stopped paging Xero invoices after N pages")
  rather than looping forever against a moving data set. The count is checked before the stop condition,
  so `maxPages: 1` throws even when the first page was the last one — it is a runaway guard, not a way to
  cap how much you fetch.
- Each page is a separate API call against your Xero rate limit — 60 calls per minute per organisation,
  plus a daily cap that depends on the plan. Prefer `modifiedSince()` for incremental syncs over
  re-walking everything.

---

### update()

Updates an existing invoice.

```php
public function update(string $idOrNumber, array $invoice): array
```

**Parameters**

| Name | Type | Notes |
|---|---|---|
| `$idOrNumber` | `string` | `InvoiceID` or `InvoiceNumber`. No slashes. |
| `$invoice` | `array<string, mixed>` | Only the fields you are changing. |

**Request**

```php
$updated = XeroBridge::invoices()->update('22222222-2222-2222-2222-222222222222', [
    'Reference' => 'CCF-2026-0042 (revised)',
    'DueDate' => '2026-04-15',
]);
```

Sent as `POST /api.xro/2.0/Invoices/22222222-2222-2222-2222-222222222222`:

```json
{ "Invoices": [ { "Reference": "CCF-2026-0042 (revised)", "DueDate": "2026-04-15" } ] }
```

**Response** — the updated invoice, same shape as `find()`, with a new `UpdatedDateUTC`.

**Notes / gotchas**

- **Connection defaults are not applied on update.** Nothing is filled in for you here.
- The `LineItems` guard applies: every line needs a `LineItemID`, or chain `replacingLineItems()` in
  front of the call — `invoices()->replacingLineItems()->update(...)`. See
  [The two safety guards](#the-two-safety-guards).
- The contact guard applies: `Contact` may contain `ContactID` and nothing else, unless
  `withContactMutation()` is chained in front.
- There is no local status preflight on `update()`. Xero itself rejects edits to a `PAID`, `VOIDED` or
  `DELETED` invoice, and that arrives as `XeroValidationException`.
- Changing `Status` through `update()` works but bypasses the transition table — prefer `authorise()`,
  `void()` and `delete()`, which check it first.

---

### authorise()

Approves an invoice by moving it to `AUTHORISED`.

```php
public function authorise(string $idOrNumber): array
```

**Request**

```php
$invoice = XeroBridge::invoices()->authorise('22222222-2222-2222-2222-222222222222');
```

Two requests: a `GET` to read the current status, then
`POST /api.xro/2.0/Invoices/22222222-2222-2222-2222-222222222222` with:

```json
{ "Invoices": [ { "Status": "AUTHORISED" } ] }
```

**Response**

```json
{
  "Invoices": [
    {
      "InvoiceID": "22222222-2222-2222-2222-222222222222",
      "InvoiceNumber": "INV-01514",
      "Status": "AUTHORISED",
      "AmountDue": 5184.00,
      "Total": 5184.00,
      "UpdatedDateUTC": "/Date(1772442311000+0000)/"
    }
  ]
}
```

**Notes / gotchas**

- Unlike `void()` and `delete()`, this method has **no `$preflight` parameter** — it always reads the
  invoice first. Budget two API calls.
- Throws `InvalidInvoiceTransitionException` from `PAID`, `VOIDED` or `DELETED`, with a message naming
  the current status and what is legal from it.
- `AUTHORISED -> AUTHORISED` is permitted, so re-approving is harmless.
- Approving is what makes an invoice emailable with `email()` and payable with
  `XeroBridge::payments()->createForInvoice(...)`.

---

### void()

Voids an approved invoice.

```php
public function void(string $idOrNumber, bool $preflight = true): array
```

**Parameters**

| Name | Type | Notes |
|---|---|---|
| `$idOrNumber` | `string` | `InvoiceID` or `InvoiceNumber`. |
| `$preflight` | `bool` | Default `true`: read the invoice first and refuse an illegal void locally. |

**Request**

```php
XeroBridge::invoices()->void('22222222-2222-2222-2222-222222222222');

// One request instead of two, when you already know the status is safe:
XeroBridge::invoices()->void('22222222-2222-2222-2222-222222222222', preflight: false);
```

The write is `POST /api.xro/2.0/Invoices/{id}` with:

```json
{ "Invoices": [ { "Status": "VOIDED" } ] }
```

**Response**

```json
{
  "Invoices": [
    {
      "InvoiceID": "22222222-2222-2222-2222-222222222222",
      "InvoiceNumber": "INV-01514",
      "Status": "VOIDED",
      "AmountDue": 0.00,
      "AmountPaid": 0.00,
      "UpdatedDateUTC": "/Date(1772678469000+0000)/"
    }
  ]
}
```

**Notes / gotchas**

- `VOIDED` is legal only from `AUTHORISED`, and only while **no payment is applied**. The preflight
  checks both: it throws `InvalidInvoiceTransitionException` for a wrong status and
  `InvoiceCannotBeVoidedException` — naming the blocking `PaymentID` — when payments exist, because
  Xero's own error for that case is opaque.
- To void a paid invoice, remove the payment first: `XeroBridge::payments()->delete($paymentId)`.
- With `preflight: false` an illegal void is Xero's problem and comes back as `XeroValidationException`.
- Voiding does not delete anything. The invoice stays visible in Xero with a `VOIDED` status, which is
  the audit-safe outcome and the reason there is no HTTP DELETE.

---

### delete()

Deletes a `DRAFT` or `SUBMITTED` invoice.

```php
public function delete(string $idOrNumber, bool $preflight = true): array
```

**Parameters**

| Name | Type | Notes |
|---|---|---|
| `$idOrNumber` | `string` | `InvoiceID` or `InvoiceNumber`. |
| `$preflight` | `bool` | Default `true`: read the current status and refuse an illegal delete locally. |

**Request**

```php
XeroBridge::invoices()->delete('22222222-2222-2222-2222-222222222222');
```

`POST /api.xro/2.0/Invoices/{id}`:

```json
{ "Invoices": [ { "Status": "DELETED" } ] }
```

**Response**

```json
{
  "Invoices": [
    {
      "InvoiceID": "22222222-2222-2222-2222-222222222222",
      "InvoiceNumber": "INV-01514",
      "Status": "DELETED",
      "Total": 5184.00,
      "UpdatedDateUTC": "/Date(1772678469000+0000)/"
    }
  ]
}
```

**Notes / gotchas**

- **There is no HTTP DELETE verb here.** This is a status change, exactly like `void()`.
- Legal from `DRAFT` *and* `SUBMITTED` — not drafts alone.
- From `AUTHORISED` it throws `InvalidInvoiceTransitionException`; you want `void()` there.
- `preflight: false` skips the read and posts straight away.

---

### email()

Asks Xero to email the invoice to the contact, using the organisation's own template.

```php
public function email(string $idOrNumber): void
```

**Request**

```php
XeroBridge::invoices()->email('22222222-2222-2222-2222-222222222222');
```

Sent as `POST /api.xro/2.0/Invoices/22222222-2222-2222-2222-222222222222/Email` with an empty body.

**Response** — `204 No Content`, with no body at all. The method returns `void`; anything other than a
2xx throws.

**Notes / gotchas**

- Xero requires the invoice to be `Type: ACCREC` and `SUBMITTED`, `AUTHORISED` or `PAID`. Emailing a
  `DRAFT` is rejected by Xero with a validation error, so call `authorise()` first.
- **There is no `cc`, `bcc`, subject or body parameter** — Xero's request body for this endpoint is
  literally `{}`. Copies are configured on the *contact*, by adding `ContactPersons` with
  `IncludeInEmails => true`. See [Contacts, payments and settings](03-contacts-payments-settings.md).
- The email goes to the contact's `EmailAddress`. A contact without one fails at Xero.
- The send is asynchronous from your point of view: a 204 means Xero accepted the instruction, not that
  the message was delivered.

---

### pdf()

The rendered invoice PDF as raw bytes.

```php
public function pdf(string $idOrNumber): string
```

**Request**

```php
$bytes = XeroBridge::invoices()->pdf('22222222-2222-2222-2222-222222222222');

Storage::disk('local')->put("invoices/INV-01514.pdf", $bytes);

// or stream it straight to the browser
return response($bytes, 200, [
    'Content-Type' => 'application/pdf',
    'Content-Disposition' => 'attachment; filename="INV-01514.pdf"',
]);
```

Sent as `GET /api.xro/2.0/Invoices/{id}` with `Accept: application/pdf`.

**Response** — a binary body beginning `%PDF-`, not JSON.

**Notes / gotchas**

- The `Accept` header **replaces** the client's default rather than merging with it. A merged
  `application/json, application/pdf` makes Xero answer with JSON, and nothing fails until someone opens
  the file — so the package refuses a response whose `Content-Type` is not `application/pdf` and throws
  `XeroBridgeException`.
- The PDF uses the invoice's branding theme. Set `BrandingThemeID` at creation (or through the
  `branding_theme_id` connection default) if you need a specific one.
- Do not log or dump the return value; it is binary.

---

### onlineUrl()

The public "view online" link for an invoice.

```php
public function onlineUrl(string $idOrNumber): string
```

**Request**

```php
$url = XeroBridge::invoices()->onlineUrl('22222222-2222-2222-2222-222222222222');
```

Sent as `GET /api.xro/2.0/Invoices/22222222-2222-2222-2222-222222222222/OnlineInvoice`.

**Response**

```json
{
  "Id": "dddddddd-4444-4ddd-8ddd-dddddddddddd",
  "Status": "OK",
  "ProviderName": "Your App Name",
  "DateTimeUTC": "/Date(1772442311000)/",
  "OnlineInvoices": [
    { "OnlineInvoiceUrl": "https://in.xero.com/PLACEHOLDERinvoicelinktoken" }
  ]
}
```

The method returns the `OnlineInvoiceUrl` string.

**Notes / gotchas**

- Available only for approved `ACCREC` invoices. When Xero returns no URL the package throws
  `XeroBridgeException` rather than handing back an empty string.
- The URL is a public, unauthenticated link — anyone holding it can view the invoice and, if online
  payments are configured, pay it. Treat it as a capability, not as an identifier.

---

### markAsSent()

Flags an invoice as sent in Xero without Xero emailing anything.

```php
public function markAsSent(string $idOrNumber): array
```

**Request**

```php
// You emailed the PDF yourself; tell Xero so its "sent" column is honest.
XeroBridge::invoices()->markAsSent('22222222-2222-2222-2222-222222222222');
```

`POST /api.xro/2.0/Invoices/22222222-2222-2222-2222-222222222222`:

```json
{ "Invoices": [ { "SentToContact": true } ] }
```

**Response**

```json
{
  "Invoices": [
    {
      "InvoiceID": "22222222-2222-2222-2222-222222222222",
      "InvoiceNumber": "INV-01514",
      "Status": "AUTHORISED",
      "SentToContact": true,
      "UpdatedDateUTC": "/Date(1772678469000+0000)/"
    }
  ]
}
```

**Notes / gotchas**

- This is a display flag only. Nothing is delivered to anyone — pair it with your own delivery, or use
  `email()` instead, which sets the flag itself.
- Xero honours `SentToContact` on an approved invoice. There is no local status preflight here, so a
  `DRAFT` is answered by Xero.

---

### withContactMutation()

Returns a copy of the resource whose payloads may carry contact fields beside `ContactID`.

```php
public function withContactMutation(): static
```

**Request**

```php
XeroBridge::invoices()->withContactMutation()->create([
    'Type' => 'ACCREC',
    'Contact' => [
        'ContactID' => '11111111-1111-1111-1111-111111111111',
        'Name' => 'Acme Sdn Bhd (renamed)',
    ],
    'LineItems' => [['Description' => 'Training', 'Quantity' => 1, 'UnitAmount' => 4800]],
]);
```

**Notes / gotchas**

- Returns a **copy**, never `$this`. The opt-in covers every call made through that copy — `create()`,
  every invoice in a `createMany()`, `update()` — and nothing else; the shared `XeroBridge::invoices()`
  instance is never changed.
- Chain it. Called on a line of its own it does nothing: the next payload carrying contact fields still
  throws `UnsafeContactPayloadException`, with nothing sent.
- It works on either side of `for()`.
- A copy kept in a variable stays opted in for every call made through it.
- Use it only when you genuinely intend Xero to rewrite the contact record. Any `ContactPersons` you do
  not include in the request **are deleted**, irreversibly.
- The safe alternative is almost always to update the contact explicitly first with
  `XeroBridge::contacts()->update(...)`, then send the invoice with `ContactID` alone.

---

### replacingLineItems()

Returns a copy of the resource whose `update()` accepts `LineItems` without a `LineItemID` on every line.

```php
public function replacingLineItems(): static
```

**Request**

```php
XeroBridge::invoices()->replacingLineItems()->update('22222222-2222-2222-2222-222222222222', [
    'LineItems' => [
        ['Description' => 'Leadership training, 3 days', 'Quantity' => 1, 'UnitAmount' => 7200, 'AccountCode' => '200'],
    ],
]);
```

**Notes / gotchas**

- Every existing line is deleted and recreated with new `LineItemID` values, so any of your own records
  pointing at an old `LineItemID` are now stale.
- Returns a **copy**, never `$this`. The opt-in covers every `update()` made through that copy and
  nothing else; the shared `XeroBridge::invoices()` instance is never changed, whether the update
  succeeds or fails.
- Chain it. Called on a line of its own it does nothing: the next `update()` with a line lacking a
  `LineItemID` still throws `InvalidInvoicePayloadException`, with nothing sent.
- A copy kept in a variable stays opted in for every `update()` made through it. See
  [The two safety guards](#the-two-safety-guards).

---

## BatchResult

`Peoplelogy\XeroBridge\Support\BatchResult` is what `createMany()` returns. It exists because
`summarizeErrors=false` makes Xero answer `200 OK` with a mixture of accepted and rejected elements.

| Method | Returns | Notes |
|---|---|---|
| `all()` | `list<array>` | Every element Xero returned, in order. |
| `successful()` | `list<array>` | `StatusAttributeString` of `OK`. An element with no such key counts as `OK`. |
| `warned()` | `list<array>` | `WARNING` — accepted, but with a caveat. Do not discard these. |
| `failed()` | `list<array>` | `ERROR` — rejected. |
| `hasFailures()` | `bool` | |
| `errorMessages()` | `list<string>` | Unique messages across failed elements, reading both `Message` and `Description`. |
| `warningMessages()` | `list<string>` | Unique `Warnings[].Message` across warned elements. |
| `submittedAt(int $index)` | `?array` | What *you* sent at that index, for "row 3 failed because…". |
| `throwIfAnyFailed()` | `self` | Throws `XeroValidationException` when anything failed; returns `$this` otherwise, so it chains. |

```php
$result = XeroBridge::invoices()->createMany($payloads);

// Iterate all(), not failed(): failed() re-indexes, so its keys are positions
// among the failures, not positions in the batch.
foreach ($result->all() as $i => $element) {
    if (strtoupper((string) ($element['StatusAttributeString'] ?? 'OK')) !== 'ERROR') {
        continue;
    }

    logger()->error('Xero rejected an invoice', [
        'submitted' => $result->submittedAt($i),
        'errors' => array_column($element['ValidationErrors'] ?? [], 'Message'),
    ]);
}

// Or fail loudly:
$result->throwIfAnyFailed();
```

> ⚠️ `submittedAt()` is index-aligned with what you passed in, which is only meaningful because Xero
> returns elements in submission order. It is not a match on content — and `successful()`, `warned()` and
> `failed()` all re-index their results, so only `all()` preserves that alignment.

---

## Idempotency keys

`Peoplelogy\XeroBridge\Support\IdempotencyKey`:

| Member | Signature | Notes |
|---|---|---|
| `MAX_LENGTH` | `int` = 128 | Xero's hard limit. |
| `RETENTION_SECONDS` | `int` = 360 | How long Xero remembers a key: **six minutes**. |
| `generate()` | `static generate(string $prefix = 'xb'): string` | A hyphen-stripped UUIDv4 with a prefix — 35 characters. |
| `xeroStyle()` | `static xeroStyle(): string` | Xero's own "four GUIDs" recommendation, hyphen-stripped to land at exactly 128. With hyphens it would be 144 and violate Xero's own limit. |
| `for()` | `static for(string ...$parts): string` | A deterministic key from values you already have. |
| `assertValid()` | `static assertValid(string $key): void` | Throws `XeroBridgeException` when empty or over 128 characters. |

```php
use Peoplelogy\XeroBridge\Support\IdempotencyKey;

// Same logical operation, same key -- so an in-process retry cannot duplicate.
$key = IdempotencyKey::for('invoice', (string) $order->id, (string) $order->updated_at);

XeroBridge::invoices()->create($payload, $key);
```

> ⚠️ **Six minutes is the whole story.** An idempotency key protects an immediate transient-network
> retry. It does **not** deduplicate a queued job that retries on any realistic backoff, because by then
> Xero has forgotten the key and will happily create a second invoice. The real defence is to persist the
> returned `InvoiceID` against your own record and make the job a no-op once it is set — which is what
> the `InvoiceCreated` event is for.

The package can keep that record for you. With the write ledger on (`XERO_WRITES_LEDGER=true` and its
table migrated), `XeroBridge::invoices()->for($order)->create([...])` claims the write before it is sent,
and a later attempt for the same record — a job retried an hour on — throws
`XeroWriteAlreadyClaimedException` instead of sending a second invoice. Only a payload Xero rejected with
a 400 frees the claim, for a corrected retry. `createMany()` takes no claim. See
[Persistence](09-persistence.md#write-dedupe).

Writes that you do not pass a key to still get one: the HTTP client generates an `Idempotency-Key` for
every request that is not a `GET`, `HEAD` or `OPTIONS` when `xero-bridge.http.idempotency` is `true` (the
default). Carrying a key is also what makes a write retryable at all — a write without one is never
retried, because a retried POST that actually succeeded creates a duplicate invoice. `create()` and
`createMany()` set the header themselves, so they stay retryable whatever that setting says; `update()`,
`authorise()`, `void()`, `delete()`, `email()` and `markAsSent()` depend on it.

---

## InvoiceFilter

`Peoplelogy\XeroBridge\Filters\InvoiceFilter` is a fluent builder for `GET /Invoices`. Builder methods
mutate the filter in place and return `$this` for chaining; only `forPage()` returns a clone, leaving the
original untouched.

```php
use Peoplelogy\XeroBridge\Filters\InvoiceFilter;

$filter = InvoiceFilter::make();
```

**Constants:** `InvoiceFilter::TYPE_ACCREC`, `InvoiceFilter::TYPE_ACCPAY`,
`InvoiceFilter::MAX_PAGE_SIZE` (1000).

### Builder methods

| Method | Signature | Produces | Notes |
|---|---|---|---|
| `make()` | `static make(): self` | — | The only constructor. |
| `statuses()` | `statuses(string\|array $statuses): self` | `Statuses=` | Upper-cased and de-duplicated. Replaces any previous call. |
| `contactIds()` | `contactIds(string\|array $ids): self` | `ContactIDs=` | Comma-joined. |
| `invoiceNumbers()` | `invoiceNumbers(string\|array $numbers): self` | `InvoiceNumbers=` | The way to look up several `INV-…` numbers at once. |
| `ids()` | `ids(string\|array $ids): self` | `IDs=` | Prefer this over a `where` with `or`: Xero optimises `or` only for `InvoiceId`, so an IDs list is dramatically faster. |
| `type()` | `type(string $type): self` | `where Type=="…"` | Upper-cased. Throws `InvalidArgumentException` for anything but `ACCREC`/`ACCPAY`. Always appended **last** in the `where`. |
| `reference()` | `reference(string $reference): self` | `where Reference=="…"` | Shorthand for `whereEquals('Reference', …)`. |
| `contactName()` | `contactName(string $name): self` | `where Contact.Name=="…"` | Shorthand for `whereEquals('Contact.Name', …)`. |
| `dateBetween()` | `dateBetween(DateTimeInterface\|string $from, DateTimeInterface\|string $to, string $field = 'Date'): self` | two `where` clauses using `DateTime(y, m, d)` | Throws `InvalidArgumentException` for `UpdatedDateUTC` — use `modifiedSince()`, which Xero recommends and which is far faster. |
| `whereRaw()` | `whereRaw(string $clause): self` | `where` | Escape hatch. Nothing is validated or escaped. |
| `whereEquals()` | `whereEquals(string $field, string $value): self` | `where Field=="value"` | Throws `InvalidArgumentException` if the value contains `"` — Xero has no escape for it. |
| `whereGuid()` | `whereGuid(string $field, string $guid): self` | `where Field=guid("…")` | The form Xero requires for GUID comparisons. |
| `modifiedSince()` | `modifiedSince(DateTimeInterface\|string $since): self` | the `If-Modified-Since` **header** | UTC, to the second, no trailing `Z`. |
| `orderBy()` | `orderBy(string $field, string $direction = 'ASC'): self` | `order=` | `ASC` emits the bare field; `DESC` emits `Field DESC`. Throws `InvalidArgumentException` for any other direction. Optimised fields are `InvoiceId`, `UpdatedDateUTC` and `Date`. |
| `page()` | `page(int $page, ?int $pageSize = null): self` | `page=` (and `pageSize=`) | Throws `InvalidArgumentException` below 1. |
| `pageSize()` | `pageSize(int $pageSize): self` | `pageSize=` | Throws `InvalidArgumentException` outside 1–1000, because Xero clamps silently and a typo'd `5000` otherwise looks like it worked. |
| `summaryOnly()` | `summaryOnly(bool $summaryOnly = true): self` | `summaryOnly=true` | Excludes exactly `Payments`, `HasAttachments`, `LineItems` and `CISDeduction`, and forces pagination on. Much faster for list screens. |
| `createdByMyApp()` | `createdByMyApp(bool $only = true): self` | `createdByMyApp=true` | Only invoices your app created. |
| `search()` | `search(string $term): self` | `SearchTerm=` | Case-insensitive substring over `InvoiceNumber` and `Reference`. |
| `unitdp()` | `unitdp(int $places = 4): self` | `unitdp=` | Ask for 4 decimal places on unit amounts instead of 2. |
| `currentPage()` | `currentPage(): ?int` | — | Reader, used by `all()`. |
| `currentPageSize()` | `currentPageSize(): ?int` | — | Reader, used by `all()`. |
| `forPage()` | `forPage(int $page, ?int $pageSize = null): self` | — | Returns a **clone** positioned on that page; the original is untouched. |
| `toQuery()` | `toQuery(): array<string, string>` | — | Raw, unencoded values. |
| `toHeaders()` | `toHeaders(): array<string, string>` | — | `['If-Modified-Since' => …]` or `[]`. |

### Three encoding rules the builder enforces for you

1. **`toQuery()` returns raw, unencoded values.** The HTTP client encodes exactly once. Pre-encoding
   double-encodes — `==` becoming `%253D%253D` — which puts a literal `%` into Xero's filter parser.
2. **Clauses join with the ` AND ` keyword, never `&&`.** A literal `&` is a query-string separator, so
   `&&` truncates the filter and Xero returns a **superset** of the intended rows. That reads as "the
   filter did nothing", not as an error.
3. **Booleans render as the strings `"true"`/`"false"`.** PHP's own cast gives `"1"`, which Xero ignores
   silently.

And one that is not about encoding: `modifiedSince()` is a **header**. Sent as a query parameter, Xero
ignores it and returns the whole unfiltered set.

### Worked example 1 — a quarter of approved and paid sales

```php
$filter = InvoiceFilter::make()
    ->statuses(['AUTHORISED', 'PAID'])
    ->type('ACCREC')
    ->dateBetween('2026-01-01', '2026-03-31')
    ->orderBy('Date', 'DESC')
    ->page(2, 50);

$page = XeroBridge::invoices()->list($filter);
```

`toQuery()`:

```php
[
    'Statuses' => 'AUTHORISED,PAID',
    'where' => 'Date>=DateTime(2026, 1, 1) AND Date<=DateTime(2026, 3, 31) AND Type=="ACCREC"',
    'order' => 'Date DESC',
    'page' => '2',
    'pageSize' => '50',
]
```

On the wire:

```
GET /api.xro/2.0/Invoices?Statuses=AUTHORISED%2CPAID&where=Date%3E%3DDateTime%282026%2C%201%2C%201%29%20AND%20Date%3C%3DDateTime%282026%2C%203%2C%2031%29%20AND%20Type%3D%3D%22ACCREC%22&order=Date%20DESC&page=2&pageSize=50
```

### Worked example 2 — one customer, one reference, summary rows only

```php
$filter = InvoiceFilter::make()
    ->contactIds('00000000-0000-0000-0000-000000000001')
    ->reference('CCF-2026-0042')
    ->summaryOnly();
```

`toQuery()`:

```php
[
    'ContactIDs' => '00000000-0000-0000-0000-000000000001',
    'where' => 'Reference=="CCF-2026-0042"',
    'summaryOnly' => 'true',
]
```

On the wire:

```
GET /api.xro/2.0/Invoices?ContactIDs=00000000-0000-0000-0000-000000000001&where=Reference%3D%3D%22CCF-2026-0042%22&summaryOnly=true
```

### Worked example 3 — an incremental sync

```php
$filter = InvoiceFilter::make()
    ->modifiedSince($lastSyncedAt)          // e.g. CarbonImmutable::parse('2026-09-01 08:30', 'Asia/Kuala_Lumpur')
    ->statuses('AUTHORISED')
    ->createdByMyApp()
    ->pageSize(250)
    ->orderBy('UpdatedDateUTC');

XeroBridge::invoices()->all($filter)->each(fn (array $invoice) => /* upsert */ null);
```

`toQuery()` — note there is no date clause at all:

```php
[
    'Statuses' => 'AUTHORISED',
    'order' => 'UpdatedDateUTC',
    'pageSize' => '250',
    'createdByMyApp' => 'true',
]
```

`toHeaders()`:

```php
['If-Modified-Since' => '2026-09-01T00:30:00']
```

On the wire — the local Malaysian time has become UTC, to the second, with no trailing `Z`:

```
GET /api.xro/2.0/Invoices?Statuses=AUTHORISED&order=UpdatedDateUTC&pageSize=250&createdByMyApp=true
If-Modified-Since: 2026-09-01T00:30:00
```

### Worked example 4 — a specific set of invoice IDs at four decimal places

```php
$filter = InvoiceFilter::make()
    ->ids([
        '11111111-1111-1111-1111-111111111111',
        '22222222-2222-2222-2222-222222222222',
    ])
    ->unitdp(4);
```

```
GET /api.xro/2.0/Invoices?IDs=11111111-1111-1111-1111-111111111111%2C22222222-2222-2222-2222-222222222222&unitdp=4
```

### Worked example 5 — a GUID comparison plus a raw clause

```php
$filter = InvoiceFilter::make()
    ->whereGuid('Contact.ContactID', '00000000-0000-0000-0000-000000000001')
    ->whereRaw('AmountDue>0');
```

`toQuery()`:

```php
['where' => 'Contact.ContactID=guid("00000000-0000-0000-0000-000000000001") AND AmountDue>0']
```

```
GET /api.xro/2.0/Invoices?where=Contact.ContactID%3Dguid%28%2200000000-0000-0000-0000-000000000001%22%29%20AND%20AmountDue%3E0
```

For this particular case prefer `contactIds()`, which uses Xero's optimised parameter rather than a
`where` clause.

---

## Exceptions

Everything below extends `Peoplelogy\XeroBridge\Exceptions\XeroBridgeException`.

| Exception | Thrown when |
|---|---|
| `InvalidInvoicePayloadException` | `Type` missing on create, or `update()` given `LineItems` without `LineItemID`s. Nothing is sent. |
| `UnsafeContactPayloadException` | The `Contact` block carries a `ContactID` plus other fields. Nothing is sent. |
| `InvalidInvoiceTransitionException` | A preflight found the status change illegal. The message names the current status and what is legal from it. |
| `InvoiceCannotBeVoidedException` | A void preflight found applied payments. The message names the `PaymentID`s. |
| `XeroWriteAlreadyClaimedException` | The write ledger refused a `for($order)->create()`: that record's invoice already exists (`xeroId()`), or another attempt is in flight or never recorded its outcome (`isPending()`). Nothing is sent — stop, do not retry. See [Persistence](09-persistence.md#write-dedupe). |
| `XeroWriteLedgerUnavailableException` | With the write ledger on and `XERO_WRITES_STRICT=true`, the ledger could not record the claim for a `for($order)->create()` — its table is missing, the database is unreachable, or the insert failed for a reason other than a duplicate. Nothing is sent, so retrying is safe. |
| `XeroBridgeException` | An identifier that is empty or contains `/`; an idempotency key that is empty or over 128 characters; `all()` exceeding `$maxPages`; `pdf()` receiving a non-PDF; `onlineUrl()` receiving no URL. |
| `XeroValidationException` | Xero rejected the request (400). `validationErrors()` holds Xero's messages; `throwIfAnyFailed()` on a `BatchResult` raises the same type. |
| `XeroAuthenticationException` | A 401 that survived one token refresh, or a 401/403 problem envelope. |
| `XeroScopeException` | A 401 whose `WWW-Authenticate` says insufficient scope. Refreshing will never fix it — the organisation must reconnect with the new scopes. |
| `XeroRateLimitException` | A 429. `retryAfter()` gives the seconds to wait; `limitProblem()` says which limit. |
| `XeroServiceUnavailableException` | 500/502/503/504, including "The Organisation is offline". Carries `retryAfter()`. |
| `XeroRequestException` | Any other non-2xx, including a 404 for an unknown invoice. |

Note the `InvalidArgumentException`s thrown by `InvoiceFilter` are plain SPL exceptions, not part of this
hierarchy — they are programming errors, caught in development.

---

## Events

`Peoplelogy\XeroBridge\Events\InvoiceCreated` is dispatched by `create()`, and by `createMany()` once per
element Xero accepted (`OK` and `WARNING`, never `ERROR`).

```php
namespace App\Listeners;

use Peoplelogy\XeroBridge\Events\InvoiceCreated;

class RecordXeroInvoice
{
    public function handle(InvoiceCreated $event): void
    {
        $event->connectionKey;      // string, e.g. 'default'
        $event->invoice;            // array<string, mixed>, the created invoice
        $event->idempotencyKey;     // ?string

        $event->invoiceId();        // ?string
        $event->invoiceNumber();    // ?string
        $event->status();           // ?string
    }
}
```

Persisting `invoiceId()` against your own record in this listener is the duplicate protection that the
six-minute idempotency window cannot give you.

With the write ledger on, a `create()` the ledger refuses sends nothing, so `InvoiceCreated` does not
fire; `Peoplelogy\XeroBridge\Events\XeroWriteBlocked` does, just before the
`XeroWriteAlreadyClaimedException`. See [Persistence](09-persistence.md#write-dedupe).

---

## Testing against a fake

The package goes through Laravel's HTTP client, so `Http::fake()` is all you need. Nothing here reaches
Xero. The whole harness — a complete test class, faking the email endpoint, mocking the facade, and the
traps — is on one page:
[Testing a consuming application](06-recipes.md#6-testing-a-consuming-application). The short version
is that every test needs three things: `Http::preventStrayRequests()`, a connection row with a future
`expires_at`, and fake patterns that include the API path and end in `*`.

```php
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Peoplelogy\XeroBridge\Facades\XeroBridge;
use Peoplelogy\XeroBridge\Models\XeroConnection;

Http::preventStrayRequests();

// The token every call reads. With no row the call throws
// XeroConnectionNotFoundException; with a token expired or expiring within a
// minute it first refreshes at identity.xero.com, which nothing here fakes.
XeroConnection::create([
    'key' => 'default',
    'tenant_id' => 'test-tenant-id',
    'access_token' => 'test-access-token',
    'refresh_token' => 'test-refresh-token',
    'expires_at' => now()->addMinutes(30),
]);

// account_code has no shipped default. Set the one this test asserts on,
// before the first invoices() call reads it.
config(['xero-bridge.connections.default.account_code' => '200']);

Http::fake([
    'api.xero.com/api.xro/2.0/Invoices*' => Http::response([
        'Invoices' => [[
            'InvoiceID' => '22222222-2222-2222-2222-222222222222',
            'InvoiceNumber' => 'INV-0042',
            'Status' => 'DRAFT',
        ]],
    ]),
]);

$invoice = XeroBridge::invoices()->create([
    'Type' => 'ACCREC',
    'Contact' => ['ContactID' => '11111111-1111-1111-1111-111111111111'],
    'LineItems' => [['Description' => 'Training', 'Quantity' => 1, 'UnitAmount' => 4800]],
]);

Http::assertSent(fn (Request $r) => $r->method() === 'POST'
    && $r->data()['Invoices'][0]['LineItems'][0]['AccountCode'] === '200');
```

Paging is faked with a sequence, one entry per page — in a test of its own, with the same connection
row, because the first stub that matches a URL wins:

```php
Http::fake(['api.xero.com/api.xro/2.0/Invoices*' => Http::sequence()
    ->push(['Invoices' => array_fill(0, 100, ['InvoiceID' => 'x']), 'pagination' => ['page' => 1, 'pageSize' => 100, 'pageCount' => 2, 'itemCount' => 150]], 200)
    ->push(['Invoices' => array_fill(0, 50, ['InvoiceID' => 'x']), 'pagination' => ['page' => 2, 'pageSize' => 100, 'pageCount' => 2, 'itemCount' => 150]], 200)]);

expect(XeroBridge::invoices()->all()->count())->toBe(150);
```

The trailing `*` is not decoration. Laravel matches a pattern against the whole URL, query string
included, so `'api.xero.com/api.xro/2.0/Invoices'` without it matches `create()` and an unfiltered
`list()`, but not `find()`, a filtered `list()`, `all()` or `createMany()`, which adds
`?summarizeErrors=false`.

---

Next: [Contacts, payments and settings](03-contacts-payments-settings.md) — including
`settings()->accounts()` and `settings()->taxRates()`, which is how you find the account codes and tax
types this page keeps telling you not to hardcode.
