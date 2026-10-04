# Contacts, payments and settings

The three resources around invoicing: who you are billing, how you record that they paid, and
where the codes on an invoice actually come from.

If you read only one section, read [Settings](#settings) first. **Account codes and tax types
differ per Xero organisation**, and nearly every avoidable failure in this API comes from an
application that hardcoded one.

All three resources are reached through the `XeroBridge` facade, and all three are scoped by the
connection you ask for:

```php
use Peoplelogy\XeroBridge\Facades\XeroBridge;

XeroBridge::contacts();                       // default connection
XeroBridge::payments();
XeroBridge::settings();

XeroBridge::connection('acme')->contacts();   // a named organisation
```

`connection()` returns a clone, so the expression above is scoped to that one call chain and
never leaks into later ones.

## Scopes

| Resource | Scope in the package's default `XERO_SCOPES` |
|---|---|
| `contacts()` | `accounting.contacts` (read-only: `accounting.contacts.read`) |
| `payments()` | `accounting.payments` |
| `settings()` | `accounting.settings` (read-only: `accounting.settings.read`) |

Xero's published OpenAPI specification still labels the `/Payments` operations with the broad
`accounting.transactions` scope, which the granular scopes replace. The broad scopes remain
usable until September 2027, and since March 2026 every Web and PKCE app — new and existing —
has been assigned the granular ones.

> ⚠️ Scopes are fixed at authorisation time. If you add one, deploy the new `XERO_SCOPES` value
> **before** anyone reconnects, or they will have to connect twice.

## Shapes you will see in every response

Xero returns PascalCase keys and .NET dates. A payment date reads back as
`"/Date(1543449600000+0000)/"`, never as `2018-11-29`. The package deliberately does not rewrite
response bodies — use `Peoplelogy\XeroBridge\Support\XeroDate` to read them:

```php
use Peoplelogy\XeroBridge\Support\XeroDate;

$payment = XeroBridge::payments()->find($paymentId);

$paidOn = XeroDate::from($payment, 'Date');   // CarbonImmutable|null
```

Every method below unwraps Xero's collection envelope for you. Where Xero returns
`{"Id": "...", "Status": "OK", "Contacts": [ ... ]}`, you get the array under `Contacts`, or its
first element for the singular methods.

---

# Contacts

A Xero contact is a customer *or* a supplier — the same record. `IsCustomer` and `IsSupplier` are
read-only flags Xero sets automatically the first time an invoice or a bill is raised against the
contact; you cannot set them on write.

## Why `firstOrCreate()` does not use POST

`POST /Contacts` has an implicit upsert: if the payload's `Name` or `ContactNumber` matches an
existing contact, Xero **updates that contact** instead of creating one. That sounds convenient
and is the single most dangerous behaviour in this part of the API, because Xero also warns that
contact names are no longer guaranteed to be unique and recommends referencing contacts by
`ContactID` alone.

Put those two facts together and a POST-based find-or-create has a silent failure mode. Two
requests for the same customer arrive at once:

1. Request A looks up "Acme Sdn Bhd", finds nothing, POSTs it. A contact is created.
2. Request B looks up "Acme Sdn Bhd" a millisecond earlier, finds nothing, POSTs it too.
3. Xero matches B's payload against the contact A just created and **overwrites it** — including
   deleting any `ContactPersons` B did not include.

Nobody gets an error. A real customer record has been rewritten by the loser of a race.

`create()` therefore uses `PUT /Contacts`, which is create-only: a duplicate name is a `400`
validation error, not a silent overwrite. `firstOrCreate()` is built as an explicit lookup
followed by that create-only PUT, and it resolves the race by catching the validation error and
looking the winner up:

```
lookup ──found──────────────────────────────► return it        (no write at all)
   │
   └─not found─► PUT ──200────────────────────► return it
                  │
                  └─400 validation ─► lookup ──found─► return the winner
                                         │
                                         └─not found─► rethrow
```

The rethrow matters: a `400` that was *not* a race — a `TaxNumber` longer than 50 characters, say —
must not be swallowed. `tests/Unit/ContactsPaymentsSettingsTest.php` pins all three paths.

## Contact name rules

`create()` validates the name before sending, so the failure names the rule rather than arriving
as a generic Xero validation error. A name must be a non-empty string, at most 255 characters,
free of `<` and `>`, without leading or trailing whitespace, and without repeated spaces.

All five are `InvalidArgumentException`, thrown before any HTTP request is made.

## Registration and tax numbers

Two fields carry a business's statutory identifiers. Xero's own descriptions:

| Field | Xero's description |
|---|---|
| `CompanyNumber` | "Company registration number (max length = 50)" |
| `TaxNumber` | "Tax number of contact – this is also known as the ABN (Australia), GST Number (New Zealand), VAT Number (UK) or Tax ID Number (US and global) in the Xero UI depending on which regionalized version of Xero you are using (max length = 50)" |
| `TaxNumberType` | "Identifier of the regional type of tax number, such as US, UK, or other regional tax identifiers" — enum `SSN`, `EIN`, `ITIN`, `ATIN` |

`TaxNumber` is a single field whose *label in the Xero UI* changes with the organisation's region.
In a Malaysian organisation it is where the TIN goes; the API name is `TaxNumber` regardless.
Neither field is validated by Xero beyond its length, so validate the format on your side if it
matters.

## Contact persons, and how to copy people on an invoice email

`ContactPersons` is Xero's **only** mechanism for copying additional people on an emailed invoice.
`POST /Invoices/{id}/Email` takes an empty request body — there is no CC parameter, no recipient
list, nothing to pass. Whoever is on the contact is who gets the email.

The field that decides it is `IncludeInEmails`, described in the specification as "boolean to
indicate whether contact should be included on emails with invoices etc.":

```php
XeroBridge::contacts()->update($contactId, [
    'ContactID' => $contactId,
    'ContactPersons' => [
        [
            'FirstName' => 'Sue',
            'LastName' => 'Johnson',
            'EmailAddress' => 'accounts@example.test',
            'IncludeInEmails' => true,
        ],
        [
            'FirstName' => 'Raj',
            'LastName' => 'Menon',
            'EmailAddress' => 'procurement@example.test',
            'IncludeInEmails' => false,   // stored, but not emailed
        ],
    ],
]);
```

> ⚠️ `ContactPersons` is replaced wholesale, not merged. Xero's documented behaviour is that "any
> ContactPersons not included in the request are deleted". Read the contact, modify the array,
> send the whole array back.

This is also why the `Contact` block of an invoice must contain **only** `ContactID`; the package
throws `UnsafeContactPayloadException` if it carries anything else. See
[Invoices](02-invoices.md).

---

### find()

Fetch one contact by its Xero identifier.

```php
public function find(string $contactId): ?array
```

**Parameters**

| Name | Type | Notes |
|---|---|---|
| `$contactId` | `string` | A `ContactID` (GUID) or a `ContactNumber`. Both are valid path segments for `GET /Contacts/{...}`. |

**Request**

```php
$contact = XeroBridge::contacts()->find('8138a266-fb42-49b2-a104-014b7045753d');
```

Sends `GET /api.xro/2.0/Contacts/8138a266-fb42-49b2-a104-014b7045753d`.

**Response** (trimmed)

```json
{
  "Id": "5c83b115-a6e8-4f2a-877f-ba63d009235b",
  "Status": "OK",
  "ProviderName": "Xero API Partner",
  "DateTimeUTC": "/Date(1551462703288)/",
  "Contacts": [
    {
      "ContactID": "8138a266-fb42-49b2-a104-014b7045753d",
      "ContactNumber": "SB2",
      "AccountNumber": "1234567",
      "ContactStatus": "ACTIVE",
      "Name": "Acme Parts Co.",
      "FirstName": "Blake",
      "LastName": "Kohler",
      "CompanyNumber": "NumberBusiness1234",
      "EmailAddress": "bk@example.test",
      "TaxNumber": "123-22-3456",
      "AccountsReceivableTaxType": "TAX003",
      "Addresses": [
        {
          "AddressType": "STREET",
          "AddressLine1": "123 Fake Street",
          "City": "Vancouver",
          "Region": "British Columbia",
          "PostalCode": "V6B 2T4"
        }
      ],
      "Phones": [
        { "PhoneType": "DEFAULT", "PhoneNumber": "408-0914", "PhoneAreaCode": "604", "PhoneCountryCode": "1" }
      ],
      "ContactPersons": [
        { "FirstName": "Sue", "LastName": "Johnson", "EmailAddress": "sue.johnson@example.test", "IncludeInEmails": true }
      ],
      "IsSupplier": true,
      "IsCustomer": true,
      "DefaultCurrency": "USD",
      "UpdatedDateUTC": "/Date(1551459777193+0000)/",
      "HasValidationErrors": false
    }
  ]
}
```

You receive the inner object. `null` comes back only if Xero returns an empty `Contacts` array;
an unknown GUID is a `404` from Xero and throws.

**Notes / gotchas**

- Throws `XeroBridgeException` *before sending* if `$contactId` is an empty string or contains a
  `/`. A slash would be mangled by edge proxies long before it reached Xero, producing a confusing
  `404`.
- `MergedToContactID` is only populated on this fetch-by-id path (and when paging). If a contact
  was merged into another, that is where the destination shows up.

### findByEmail()

Exact-match lookup by email address, returning the first match.

```php
public function findByEmail(string $email): ?array
```

**Request**

```php
$contact = XeroBridge::contacts()->findByEmail('accounts@example.test');
```

Sends `GET /api.xro/2.0/Contacts?where=EmailAddress%3D%3D%22accounts%40example.test%22`, which
decodes to `where=EmailAddress=="accounts@example.test"`.

**Response**

Same envelope as `find()`, with zero or more contacts. `null` when the array is empty.

**Notes / gotchas**

- Xero documents the exact match as the optimised form and lists
  `EmailAddress.StartsWith(...)` under the unoptimised queries to avoid. The package never
  generates `StartsWith` — there is a test asserting the URL does not contain it. Use
  [`search()`](#search) for substring matching.
- Xero does not enforce email uniqueness on contacts. This method silently takes the first of
  however many matched, which is wrong often enough to matter — use
  [`findAllByEmail()`](#findallbyemail) when a duplicate would be a problem.
- Throws `InvalidArgumentException` if the email contains a double quote, which would otherwise
  break out of the filter expression.

### findAllByEmail()

Every contact with this email address.

```php
public function findAllByEmail(string $email): array
```

**Request**

```php
$matches = XeroBridge::contacts()->findAllByEmail('accounts@example.test');

if (count($matches) > 1) {
    Log::warning('Duplicate Xero contacts share an email address.', [
        'email' => 'accounts@example.test',
        'contact_ids' => array_column($matches, 'ContactID'),
    ]);
}
```

**Response**

A `list<array>` of contact objects in the shape shown under `find()`, or `[]`.

**Notes / gotchas**

- No `page` parameter is sent, so Xero returns the unpaged result set. If you expect enough
  matches to want paging, request it yourself with
  `XeroBridge::client()->get('Contacts', ['where' => $filter, 'page' => $n])`, which hands back
  the whole body including the `pagination` block.
- Same double-quote guard as `findByEmail()`.

### findAllByName()

Every contact whose `Name` matches exactly.

```php
public function findAllByName(string $name): array
```

**Request**

```php
$matches = XeroBridge::contacts()->findAllByName('Acme Sdn Bhd');
```

Sends `where=Name=="Acme Sdn Bhd"`.

**Response**

A `list<array>` of contact objects, or `[]`.

**Notes / gotchas**

- Exact and case-sensitive on Xero's side. "ACME SDN BHD" will not match.
- Contact names are **not** guaranteed unique. Treat more than one result as a real possibility,
  not a data error.
- Throws `InvalidArgumentException` if the name contains a double quote.

### search()

Case-insensitive substring search across several fields.

```php
public function search(string $term): array
```

**Request**

```php
$matches = XeroBridge::contacts()->search('acme');
```

Sends `GET /api.xro/2.0/Contacts?SearchTerm=acme`.

**Response**

A `list<array>` of contact objects, or `[]`.

**Notes / gotchas**

- Xero describes `SearchTerm` as "a case-insensitive text search across the fields: Name,
  FirstName, LastName, ContactNumber, CompanyNumber, EmailAddress". It does not search
  `TaxNumber`. (The OpenAPI specification still omits `CompanyNumber` from that list; the Contacts
  reference page is the newer of the two.)
- This is the right tool for a type-ahead box and the wrong tool for reconciliation. For "is this
  exact customer already in Xero?" use `findAllByEmail()` or `findAllByName()`.
- No double-quote guard is applied, because `SearchTerm` is a plain query parameter rather than a
  filter expression.

### create()

Create a contact, erroring rather than overwriting if it already exists.

```php
public function create(array $contact): array
```

**Parameters**

| Name | Type | Notes |
|---|---|---|
| `$contact` | `array<string, mixed>` | A Xero `Contact` object. `Name` is required and validated locally. |

**Request**

```php
$contact = XeroBridge::contacts()->create([
    'Name' => 'Acme Sdn Bhd',
    'EmailAddress' => 'accounts@example.test',
    'CompanyNumber' => '202001234567',
    'TaxNumber' => 'C1234567890',
    'Addresses' => [
        [
            'AddressType' => 'STREET',
            'AddressLine1' => 'Level 10, Menara Example',
            'City' => 'Kuala Lumpur',
            'PostalCode' => '50450',
            'Country' => 'Malaysia',
        ],
    ],
    'ContactPersons' => [
        [
            'FirstName' => 'Sue',
            'LastName' => 'Johnson',
            'EmailAddress' => 'sue.johnson@example.test',
            'IncludeInEmails' => true,
        ],
    ],
]);
```

The package sends `PUT /api.xro/2.0/Contacts` with:

```json
{
  "Contacts": [
    {
      "Name": "Acme Sdn Bhd",
      "EmailAddress": "accounts@example.test",
      "CompanyNumber": "202001234567",
      "TaxNumber": "C1234567890",
      "Addresses": [ { "AddressType": "STREET", "AddressLine1": "Level 10, Menara Example", "City": "Kuala Lumpur", "PostalCode": "50450", "Country": "Malaysia" } ],
      "ContactPersons": [ { "FirstName": "Sue", "LastName": "Johnson", "EmailAddress": "sue.johnson@example.test", "IncludeInEmails": true } ]
    }
  ]
}
```

**Response** (trimmed)

```json
{
  "Id": "d4f1b6a0-3f7e-4c7a-9a3f-2b6d0a9e1c55",
  "Status": "OK",
  "ProviderName": "Xero API Partner",
  "DateTimeUTC": "/Date(1551462703288)/",
  "Contacts": [
    {
      "ContactID": "8138a266-fb42-49b2-a104-014b7045753d",
      "ContactStatus": "ACTIVE",
      "Name": "Acme Sdn Bhd",
      "EmailAddress": "accounts@example.test",
      "CompanyNumber": "202001234567",
      "TaxNumber": "C1234567890",
      "ContactPersons": [
        { "FirstName": "Sue", "LastName": "Johnson", "EmailAddress": "sue.johnson@example.test", "IncludeInEmails": true }
      ],
      "IsSupplier": false,
      "IsCustomer": false,
      "UpdatedDateUTC": "/Date(1551459777193+0000)/",
      "HasValidationErrors": false
    }
  ]
}
```

You receive the inner object, or `[]` if Xero returned an empty collection.

**Notes / gotchas**

- **PUT, not POST.** POST would upsert on a matching `ContactName` or `ContactNumber` and silently
  overwrite a real customer. There is a test asserting the method is `PUT`.
- A duplicate name therefore throws `XeroValidationException` (`400`). Read
  `$e->validationErrors()` for Xero's messages.
- `IsCustomer` and `IsSupplier` cannot be set here. Xero sets them when the first invoice or bill
  is raised.
- Throws `InvalidArgumentException` for any of the five name rules, before sending.
- A write carries an automatic `Idempotency-Key` header (configurable via
  `xero-bridge.http.idempotency`). Xero retains that key for six minutes only — it protects an
  immediate network retry, not a queued job retried an hour later.

### update()

Update an existing contact by `ContactID`.

```php
public function update(string $contactId, array $contact): array
```

**Parameters**

| Name | Type | Notes |
|---|---|---|
| `$contactId` | `string` | The `ContactID` to update. URL-encoded into the path. |
| `$contact` | `array<string, mixed>` | The fields to change. Elements you omit are preserved. |

**Request**

```php
$contact = XeroBridge::contacts()->update('8138a266-fb42-49b2-a104-014b7045753d', [
    'ContactID' => '8138a266-fb42-49b2-a104-014b7045753d',
    'EmailAddress' => 'finance@example.test',
    'TaxNumber' => 'C1234567890',
]);
```

Sends `POST /api.xro/2.0/Contacts/8138a266-fb42-49b2-a104-014b7045753d` with
`{"Contacts": [ ... ]}`.

**Response**

The updated contact, same shape as `create()`.

**Notes / gotchas**

- **Omitted top-level fields are preserved, but `ContactPersons` is not.** Xero deletes any
  contact persons not present in the request. If the contact has contact persons and you are
  changing anything at all, read the contact first and send the full array back.
- No name validation runs on this path — `assertValidName()` is only applied by `create()`. Xero
  will reject a bad name with a `400`.
- To archive a contact, set `'ContactStatus' => 'ARCHIVED'`. There is no `delete()`; Xero contacts
  cannot be deleted through the API.

### firstOrCreate()

Find a contact by one lookup key, or create it, without ever risking a silent overwrite.

```php
public function firstOrCreate(array $lookup, array $attributes = []): array
```

**Parameters**

| Name | Type | Notes |
|---|---|---|
| `$lookup` | `array<string, string>` | Exactly one entry, keyed `EmailAddress` or `Name`. Anything else throws. |
| `$attributes` | `array<string, mixed>` | Merged over `$lookup` when a contact has to be created. Ignored entirely when one is found. |

**Request**

```php
$contact = XeroBridge::contacts()->firstOrCreate(
    ['EmailAddress' => 'accounts@example.test'],
    [
        'Name' => 'Acme Sdn Bhd',
        'CompanyNumber' => '202001234567',
        'ContactPersons' => [
            [
                'FirstName' => 'Sue',
                'LastName' => 'Johnson',
                'EmailAddress' => 'sue.johnson@example.test',
                'IncludeInEmails' => true,
            ],
        ],
    ],
);

$invoice = XeroBridge::invoices()->create([
    'Type' => 'ACCREC',
    'Contact' => ['ContactID' => $contact['ContactID']],   // ContactID ONLY
    'LineItems' => [
        ['Description' => 'Leadership workshop', 'Quantity' => 1, 'UnitAmount' => 4500.00],
    ],
]);
```

**Request traffic**

| Situation | Requests sent |
|---|---|
| Contact exists | 1 — the lookup `GET`. No write at all. |
| Contact does not exist | 2 — `GET`, then the create-only `PUT`. |
| Race lost | 3 — `GET`, `PUT` (returns `400`), `GET` again for the winner. |

**Response**

A contact object, identical in shape whichever path was taken.

**Notes / gotchas**

- **Looking up by `EmailAddress` still requires a `Name` in `$attributes`.** The lookup key and the
  attributes are merged to form the create payload, so
  `firstOrCreate(['EmailAddress' => 'accounts@example.test'])` with no attributes throws
  `InvalidArgumentException` ("A contact needs a Name.") the moment no match is found — which may
  be long after you shipped it.
- `$attributes` is merged *over* `$lookup`, so a `Name` in both means the attribute wins. Do not
  pass a different value in each.
- Throws `InvalidArgumentException` if `$lookup` does not have exactly one entry, or if its key is
  anything other than `EmailAddress` or `Name`. Nothing is sent in that case.
- A `XeroValidationException` that was not a race is rethrown untouched. Do not catch it and retry
  blindly.
- Looking up by `Name` takes the first of any duplicates. Prefer `EmailAddress`, and prefer
  storing the returned `ContactID` in your own database over looking the contact up again.

---

# Payments

A payment records that money moved against an invoice. It is not a request to a payment gateway —
Xero is a ledger, and this endpoint writes to it.

## There is deliberately no `update()`

**Xero payments cannot be modified.** They can only be created and deleted. The package therefore
exposes `create()` and `delete()` and no `update()`, and there is a one-line test asserting the
method does not exist, so that a well-meaning contributor cannot quietly add one.

To correct a payment: `delete()` it and `create()` a replacement.

## The account rule

Every payment needs an account, and Xero only accepts an account that is **of type `BANK`, or has
"enable payments to this account" switched on** (`EnablePaymentsToAccount: true`). That is an OR
across two different fields, which is why
[`settings()->paymentAccounts()`](#paymentaccounts) exists — call it rather than guessing.

Identify the account by either `Account.AccountID` (a GUID) or `Account.Code` (the short code such
as `"090"`). The package checks one of the two is present before sending.

## Dates: `YYYY-MM-DD` on write, `/Date(...)/` on read

Xero **accepts** a plain `2026-09-28` and **returns** `/Date(1543449600000+0000)/`. Reading a
payment and posting it straight back would therefore fail with an error that never mentions dates.

`create()` normalises whatever you pass through `XeroDate::toApiDate()`, so a `DateTimeInterface`,
an ISO string, and a .NET date read straight out of a Xero response all work:

```php
XeroBridge::payments()->create([
    'Invoice' => ['InvoiceID' => $invoiceId],
    'Account' => ['Code' => '090'],
    'Amount' => 100,
    'Date' => '/Date(1439434356790)/',   // sent as "2015-08-13"
]);
```

A `DateTimeInterface` is formatted, not converted to UTC: a Malaysian date stays the local
calendar date, so a payment cannot slip into the previous accounting period.

## The invoice must be AUTHORISED

A `DRAFT` or `SUBMITTED` invoice cannot take a payment. Authorise it first —
`XeroBridge::invoices()->authorise($invoiceId)` — and Xero flips the status to `PAID` by itself
once the invoice is fully paid. Do not set `PAID` yourself.

The amount must also be less than or equal to the invoice's outstanding `AmountDue`.

## Warnings arrive on a successful 200

A `CurrencyRate` warning can come back on a `200`. The HTTP client only raises *failures*, so
without special handling that warning would be invisible — and it can mean the foreign-currency
amount you posted is not the amount that landed.

`create()` therefore inspects `Warnings[]` on the created payment and logs each message through
the container's `Psr\Log\LoggerInterface`:

```
xero-bridge: Xero accepted the payment but returned warnings.
  connection: default
  warnings: ["A currency rate was applied"]
```

The warnings also stay on the returned array, so you can react to them:

```php
$payment = XeroBridge::payments()->createForInvoice($invoiceId, 4500.00, '090');

foreach ($payment['Warnings'] ?? [] as $warning) {
    $warning['Message'];   // e.g. "A currency rate was applied"
}
```

---

### create()

Apply a payment to an invoice, credit note, prepayment or overpayment.

```php
public function create(array $payment): array
```

**Parameters**

| Name | Type | Notes |
|---|---|---|
| `$payment` | `array<string, mixed>` | A Xero `Payment` object. Must carry an account and exactly one target. `Date` is normalised if present. |

**Request**

```php
$payment = XeroBridge::payments()->create([
    'Invoice' => ['InvoiceID' => '046d8a6d-1ae1-4b4d-9340-5601bdf41b87'],
    'Account' => ['Code' => '090'],
    'Amount' => 4500.00,
    'Date' => '2026-09-28',
    'Reference' => 'FPX 20260928-0041',
]);
```

The package sends `POST /api.xro/2.0/Payments` with:

```json
{
  "Payments": [
    {
      "Invoice": { "InvoiceID": "046d8a6d-1ae1-4b4d-9340-5601bdf41b87" },
      "Account": { "Code": "090" },
      "Amount": 4500.00,
      "Date": "2026-09-28",
      "Reference": "FPX 20260928-0041"
    }
  ]
}
```

**Response** (trimmed)

```json
{
  "Id": "b6a4e2f1-9c3d-4a52-8f10-7e5c1d2b3a44",
  "Status": "OK",
  "ProviderName": "Provider Name Example",
  "DateTimeUTC": "/Date(1552431874205)/",
  "Payments": [
    {
      "PaymentID": "99ea7f6b-c513-4066-bc27-b7c65dcd76c2",
      "Date": "/Date(1543449600000+0000)/",
      "BankAmount": 4500.00,
      "Amount": 4500.00,
      "Reference": "FPX 20260928-0041",
      "CurrencyRate": 1.000000,
      "PaymentType": "ACCRECPAYMENT",
      "Status": "AUTHORISED",
      "UpdatedDateUTC": "/Date(1541176592690+0000)/",
      "UpdatedDateUTCString": "2018-11-02T16:36:32Z",
      "HasAccount": true,
      "IsReconciled": false,
      "Account": {
        "AccountID": "5690f1e8-1d02-4893-90c2-ee1a69eff942",
        "Code": "090",
        "Name": "Business Bank Account"
      },
      "Invoice": {
        "Type": "ACCREC",
        "InvoiceID": "046d8a6d-1ae1-4b4d-9340-5601bdf41b87",
        "InvoiceNumber": "INV-0002",
        "Status": "PAID",
        "AmountDue": 0.00,
        "AmountPaid": 4500.00,
        "CurrencyCode": "MYR",
        "FullyPaidOnDate": "/Date(1543449600000+0000)/",
        "Contact": { "ContactID": "a3675fc4-f8dd-4f03-ba5b-f1870566bcd7", "Name": "Acme Sdn Bhd" }
      },
      "HasValidationErrors": false
    }
  ]
}
```

Note `Invoice.Status` has become `PAID` and `AmountDue` is `0.00` — Xero did that, not you.

**Notes / gotchas**

- Throws `InvalidArgumentException` if there is no `Account.AccountID` or `Account.Code`, and again
  if there is no `Invoice`, `CreditNote`, `Prepayment` or `Overpayment` key. Both fire before any
  request is sent; the account one names `settings()->paymentAccounts()` in the message.
- Throws `XeroBridgeException` if the payload contains an `Invoices` key. `POST /Payments` applies
  **one** payment to **one** invoice; settling several invoices with a single bank transaction is
  `/BatchPayments`, reachable via `XeroBridge::request('POST', 'BatchPayments', $payload)`.
- Throws `XeroValidationException` (`400`) when the invoice is not `AUTHORISED`, when the amount
  exceeds `AmountDue`, or when the account is not payment-enabled.
- Set `'IsReconciled' => true` only if you genuinely reconcile outside Xero. It is an assertion
  about the bank statement, not a formality.
- `CurrencyRate` applies only to non-base-currency invoices. Leaving it out lets Xero choose the
  rate — and produces the warning described above.

### createForInvoice()

The common case, with the fields Xero requires filled in for you.

```php
public function createForInvoice(
    string $invoiceId,
    float|int|string $amount,
    ?string $accountCode = null,
    DateTimeInterface|string|null $date = null,
    array $extra = [],
): array
```

**Parameters**

| Name | Type | Default | Notes |
|---|---|---|---|
| `$invoiceId` | `string` | — | The `InvoiceID` to pay. Must be an `AUTHORISED` invoice. |
| `$amount` | `float\|int\|string` | — | At most the invoice's outstanding `AmountDue`. |
| `$accountCode` | `?string` | `null` | Falls back to the connection's `payment_account_code` default. |
| `$date` | `DateTimeInterface\|string\|null` | `null` | Falls back to `now()`. Normalised to `YYYY-MM-DD`. |
| `$extra` | `array` | `[]` | Merged over the generated payload — `Reference`, `CurrencyRate`, `IsReconciled`, and so on. |

**Request**

```php
use Illuminate\Support\Carbon;

$payment = XeroBridge::payments()->createForInvoice(
    invoiceId: '046d8a6d-1ae1-4b4d-9340-5601bdf41b87',
    amount: 4500.00,
    accountCode: '090',
    date: Carbon::parse('2026-09-28'),
    extra: ['Reference' => 'FPX 20260928-0041'],
);
```

Which builds exactly the payload shown under `create()` and sends it the same way.

**Response**

Identical to `create()`.

**Notes / gotchas**

- `payment_account_code` is **not** in the shipped config. Add it per connection, or supply it per
  call:

  ```php
  // config/xero-bridge.php
  'connections' => [
      'default' => [
          'account_code' => env('XERO_ACCOUNT_CODE'),   // sales, for invoice lines
          'payment_account_code' => env('XERO_PAYMENT_ACCOUNT_CODE'),  // bank, for payments
          // ...tax_type, currency and branding_theme_id as before
      ],
  ],
  ```

  ```php
  // or at the call site
  XeroBridge::withDefaults(['payment_account_code' => '090'])
      ->payments()
      ->createForInvoice($invoiceId, 4500.00);
  ```

  The override applies to that expression only. It is honoured even if `payments()` was already
  resolved for this connection earlier in the process, and it never reaches a later plain
  `XeroBridge::payments()` call, which goes back to the configured value — or throws, if there is
  none. `tests/Unit/WithDefaultsTest.php` pins both. Passing `$accountCode` per call works just as
  well. [Overriding defaults](02-invoices.md#overriding-defaults) has the rest.

  These are two different accounts and mixing them up posts revenue to the bank ledger.
- With neither an argument nor a default, throws `InvalidArgumentException` pointing at
  `settings()->paymentAccounts()`.
- `$extra` is merged *over* the generated keys, so it can override `Date`, `Amount`, `Account` and
  `Invoice`. That is occasionally useful and mostly a way to surprise yourself.
- Everything `create()` throws, this throws too — it delegates.

### find()

Fetch one payment.

```php
public function find(string $paymentId): ?array
```

**Request**

```php
$payment = XeroBridge::payments()->find('99ea7f6b-c513-4066-bc27-b7c65dcd76c2');
```

Sends `GET /api.xro/2.0/Payments/99ea7f6b-c513-4066-bc27-b7c65dcd76c2`.

**Response**

The payment object shown under `create()`, or `null` if the collection came back empty.

**Notes / gotchas**

- `Date` and `UpdatedDateUTC` are .NET dates. `UpdatedDateUTCString` is an ISO-8601 sibling, and
  `XeroDate::from($payment, 'UpdatedDateUTC')` prefers it automatically.
- A payment made through a batch carries `BatchPaymentID` and a nested `BatchPayment` block. That
  payment cannot be deleted with `delete()` — see below.

### list()

List payments, optionally filtered.

```php
public function list(array $query = []): array
```

**Parameters**

| Name | Type | Notes |
|---|---|---|
| `$query` | `array<string, mixed>` | Passed straight through as query parameters, e.g. `['where' => 'Status=="AUTHORISED"']`. |

**Request**

```php
use Peoplelogy\XeroBridge\Support\XeroDate;

$payments = XeroBridge::payments()->list([
    'where' => sprintf(
        'Status=="AUTHORISED" AND Date >= %s',
        XeroDate::toFilterLiteral('2026-09-01'),
    ),
    'order' => 'Date DESC',
]);
```

The `where` becomes `Status=="AUTHORISED" AND Date >= DateTime(2026, 9, 1)`.

**Response** (trimmed)

```json
{
  "Id": "9f310473-e1b5-4704-a25c-eec653deb596",
  "Status": "OK",
  "ProviderName": "Provider Name Example",
  "DateTimeUTC": "/Date(1552431874205)/",
  "Payments": [
    {
      "PaymentID": "99ea7f6b-c513-4066-bc27-b7c65dcd76c2",
      "Date": "/Date(1543449600000+0000)/",
      "Amount": 46.00,
      "BankAmount": 46.00,
      "CurrencyRate": 1.000000,
      "PaymentType": "ACCRECPAYMENT",
      "Status": "AUTHORISED",
      "IsReconciled": false,
      "Account": { "AccountID": "5690f1e8-1d02-4893-90c2-ee1a69eff942", "Code": "970" },
      "Invoice": { "Type": "ACCREC", "InvoiceID": "046d8a6d-1ae1-4b4d-9340-5601bdf41b87", "InvoiceNumber": "INV-0002", "CurrencyCode": "MYR" }
    },
    {
      "PaymentID": "6b037c9b-2e5d-4905-84d3-eabfb3438242",
      "Date": "/Date(1552521600000+0000)/",
      "Amount": 2.00,
      "PaymentType": "ARCREDITPAYMENT",
      "Status": "AUTHORISED",
      "Account": { "AccountID": "136ebd08-60ea-4592-8982-be92c153b53a", "Code": "980" }
    }
  ]
}
```

You receive the array under `Payments`.

**Notes / gotchas**

- **Paging only happens if you ask for it.** Xero applies no paging until a `page` parameter is
  sent, and once you do pass `['page' => $n]` the `pagination` block is discarded by the unwrap,
  so `count($payments)` never tells you how many exist. Use
  `XeroBridge::client()->get('Payments', ['page' => $n])` when you need that metadata. There is no
  lazy `all()` here — that exists only on `invoices()`.
- Deleted payments come back with `Status: "DELETED"`. Filter on `Status=="AUTHORISED"` unless you
  want them.
- Date literals inside `where` use `DateTime(y, m, d)`, not quoted strings. `XeroDate::toFilterLiteral()`
  builds it.
- For incremental syncs, an `If-Modified-Since` header beats a `where` on `UpdatedDateUTC`. Send it
  with `XeroDate::toModifiedSinceHeader()` via `XeroBridge::request()`.

### delete()

Reverse a payment.

```php
public function delete(string $paymentId): array
```

**Request**

```php
XeroBridge::payments()->delete('99ea7f6b-c513-4066-bc27-b7c65dcd76c2');
```

Sends `POST /api.xro/2.0/Payments/99ea7f6b-c513-4066-bc27-b7c65dcd76c2` with:

```json
{ "Payments": [ { "Status": "DELETED" } ] }
```

**Response** (trimmed)

```json
{
  "Id": "3c9e7a10-2f41-4d8b-9c62-5a0f7e1b4d33",
  "Status": "OK",
  "ProviderName": "Provider Name Example",
  "DateTimeUTC": "/Date(1552431874205)/",
  "Payments": [
    {
      "PaymentID": "99ea7f6b-c513-4066-bc27-b7c65dcd76c2",
      "Date": "/Date(1543449600000+0000)/",
      "Amount": 4500.00,
      "Status": "DELETED",
      "PaymentType": "ACCRECPAYMENT",
      "UpdatedDateUTC": "/Date(1541176592690+0000)/",
      "Invoice": {
        "Type": "ACCREC",
        "InvoiceID": "046d8a6d-1ae1-4b4d-9340-5601bdf41b87",
        "Status": "AUTHORISED",
        "AmountDue": 4500.00,
        "AmountPaid": 0.00
      },
      "HasValidationErrors": false
    }
  ]
}
```

**Notes / gotchas**

- The HTTP verb is `POST`, not `DELETE`. Xero deletes by writing a status.
- The payment is not removed — it stays as an audit row with `Status: "DELETED"`, keeping its
  original `Amount`, and the invoice returns to `AUTHORISED` with its `AmountDue` restored and its
  `AmountPaid` back to `0.00`.
- **Payments created through `BatchPayments` or `Receipts` cannot be removed this way.** Xero
  rejects them with a `XeroValidationException`; delete the batch payment instead.
- This is also how you "fix" a payment, since there is no `update()`: delete, then create a
  replacement.

---

# Settings

Read-only access to the connected organisation's own configuration. **This is the resource you
need before invoices, not after.**

> ⚠️ **Account codes and tax types differ per Xero organisation.** A `200` sales account in one
> organisation may be `4000` in another. A `TaxType` of `OUTPUT2` means 15% GST in one and
> something else entirely in the next. Never hardcode either, and never copy a code out of this
> document into production — every example here is illustrative.

That is not a style preference. Hardcoding a code produces one of two outcomes: a hard
`400 Account code 'X' is not valid` (annoying, but visible), or a *successful* posting to the wrong
ledger account in an organisation that happens to have a code by that name (invisible, and found
by an accountant months later).

The correct pattern is to discover the codes per connection and cache them in your own
application:

```php
use Illuminate\Support\Facades\Cache;

$accounts = Cache::remember(
    "xero:{$key}:accounts",
    now()->addDay(),
    fn (): array => XeroBridge::connection($key)->settings()->accounts(),
);
```

Results are deliberately **not** cached inside the package. A chart of accounts changes rarely,
but the right cache key, TTL and invalidation depend entirely on the consuming application —
particularly in a multi-tenant one, where a shared key would serve one organisation's codes to
another.

Everything in this resource needs `accounting.settings` (or `accounting.settings.read`).

---

### accounts()

The organisation's full chart of accounts.

```php
public function accounts(array $query = []): array
```

**Parameters**

| Name | Type | Notes |
|---|---|---|
| `$query` | `array<string, mixed>` | Passed through as query parameters, e.g. `['where' => 'Type=="BANK"']` or `['order' => 'Name ASC']`. |

**Request**

```php
$revenue = XeroBridge::settings()->accounts([
    'where' => 'Status=="ACTIVE" AND Type=="REVENUE"',
    'order' => 'Code ASC',
]);
```

Sends `GET /api.xro/2.0/Accounts?where=...&order=Code%20ASC`.

**Response**

```json
{
  "Accounts": [
    {
      "AccountID": "ebd06280-af70-4bed-97c6-7451a454ad85",
      "Code": "091",
      "Name": "Business Savings Account",
      "Type": "BANK",
      "TaxType": "NONE",
      "EnablePaymentsToAccount": false,
      "BankAccountNumber": "0209087654321050",
      "BankAccountType": "BANK",
      "CurrencyCode": "NZD"
    },
    {
      "AccountID": "7d05a53d-613d-4eb2-a2fc-dcb6adb80b80",
      "Code": "200",
      "Name": "Sales",
      "Type": "REVENUE",
      "TaxType": "OUTPUT2",
      "Description": "Income from any normal business activity",
      "EnablePaymentsToAccount": false
    }
  ]
}
```

You receive the array under `Accounts`.

**Notes / gotchas**

- `Code` is what goes on an invoice line's `AccountCode`; `AccountID` is what goes in a payment's
  `Account.AccountID`. They are not interchangeable.
- `Class` (`ASSET`, `EQUITY`, `EXPENSE`, `LIABILITY`, `REVENUE`) is read-only and the most reliable
  way to group accounts for a picker. `Type` is far more granular.
- `SystemAccount` marks Xero's built-in accounts (`DEBTORS`, `CREDITORS`, `GST`, …). Do not post
  invoice lines to those.
- Archived accounts are returned unless you filter them out. `Status=="ACTIVE"` is almost always
  what you want.
- Filter values need Xero's double-quoted syntax. The package encodes the query string exactly
  once, so `==` reaches Xero's parser intact — do not pre-encode it yourself.

### paymentAccounts()

Only the accounts a payment can actually be applied to.

```php
public function paymentAccounts(): array
```

**Parameters**

None.

**Request**

```php
$options = collect(XeroBridge::settings()->paymentAccounts())
    ->map(fn (array $account): array => [
        'code' => $account['Code'] ?? null,
        'label' => $account['Name'] ?? '',
    ])
    ->all();
```

Sends a single `GET /api.xro/2.0/Accounts` and filters the result in PHP.

**Response**

Given a chart of accounts containing:

```json
{
  "Accounts": [
    { "Code": "090", "Name": "Business Bank Account", "Type": "BANK", "EnablePaymentsToAccount": false },
    { "Code": "200", "Name": "Sales", "Type": "REVENUE", "EnablePaymentsToAccount": false },
    { "Code": "800", "Name": "Deposits Held", "Type": "CURRLIAB", "EnablePaymentsToAccount": true }
  ]
}
```

you receive the `090` and `800` entries. `200` is dropped: it is neither a bank account nor
payment-enabled, and Xero would reject a payment against it.

**Notes / gotchas**

- The filter is applied client-side because Xero's rule is an OR across two different fields
  (`Type == "BANK"` **or** `EnablePaymentsToAccount == true`), which its `where` syntax does not
  express cleanly.
- `EnablePaymentsToAccount` is read through `FILTER_VALIDATE_BOOLEAN`, so Xero's occasional string
  `"true"` is handled.
- **No `Status` filter is applied.** Archived bank accounts will appear in this list. If that
  matters, call `accounts(['where' => 'Status=="ACTIVE"'])` and apply your own filter, or exclude
  `Status === 'ARCHIVED'` afterwards.
- This fetches the entire chart of accounts every call. Cache the result in your application.

### taxRates()

The organisation's tax rates.

```php
public function taxRates(array $query = []): array
```

**Parameters**

| Name | Type | Notes |
|---|---|---|
| `$query` | `array<string, mixed>` | e.g. `['where' => 'Status=="ACTIVE"']`, `['order' => 'Name ASC']`. |

**Request**

```php
$rates = XeroBridge::settings()->taxRates(['where' => 'Status=="ACTIVE"']);

$byType = array_column($rates, null, 'TaxType');
$lineTaxType = $byType['OUTPUT2']['TaxType'] ?? null;
```

Sends `GET /api.xro/2.0/TaxRates?where=Status%3D%3D%22ACTIVE%22`.

**Response** (trimmed)

```json
{
  "Id": "455d494d-9706-465b-b584-7086ca406b27",
  "Status": "OK",
  "ProviderName": "Provider Name Example",
  "DateTimeUTC": "/Date(1555086839841)/",
  "TaxRates": [
    {
      "Name": "15% GST on Expenses",
      "TaxType": "INPUT2",
      "ReportTaxType": "INPUT",
      "CanApplyToAssets": true,
      "CanApplyToEquity": true,
      "CanApplyToExpenses": true,
      "CanApplyToLiabilities": true,
      "CanApplyToRevenue": false,
      "DisplayTaxRate": 15.0000,
      "EffectiveRate": 15.0000,
      "Status": "ACTIVE",
      "TaxComponents": [
        { "Name": "GST", "Rate": 15.0000, "IsCompound": false, "IsNonRecoverable": false }
      ]
    },
    {
      "Name": "15% GST on Income",
      "TaxType": "OUTPUT2",
      "ReportTaxType": "OUTPUT",
      "CanApplyToRevenue": true,
      "DisplayTaxRate": 15.0000,
      "EffectiveRate": 15.0000,
      "Status": "ACTIVE",
      "TaxComponents": [
        { "Name": "GST", "Rate": 15.0000, "IsCompound": false, "IsNonRecoverable": false }
      ]
    },
    {
      "Name": "No GST",
      "TaxType": "NONE",
      "ReportTaxType": "NONE",
      "DisplayTaxRate": 0.0000,
      "EffectiveRate": 0.0000,
      "Status": "ACTIVE"
    }
  ]
}
```

You receive the array under `TaxRates`.

**Notes / gotchas**

- **`TaxType` is the code you send on a line item; `Name` is for humans.** They are unrelated
  strings. An organisation can rename "15% GST on Income" to anything without the `TaxType`
  changing, and `OUTPUT2` does not mean the same rate in two different organisations.
- `EffectiveRate` is the rate actually applied; `DisplayTaxRate` is what Xero shows. They differ
  for compound and non-recoverable components. Read `TaxComponents` when a rate is built from more
  than one part.
- `CanApplyToRevenue` / `CanApplyToExpenses` tell you which rates belong on a sales invoice versus
  a bill. Filter on them when building a picker, or someone will put an input tax on a customer
  invoice.
- Rates differ **per line**, not per invoice. In Malaysia, SST on training is 8% while education
  and rental are 6%, so one invoice can legitimately carry two `TaxType` values. This is why the
  package ships no default `tax_type` — a connection-wide default would stamp the wrong rate on a
  venue recharge. Set `tax_type` in config only if every line of every invoice on that connection
  genuinely carries the same rate.

### organisation()

The connected organisation's own details.

```php
public function organisation(): array
```

**Parameters**

None.

**Request**

```php
$org = XeroBridge::settings()->organisation();

if (($org['IsDemoCompany'] ?? false) === true) {
    throw new RuntimeException('Refusing to post live invoices to a Xero demo company.');
}
```

Sends `GET /api.xro/2.0/Organisation`.

**Response** (trimmed)

```json
{
  "Id": "27b7a645-a3ee-43c8-b2c6-a2fa7b84c8c5",
  "Status": "OK",
  "ProviderName": "Provider Name Example",
  "DateTimeUTC": "/Date(1552404447003)/",
  "Organisations": [
    {
      "OrganisationID": "b2c885a9-4bb9-4a00-9b6e-6c2bf60b1a2b",
      "Name": "Acme Sdn Bhd",
      "LegalName": "Acme Sdn Bhd",
      "PaysTax": true,
      "Version": "NZ",
      "OrganisationType": "COMPANY",
      "BaseCurrency": "NZD",
      "CountryCode": "NZ",
      "IsDemoCompany": false,
      "OrganisationStatus": "ACTIVE",
      "TaxNumber": "071-138-054",
      "FinancialYearEndDay": 31,
      "FinancialYearEndMonth": 3,
      "SalesTaxBasis": "PAYMENTS",
      "SalesTaxPeriod": "TWOMONTHS",
      "DefaultSalesTax": "Tax Exclusive",
      "PeriodLockDate": "/Date(1546214400000+0000)/",
      "EndOfYearLockDate": "/Date(1546214400000+0000)/",
      "Timezone": "NEWZEALANDSTANDARDTIME",
      "Edition": "BUSINESS",
      "ShortCode": "!mBdtL"
    }
  ]
}
```

Xero wraps this in an array even though there is exactly one. You receive the single object, or
`[]` if the array came back empty.

**Notes / gotchas**

- The best available sanity check after connecting. Assert `BaseCurrency`, `CountryCode` and
  `IsDemoCompany` match what your application expects **before** you post anything.
- `PeriodLockDate` and `EndOfYearLockDate` are the reason an otherwise valid invoice or payment can
  be rejected: a date on or before a lock date cannot be written. Check them when a backdated write
  fails for no visible reason.
- `BaseCurrency` is the organisation's, not the invoice's. Posting a foreign-currency invoice
  requires a Xero edition that supports multicurrency.
- `Version` is Xero's regional edition (`NZ`, `AU`, `UK`, `US`, `GLOBAL`), and it determines the
  UI label of the contact `TaxNumber` field discussed earlier.

### brandingThemes()

The organisation's invoice branding themes.

```php
public function brandingThemes(): array
```

**Parameters**

None.

**Request**

```php
$themes = XeroBridge::settings()->brandingThemes();

$default = $themes[0]['BrandingThemeID'] ?? null;   // SortOrder 0

XeroBridge::invoices()->create([
    'Type' => 'ACCREC',
    'Contact' => ['ContactID' => $contactId],
    'BrandingThemeID' => $default,
    'LineItems' => [
        ['Description' => 'Leadership workshop', 'Quantity' => 1, 'UnitAmount' => 4500.00],
    ],
]);
```

Sends `GET /api.xro/2.0/BrandingThemes`.

**Response**

```json
{
  "Id": "d1a1beea-bdfe-4ee4-9dbc-27226a26cd68",
  "Status": "OK",
  "ProviderName": "Xero API Partner",
  "DateTimeUTC": "/Date(1550881711906)/",
  "BrandingThemes": [
    {
      "BrandingThemeID": "dabc7637-62c1-4941-8a6e-ee44fa5090e7",
      "Name": "Standard",
      "SortOrder": 0,
      "CreatedDateUTC": "/Date(1464967643813+0000)/"
    }
  ]
}
```

You receive the array under `BrandingThemes`.

**Notes / gotchas**

- Themes are ordered by `SortOrder`, and `SortOrder: 0` is the organisation's default. There is no
  `IsDefault` flag.
- A `BrandingThemeID` belongs to one organisation. Setting it globally in config is fine for a
  single-organisation app and a bug in a multi-tenant one — put it under the connection's own block
  (`connections.<key>.branding_theme_id`) instead.
- The theme controls the invoice PDF and the emailed invoice. If a customer says the invoice "looks
  wrong", this is usually why.
- Payment services attached to a theme are a separate endpoint
  (`GET /BrandingThemes/{id}/PaymentServices`, scope `paymentservices`) that this package does not
  wrap. Reach it with `XeroBridge::request()`.

---

## A complete flow

Discover the codes, find or create the customer, invoice them, then record the payment.

```php
use Illuminate\Support\Facades\Cache;
use Peoplelogy\XeroBridge\Exceptions\XeroBridgeException;
use Peoplelogy\XeroBridge\Facades\XeroBridge;
use RuntimeException;

$key = 'default';
$xero = XeroBridge::connection($key);

try {
    // 1. Discover this organisation's codes. Cached in the application, not the package.
    $accounts = Cache::remember(
        "xero:{$key}:accounts",
        now()->addDay(),
        fn (): array => $xero->settings()->accounts(['where' => 'Status=="ACTIVE"']),
    );

    $salesCode = collect($accounts)->firstWhere('Name', 'Sales')['Code'] ?? null;
    $bankCode = $xero->settings()->paymentAccounts()[0]['Code'] ?? null;

    // 2. Find or create the customer. No write happens if it already exists.
    $contact = $xero->contacts()->firstOrCreate(
        ['EmailAddress' => 'accounts@example.test'],
        [
            'Name' => 'Acme Sdn Bhd',
            'CompanyNumber' => '202001234567',
            'ContactPersons' => [
                [
                    'FirstName' => 'Sue',
                    'LastName' => 'Johnson',
                    'EmailAddress' => 'sue.johnson@example.test',
                    'IncludeInEmails' => true,
                ],
            ],
        ],
    );

    // 3. Raise and authorise the invoice. ContactID ONLY in the Contact block.
    $invoice = $xero->invoices()->create([
        'Type' => 'ACCREC',
        'Contact' => ['ContactID' => $contact['ContactID']],
        'LineItems' => [
            [
                'Description' => 'Leadership workshop, 2 days',
                'Quantity' => 1,
                'UnitAmount' => 4500.00,
                'AccountCode' => $salesCode,
            ],
        ],
        'Status' => 'AUTHORISED',
    ]);

    // 4. Record the payment. Sue is copied because IncludeInEmails is true.
    $payment = $xero->payments()->createForInvoice(
        invoiceId: $invoice['InvoiceID'],
        amount: 4500.00,
        accountCode: $bankCode,
        extra: ['Reference' => 'FPX 20260928-0041'],
    );

    foreach ($payment['Warnings'] ?? [] as $warning) {
        report(new RuntimeException('Xero payment warning: '.($warning['Message'] ?? '')));
    }
} catch (XeroBridgeException $e) {
    // context() is safe to log: it never carries a token or the client secret.
    logger()->error('Xero flow failed.', $e->context() + ['errors' => $e->validationErrors()]);

    throw $e;
}
```

## See also

- [Invoices](02-invoices.md) — the `Contact` block rule, the filter builder, paging
- [Commands, errors and token lifecycle](05-commands-and-errors.md) — the exception hierarchy and
  rate limits
- [Recipes](06-recipes.md) — queued invoice creation, idempotency in a job
