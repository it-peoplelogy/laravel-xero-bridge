# Using `peoplelogy/laravel-xero-bridge`

A practical reference for the six Xero flows most applications need: how to call each one,
what to pass, what comes back, and what goes wrong.

It covers the package only. Nothing here assumes a particular application, database or domain
model — the package deliberately contains no bookkeeping logic, so *when* to raise an invoice and
*what* goes on it stay yours to decide.

Every flow below can be run by hand, against a real Xero organisation, from the package’s own test
console at **`/xero/console`**. It is there as soon as the package is installed, in every
environment except production, and its actions map one-to-one onto the flows in this document — so
the quickest way to understand a flow is usually to run it there and read the JSON it returns.
Writes are refused unless the connected organisation is a Xero Demo Company or explicitly
allow-listed.

> **Version note.** Written against `peoplelogy/laravel-xero-bridge` v1.3.0. Method signatures are
> read from the package source; request and response samples are reproduced from the package's own
> docs in this same directory, which are the authority wherever the two
> disagree. The GUIDs and figures in them are illustrative.

---

## Contents

1. [The calling mechanism](#the-calling-mechanism)
2. [Flow 1 — first or create a contact](#flow-1--first-or-create-a-contact)
3. [Flow 2.1 — create a DRAFT invoice](#flow-21--create-a-draft-invoice)
4. [Flow 2.2 — create an invoice that ends up PAID](#flow-22--create-an-invoice-that-ends-up-paid)
5. [Flow 3 — TIN, BRN, CC list, then send](#flow-3--tin-brn-cc-list-then-send)
6. [Flow 4 — find one invoice](#flow-4--find-one-invoice)
7. [Flow 5 — find a contact](#flow-5--find-a-contact)
8. [Flow 6 — list invoices](#flow-6--list-invoices)
9. [Error handling](#error-handling)
10. [Things that differ per organisation](#things-that-differ-per-organisation)

---

## The calling mechanism

### The namespace

The package autoloads PSR-4 from a single root:

```
Peoplelogy\XeroBridge\   →   vendor/peoplelogy/laravel-xero-bridge/src/
```

So every class below is `Peoplelogy\XeroBridge\` plus its path — `Filters\InvoiceFilter` is
`Peoplelogy\XeroBridge\Filters\InvoiceFilter`, and so on. The service provider is auto-discovered;
there is nothing to register in `bootstrap/providers.php`.

### Three ways to reach it

**1. Import the facade — the usual choice.**

```php
namespace App\Services\Xero;

use Peoplelogy\XeroBridge\Facades\XeroBridge;

class InvoiceWriter
{
    public function create(): array
    {
        return XeroBridge::invoices()->create([...]);
    }
}
```

**2. The global alias**, registered by the package as `XeroBridge`. No import, but it lives in the
**root** namespace:

```php
\XeroBridge::invoices()->create([...]);   // note the leading backslash
```

> **The mistake everybody makes once.** Inside a namespaced class, writing `XeroBridge::invoices()`
> with no `use` and no leading `\` resolves to `App\Services\Xero\XeroBridge` — a class that does
> not exist — and fatals with *"Class not found"* that names your own namespace, not the package's.
> Either add the `use` statement or write `\XeroBridge`. In Blade and `routes/*.php` (no namespace)
> the bare `XeroBridge::` works, which is what makes this inconsistent enough to trip over.

**3. Inject the manager** — no facade, and trivially mockable in tests:

```php
use Peoplelogy\XeroBridge\XeroBridgeManager;

public function __construct(private readonly XeroBridgeManager $xero) {}

// then
$this->xero->invoices()->create([...]);
```

`XeroBridgeManager` is a container singleton, also aliased as `'xero-bridge'`, so
`app('xero-bridge')` and `app(XeroBridgeManager::class)` are the same object. The facade is a thin
wrapper over exactly this — every method in the tables below works identically through all three.

### What you import, and when

Only the facade is needed for ordinary use. The rest you import when a specific job calls for it:

| Import | When |
|---|---|
| `Facades\XeroBridge` | Always — the entry point |
| `Filters\InvoiceFilter` | Building a `list()` / `all()` query ([flow 6](#flow-6--list-invoices)) |
| `Exceptions\*` | Catching a specific failure ([error handling](#error-handling)) |
| `Support\InvoiceTransitions` | Checking a status change is legal before attempting it |
| `Support\BatchResult` | Type-hinting the return of `createMany()` |
| `Models\XeroConnection` | Reading the stored connection row directly |
| `Events\*` | Writing a listener |
| `Contracts\ConnectionRepository` | Replacing how connections are stored |

The **resources** — `Resources\Invoices`, `Contacts`, `Payments`, `Settings` — are never imported.
You get them from the manager (`XeroBridge::invoices()`), and only type-hint them if you are
passing one around.

Everything under `Client\`, `OAuth\`, `Http\`, `Jobs\`, `Repositories\` and `Webhooks\` is
internal plumbing. It is reachable, but if you find yourself importing from there, check the
facade cannot already do it.

### The exceptions, in full

All under `Peoplelogy\XeroBridge\Exceptions\`, all extending `XeroBridgeException`:

`XeroBridgeException` · `XeroAuthenticationException` · `XeroConfigurationException` ·
`XeroConnectionNotFoundException` · `XeroReauthorizationRequiredException` · `XeroScopeException` ·
`XeroValidationException` · `XeroRequestException` · `XeroRateLimitException` ·
`XeroServiceUnavailableException` · `XeroIdentityUnavailableException` ·
`InvalidInvoicePayloadException` · `InvalidInvoiceTransitionException` ·
`InvoiceCannotBeVoidedException` · `UnsafeContactPayloadException` ·
`ConnectionKeyConflictException` · `TenantAlreadyConnectedException`

See [error handling](#error-handling) for which to catch and why.

### Events you can listen to

All under `Peoplelogy\XeroBridge\Events\`, with public readonly properties:

| Event | Properties |
|---|---|
| `XeroConnected` | `XeroConnection $connection`, `bool $wasRepointed` |
| `TokenRefreshed` | `XeroConnection $connection`, `?CarbonImmutable $expiresAt` |
| `ConnectionExpired` | `XeroConnection $connection`, `string $reason`, `string $connectUrl` |
| `InvoiceCreated` | `string $connectionKey`, `array $invoice`, `?string $idempotencyKey` |
| `XeroWebhookReceived` | `WebhookEvent $event`, `WebhookEnvelope $envelope` |
| `XeroWebhookSignatureFailed` | `?string $signature`, `int $bodyLength`, `?string $ip` |

```php
use Peoplelogy\XeroBridge\Events\ConnectionExpired;

Event::listen(ConnectionExpired::class, function (ConnectionExpired $e) {
    Notification::route('mail', config('app.admin_email'))
        ->notify(new XeroNeedsReconnecting($e->reason, $e->connectUrl));
});
```

`ConnectionExpired` is the one worth wiring up: it fires when the refresh token dies, which no
amount of retrying fixes — a human has to reconnect.

`XeroWebhookSignatureFailed` fires during Xero's intent-to-receive check by design — it sends
deliberately bad signatures to confirm you reject them — so alert on a sustained stream, not on
one.

### Artisan commands

```bash
php artisan xero-bridge:install          # publish config + migration, print the .env keys
php artisan xero-bridge:status           # connections, expiry, missing config — no API call
php artisan xero-bridge:refresh-tokens   # run hourly from your scheduler
```

```php
// routes/console.php
Schedule::command('xero-bridge:refresh-tokens')->hourly()->withoutOverlapping()->onOneServer();
```

`xero-bridge:status` exits `0` when healthy, `1` on incomplete config, `2` when a connection needs
reauthorising — so it can be wired straight into monitoring.

### Four resources

```php
XeroBridge::contacts()     // Contacts
XeroBridge::invoices()     // Invoices
XeroBridge::payments()     // Payments
XeroBridge::settings()     // Organisation, Accounts, TaxRates, BrandingThemes (read-only)
```

Anything the package has not wrapped is still reachable:

```php
XeroBridge::request('GET', 'CreditNotes');          // decoded array
XeroBridge::raw('GET', 'Invoices/{id}');            // the raw Response, for PDFs
XeroBridge::client()->get('Currencies', ['where' => '...']);
```

### Which organisation you are talking to

Most applications connect exactly one organisation, in which case you never name it:

```php
XeroBridge::invoices()->find('INV-0042');                      // the default connection
XeroBridge::connection('acme')->invoices()->find('INV-0042');  // a named one
```

`connection()` returns a **clone**, not `$this`. `XeroBridge::connection('acme')->invoices()` is
scoped to that expression alone and cannot leak into the next call in the same request. The
default connection name comes from `XERO_DEFAULT_CONNECTION` (`default`).

### What you get back

Every method returns **plain PHP arrays, already unwrapped** from Xero's envelope. Xero answers
`POST /Invoices` with `{"Invoices": [ ... ]}`; `create()` hands you the inner element.

| Shape | Methods |
|---|---|
| `array` — the record | `create()`, `update()`, `authorise()`, `organisation()` |
| `?array` — `null` when absent, no exception | `find()`, `findByEmail()` |
| `list<array>` — possibly empty | `list()`, `accounts()`, `taxRates()` |
| `void` | `email()` — Xero answers 204 with no body |
| `string` | `pdf()` (raw bytes), `onlineUrl()` |

### What it does for you

Token refresh (access tokens last 30 minutes, refresh tokens rotate and are refreshed under a
per-connection lock), retries with backoff, `Retry-After` handling, `Idempotency-Key` on writes,
and typed exceptions. You do not write any of that.

### Dates

Send plain `Y-m-d` strings. Xero **returns** `/Date(1772409600000+0000)/`, which is not
JSON-parseable as a date — read `DateString` / `DueDateString` instead, or convert the
milliseconds yourself. A date read back from Xero and re-posted unchanged will be rejected.

---

## Flow 1 — first or create a contact

```php
$contact = XeroBridge::contacts()->firstOrCreate(
    ['EmailAddress' => 'finance@acme.com'],   // exactly ONE lookup key
    [                                          // merged in only when creating
        'Name' => 'Acme Sdn Bhd',
        'FirstName' => 'Siti',
        'LastName' => 'Rahman',
        'CompanyNumber' => '202401012345',     // BRN
        'TaxNumber' => 'C12345678901',         // TIN
    ],
);
```

### Parameters

| Argument | Type | Notes |
|---|---|---|
| `$lookup` | `array<string,string>` | **Exactly one** key, either `EmailAddress` or `Name`. Anything else throws `InvalidArgumentException`. |
| `$attributes` | `array<string,mixed>` | Applied **only on create**. An existing contact comes back untouched. |

### On the wire

A lookup first (`GET /Contacts?where=EmailAddress=="finance@acme.com"`), then, only if nothing
matched, a create:

```json
PUT /api.xro/2.0/Contacts
{
  "Contacts": [
    {
      "EmailAddress": "finance@acme.com",
      "Name": "Acme Sdn Bhd",
      "CompanyNumber": "202401012345",
      "TaxNumber": "C12345678901"
    }
  ]
}
```

### Response

```json
{
  "ContactID": "8138a266-fb42-49b2-a104-014b7045753d",
  "ContactStatus": "ACTIVE",
  "Name": "Acme Sdn Bhd",
  "EmailAddress": "finance@acme.com",
  "CompanyNumber": "202401012345",
  "TaxNumber": "C12345678901",
  "ContactPersons": [],
  "IsCustomer": true,
  "UpdatedDateUTC": "/Date(1790553726193+0000)/",
  "HasValidationErrors": false
}
```

Persist `ContactID`. It is the only stable way to reference a contact.

### Gotchas

- **It is a `PUT`, not a `POST`, on purpose.** `POST /Contacts` is an implicit upsert: matched on
  a non-unique name, it silently overwrites a real customer record. Xero's own guidance is to
  reference contacts by `ContactID`.
- **Race-safe.** If another process creates the contact between the lookup and the create, the
  loser gets a clean conflict and re-reads, rather than clobbering the winner.
- **Never use it to update.** An existing contact ignores `$attributes` entirely. To change a
  contact, call `contacts()->update($contactId, [...])`.
- `CompanyNumber` and `TaxNumber` are capped at 50 characters by Xero.

---

## Flow 2.1 — create a DRAFT invoice

```php
$invoice = XeroBridge::invoices()->create([
    'Type' => 'ACCREC',                                    // REQUIRED
    'Status' => 'DRAFT',
    'Contact' => ['ContactID' => '1111...'],               // ContactID ALONE
    'Date' => '2026-03-02',
    'DueDate' => '2026-04-01',
    'Reference' => 'CCF-2026-0042',
    'LineItems' => [[
        'Description' => 'Leadership training, 2 days',
        'Quantity' => 1,
        'UnitAmount' => 4800,
        'AccountCode' => '200',      // omit to use the connection default
        'TaxType' => 'OUTPUT',       // omit to use the connection default
    ]],
]);
```

### Parameters

| Field | Required | Notes |
|---|---|---|
| `Type` | **yes** | `ACCREC` (sales) or `ACCPAY` (bill). Missing throws `InvalidInvoicePayloadException` before anything is sent. |
| `Contact` | **yes** | `['ContactID' => ...]` — see the gotcha below. |
| `LineItems` | **yes** | At least one. `Description` is the minimum. |
| `Status` | no | Omitted creates a `DRAFT`. |
| `Date`, `DueDate`, `Reference` | no | Plain `Y-m-d`. |
| `AccountCode`, `TaxType`, `CurrencyCode`, `BrandingThemeID` | no | Filled from the connection defaults **only where you left them out**. |

### On the wire

`POST /api.xro/2.0/Invoices`, with an `Idempotency-Key` header:

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

### Response

The method returns the inner invoice, not the envelope:

```json
{
  "Type": "ACCREC",
  "InvoiceID": "22222222-2222-2222-2222-222222222222",
  "InvoiceNumber": "INV-0042",
  "Reference": "CCF-2026-0042",
  "Status": "DRAFT",
  "AmountDue": 5184.00,
  "AmountPaid": 0.00,
  "SubTotal": 4800.00,
  "TotalTax": 384.00,
  "Total": 5184.00,
  "CurrencyCode": "MYR",
  "DateString": "2026-03-02T00:00:00",
  "DueDateString": "2026-04-01T00:00:00",
  "SentToContact": false,
  "Contact": { "ContactID": "1111...", "Name": "Acme Sdn Bhd" },
  "LineItems": [
    {
      "LineItemID": "33333333-3333-3333-3333-333333333333",
      "Description": "Leadership training, 2 days",
      "UnitAmount": 4800.00,
      "TaxType": "OUTPUT",
      "TaxAmount": 384.00,
      "LineAmount": 4800.00,
      "AccountCode": "200",
      "Quantity": 1.0000
    }
  ]
}
```

Persist `InvoiceID` and `InvoiceNumber`. Note that **tax is computed server-side** — you sent
4800, Xero returned a `Total` of 5184.

### Gotchas

- **`Contact` must carry `ContactID` and nothing else.** With `ContactID` plus any other field,
  Xero applies those fields to the *contact record* and **deletes any `ContactPersons` you left
  out**. The package refuses this with `UnsafeContactPayloadException` rather than letting it
  happen; `withContactMutation()` opts in if you genuinely mean it. It is irreversible and nobody
  notices for weeks.
- A `DRAFT` can be deleted outright. Once `AUTHORISED` it can only be voided, and it stays visible
  in Xero forever.
- An unknown `AccountCode` is a 400 → `XeroValidationException`, with Xero's own message in
  `validationErrors()`.
- The `Idempotency-Key` is retained by Xero for **six minutes**. It protects an immediate network
  retry; it does **not** deduplicate a queued job retried an hour later. Persist `InvoiceID` for
  that.

---

## Flow 2.2 — create an invoice that ends up PAID

> ### PAID is not a status you can set
>
> Xero rejects `"Status": "PAID"` on create. `PAID` is what Xero moves an invoice to once payments
> cover it in full. There is no single call for this.

Three steps, in this order:

```php
// 1. AUTHORISED, not DRAFT — a payment cannot attach to a draft
$invoice = XeroBridge::invoices()->create([
    'Type' => 'ACCREC',
    'Status' => 'AUTHORISED',
    'Contact' => ['ContactID' => $contactId],
    'LineItems' => [[ 'Description' => '...', 'Quantity' => 1, 'UnitAmount' => 4800 ]],
]);

// 2. pay Xero's own AmountDue, never the figure you sent
$payment = XeroBridge::payments()->createForInvoice(
    $invoice['InvoiceID'],
    (float) $invoice['AmountDue'],   // 5184.00, not 4800
    '090',                           // a BANK / payments-enabled account code
    '2026-03-02',                    // optional, defaults to today
);

// 3. read it back — do not assume
$settled = XeroBridge::invoices()->find($invoice['InvoiceID']);
// $settled['Status'] === 'PAID'
```

### `createForInvoice()` parameters

| Argument | Type | Notes |
|---|---|---|
| `$invoiceId` | `string` | Must be **AUTHORISED**. |
| `$amount` | `float\|int\|string` | Use the invoice's `AmountDue`. |
| `$accountCode` | `?string` | Type `BANK`, or with "enable payments to this account" on. Discover with `settings()->paymentAccounts()`. Throws `InvalidArgumentException` if null and no connection default. |
| `$date` | `DateTimeInterface\|string\|null` | Defaults to today. |
| `$extra` | `array` | Merged into the payload. |

### On the wire

```json
POST /api.xro/2.0/Payments
{
  "Payments": [
    {
      "Invoice": { "InvoiceID": "c7c37b83-ac95-45ea-88ba-8ad83a5f22fe" },
      "Account": { "Code": "090" },
      "Amount": 5184,
      "Date": "2026-03-02"
    }
  ]
}
```

### Gotchas

- **Pay `AmountDue`, not your own total.** Tax and rounding are applied server-side. Paying the
  pre-tax figure leaves the invoice `AUTHORISED` with a balance, not `PAID`.
- **A `PAID` invoice can never be changed.** `InvoiceTransitions` lists no legal transition out of
  it. To correct one, reverse the payment first with `payments()->delete($paymentId)`, then void.
- Overpaying is rejected by Xero — check `AmountDue` before applying.
- A successful 200 can still carry a `Warnings` array (a `CurrencyRate` warning, typically). The
  package logs it; check it yourself if a wrong foreign-currency amount would matter.

---

## Flow 3 — TIN, BRN, CC list, then send

Three facts shape this flow, and all three surprise people.

> ### `POST /Invoices/{id}/Email` takes an empty body
>
> No `to`, no `cc`, no `bcc`, no `subject`, no `body`, no `replyTo`. Xero's own OpenAPI spec types
> the request body as `RequestEmpty`. The package sends `[]` on the wire and has a test asserting
> it stays empty.

1. **TIN and BRN live on the contact, not the invoice.** In a Malaysian organisation the TIN goes
   in `TaxNumber` (the field is relabelled per region — ABN in Australia, VAT Number in the UK);
   the BRN is `CompanyNumber`. Both max 50 characters.
2. **`ContactPersons` with `IncludeInEmails` is the only CC mechanism.** Maximum five. The list you
   send **replaces** the stored one wholesale, so build it in full every time. It is **per contact,
   not per send** — everyone on it is copied on every future invoice for that customer until you
   change it back.
3. **Xero only emails a SUBMITTED, AUTHORISED or PAID invoice**, so a DRAFT must be approved first.

### Validate the TIN before you store it

From v1.3.0 the package can check the buyer's TIN against LHDN Malaysia's MyInvois API before it is
written onto the contact, rather than trusting whatever was typed:

```php
use Peoplelogy\XeroBridge\MyInvois\Facades\MyInvois;
use Peoplelogy\XeroBridge\MyInvois\IdType;

if (! MyInvois::validate($tin, IdType::BRN, $brn)) {
    // HASiL has no record of this pair. Ask the buyer to confirm both values
    // rather than writing them to the contact and on to the invoice.
}
```

Three things about it matter here:

- **It needs BOTH values.** Since 1 August 2026 LHDN validates the TIN and the registration number
  *as a pair*, so a valid TIN with a stale or mistyped BRN answers exactly like a fabricated one.
  That is a reason to collect the BRN carefully, not a reason to distrust the check.
- **A negative answer is not an error.** LHDN returns HTTP 404 for "no such pair", which is the
  answer you asked for, so the method returns `false`. Only a genuine failure — a malformed request,
  a credential problem, a rate limit, an outage — throws.
- **A positive answer is narrower than it looks.** It proves the pair exists in HASiL's records. It
  does *not* prove the pair belongs to the customer you are invoicing: the endpoint returns no name
  and no address at all.

The module is **off by default** and needs `MYINVOIS_ENABLED=true` with credentials from the
MyInvois portal. Full detail in
[08-myinvois-tin-validation.md](08-myinvois-tin-validation.md).

```php
// (a) the buyer's details, onto the CONTACT
XeroBridge::contacts()->update($contactId, [
    'CompanyNumber' => '202401012345',   // BRN
    'TaxNumber' => 'C12345678901',       // TIN
    'ContactPersons' => [
        ['FirstName' => 'Siti', 'LastName' => 'Rahman',
         'EmailAddress' => 'ap@acme.com', 'IncludeInEmails' => true],
        ['FirstName' => 'Finance', 'LastName' => 'Team',
         'EmailAddress' => 'finance@acme.com', 'IncludeInEmails' => true],
    ],
]);

// (b) Xero refuses to email a DRAFT
XeroBridge::invoices()->authorise($invoiceId);

// (c) send. 204 No Content, so this returns void.
XeroBridge::invoices()->email($invoiceId);
```

### On the wire

`POST /Contacts/{ContactID}`:

```json
{
  "Contacts": [
    {
      "CompanyNumber": "202401012345",
      "TaxNumber": "C12345678901",
      "ContactPersons": [
        { "FirstName": "Siti", "LastName": "Rahman",
          "EmailAddress": "ap@acme.com", "IncludeInEmails": true }
      ]
    }
  ]
}
```

then `POST /Invoices/{InvoiceID}` with nothing but a status:

```json
{ "Invoices": [ { "Status": "AUTHORISED" } ] }
```

then `POST /Invoices/{InvoiceID}/Email` with an empty body.

### Who actually receives it

The contact's own `EmailAddress`, plus every `ContactPerson` whose `IncludeInEmails` is `true`.
That is the entire mechanism.

### Gotchas

- **You cannot copy someone on one invoice only.** Changing who is copied means changing the
  contact, and that applies to every subsequent invoice for that customer.
- **The template and sender are not yours.** Subject and body come from the organisation's email
  template, configured in Xero's UI. The sender is the user who authorised the app connection.
- **You cannot read back what was sent.** There is no API for the rendered email. If you need an
  audit trail, log the `ContactPersons` you set and the moment you called `email()`.
- If none of that fits, send it yourself: `invoices()->pdf($id)` for the bytes or
  `invoices()->onlineUrl($id)` for the public link, build your own Mailable, then call
  `invoices()->markAsSent($id)` so Xero's UI still shows it as sent.

---

## Flow 4 — find one invoice

```php
$invoice = XeroBridge::invoices()->find('INV-0042');   // or the InvoiceID GUID

if ($invoice === null) {
    // no such invoice — this is NOT an exception
}
```

| Argument | Notes |
|---|---|
| `$idOrNumber` | An `InvoiceID` GUID **or** a human `InvoiceNumber` such as `INV-0042`. |

`GET /api.xro/2.0/Invoices/{id}`. Returns the same shape as [2.1](#flow-21--create-a-draft-invoice),
including full `LineItems` and any `Payments` applied.

**Gotcha:** returns `null` for "not found" rather than throwing, so `??` and `is_null()` work
naturally — but an unchecked `$invoice['Status']` will fatal on a miss.

---

## Flow 5 — find a contact

### 5.1 By email — `contacts.find_by_email`

```php
$contact = XeroBridge::contacts()->findByEmail('finance@acme.com');   // ?array
```

`GET /Contacts?where=EmailAddress=="finance@acme.com"`. Returns the **first** match or `null`.

**Gotcha:** Xero allows more than one contact per email address. Treat this as *a* match, not
*the* match. `findAllByEmail()` returns every one if that distinction matters.

### 5.2 By ContactID — `contacts.find`

```php
$contact = XeroBridge::contacts()->find('8138a266-fb42-49b2-a104-014b7045753d');   // ?array
```

`GET /Contacts/{ContactID}`. The only unique way to reference a contact — Xero explicitly warns
that contact names are not unique and recommends `ContactID` everywhere.

Both return the shape shown under [flow 1](#flow-1--first-or-create-a-contact).

---

## Flow 6 — list invoices

```php
use Peoplelogy\XeroBridge\Filters\InvoiceFilter;

$invoices = XeroBridge::invoices()->list(
    InvoiceFilter::make()
        ->statuses(['AUTHORISED', 'PAID'])
        ->type('ACCREC')
        ->dateBetween('2026-01-01', '2026-03-31')
        ->orderBy('Date', 'DESC')
        ->page(1, 25)
);
```

Returns `list<array>` — one page. For everything matching, `all()` returns a `LazyCollection` and
walks Xero's pagination for you:

```php
XeroBridge::invoices()->all($filter)->each(fn (array $invoice) => /* ... */);
```

### Filter methods

| Method | Notes |
|---|---|
| `statuses(string\|array)` | `DRAFT`, `SUBMITTED`, `AUTHORISED`, `PAID`, `VOIDED`, `DELETED` |
| `type(string)` | `ACCREC` or `ACCPAY` |
| `contactIds()`, `contactName()` | |
| `invoiceNumbers()`, `ids()`, `reference()` | |
| `dateBetween($from, $to)` | Either side may be null |
| `modifiedSince($when)` | Sent as the `If-Modified-Since` header, not a query param |
| `orderBy($field, $dir)` | |
| `page($page, $pageSize)`, `pageSize()` | |
| `summaryOnly()` | Lighter rows, no line items |
| `createdByMyApp()` | Only invoices this app created |
| `whereRaw()`, `whereEquals()`, `whereGuid()` | Escape hatches |

### Gotchas

- **Always page.** An unbounded `/Invoices` on a live organisation is the quickest way to spend the
  daily rate limit. `all()` pages at 100 unless told otherwise.
- **Xero caps a page at 1000 and silently clamps anything larger**, so `pageSize()` throws outside
  1–1000 rather than let a typo'd `5000` look like it worked.
- `list()` gives one page and ignores the rest. `all()` is the one that walks.
- `summaryOnly()` omits `LineItems` — don't reach into them on a summary row.
- `modifiedSince()` is a header, so it does not appear in the query string when debugging.

---

## Error handling

Every failure is a typed exception extending `Peoplelogy\XeroBridge\Exceptions\XeroBridgeException`.
Catch the specific one when you can act on it differently; catch the base class as a backstop.

```php
use Peoplelogy\XeroBridge\Exceptions\XeroBridgeException;
use Peoplelogy\XeroBridge\Exceptions\XeroRateLimitException;
use Peoplelogy\XeroBridge\Exceptions\XeroValidationException;

try {
    $invoice = XeroBridge::invoices()->create($payload);
} catch (XeroValidationException $e) {
    // Xero rejected the payload. $e->validationErrors() holds its own messages.
    report($e);
} catch (XeroRateLimitException $e) {
    // $e->retryAfter() is seconds, or null on a concurrency limit.
    $this->release($e->retryAfter() ?? 60);
} catch (XeroBridgeException $e) {
    report($e);
}
```

### What to catch, and what it means

| Exception | Cause | What to do |
|---|---|---|
| `XeroConfigurationException` | A `XERO_*` key is missing or wrong (bad redirect URI, no `offline_access`) | Fix `.env`, `php artisan config:clear`. Never retried. |
| `XeroConnectionNotFoundException` | Nothing connected under that key | Send an admin to `/xero/connect` |
| `XeroReauthorizationRequiredException` | The refresh token is dead (terminal `invalid_grant`) | Reconnect. No code change helps. |
| `XeroScopeException` | The connection was authorised without a scope this call needs | Add it to `XERO_SCOPES` **and reconnect** — an existing connection never gains scopes |
| `XeroValidationException` | 400 from Xero: unknown `AccountCode`, missing contact, bad `TaxType` | Fix the payload. `validationErrors()` names the element. |
| `UnsafeContactPayloadException` | An invoice `Contact` block carries `ContactID` **plus** other fields | Send `ContactID` alone |
| `InvalidInvoicePayloadException` | `Type` missing on create | Nothing was sent |
| `InvalidInvoiceTransitionException` | e.g. voiding a DRAFT | Checked locally against `InvoiceTransitions`, so the message names the current status |
| `XeroRateLimitException` | 429 | `retryAfter()` on minute/daily limits; **null on a concurrency limit** — back off yourself |
| `XeroServiceUnavailableException` | Organisation offline, or Xero is down | Retry in a few minutes |
| `XeroRequestException` | Anything else non-2xx | Inspect `statusCode()` and `response()` |

### Useful accessors on any `XeroBridgeException`

```php
$e->statusCode();        // ?int
$e->connectionKey();     // ?string
$e->validationErrors();  // array — Xero's own messages
$e->xeroType();          // ?string
$e->xeroErrorNumber();   // ?int
$e->retryAfter();        // ?int seconds
$e->response();          // ?Illuminate\Http\Client\Response
$e->context();           // array
```

### In a queued job

`retryAfter()` exists so a worker can `release()` itself instead of sleeping. A daily rate limit
can carry a `Retry-After` measured in hours — never sleep inline on one.

---

## Things that differ per organisation

**Nothing below should ever be hardcoded.** These differ between Xero organisations, so a value
that works in the sandbox will be rejected in production.

| What | How to discover it |
|---|---|
| Account codes | `XeroBridge::settings()->accounts()` — a `200` sales account in one org is `4000` in another |
| Payment accounts | `XeroBridge::settings()->paymentAccounts()` — type `BANK` or payments-enabled only |
| Tax types | `XeroBridge::settings()->taxRates()` — the `TaxType` code, not the label |
| Branding themes | `XeroBridge::settings()->brandingThemes()` |
| Base currency, region, `IsDemoCompany` | `XeroBridge::settings()->organisation()` |

This is also why three of the four per-connection defaults ship **no value at all**. Only
`XERO_CURRENCY` has one, and only because a base currency is a property of the organisation rather
than of a line:

| Setting | Ships a default? |
|---|---|
| `XERO_ACCOUNT_CODE` | no — the `200` fallback was removed in v1.1.0 |
| `XERO_TAX_TYPE` | no |
| `XERO_BRANDING_THEME_ID` | no |
| `XERO_CURRENCY` | yes, `MYR` |

A wrong-but-present account code is worse than a missing one: Xero accepts the invoice and posts it
to whatever account that code happens to be in that organisation, and nobody notices until Finance
reconciles. With no default, Xero rejects the invoice and names the problem.

`XERO_TAX_TYPE` is unset for a second reason. Tax is frequently **per line**, not per invoice —
under Malaysian SST, for instance, training is 8% while education and rental are 6%, so a single
invoice can legitimately carry two rates. A connection-wide default would quietly stamp the wrong
one on a venue recharge. Set it only if every line of every invoice on that connection genuinely
carries the same rate.

None of these are cached by the package. The right cache key, TTL and invalidation depend
entirely on the consuming application, so caching belongs there.

The same caution applies to a MyInvois TIN check. A match tells you the TIN and registration number
exist together in HASiL's records and nothing further — no name is returned, so it is not evidence
of who you are dealing with. And because the pair has only been validated together since 1 August
2026, any verdict stored before that date was answering a weaker question and is worth re-running.

---

## Related

- The numbered guides in this directory — [01-getting-started](01-getting-started.md),
  `02-invoices`, `03-contacts-payments-settings`, `04-webhooks-and-events`,
  `05-commands-and-errors`, `06-recipes`, `07-test-console`. Deeper than this page on every topic,
  and the authority where the two disagree.
- The test console at `/xero/console` — runs every flow below by hand and shows the request
  timings, the rate limit Xero reported and the full response. See `07-test-console` for the write
  guard and for how it is switched on and off.
- `php artisan xero-bridge:status` — connections, token expiry and missing configuration, without
  calling Xero
- `08-myinvois-tin-validation` in the package docs — the optional LHDN MyInvois taxpayer TIN
  validator: turning it on, the 404-is-an-answer contract, caching and troubleshooting
- [Xero Accounting API reference](https://developer.xero.com/documentation/api/accounting/overview)
  — for the fields this package passes through untouched
