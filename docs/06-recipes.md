# Recipes

Complete worked examples for the flows this package was built for. Each one is a
whole class or a whole test file — paste it, rename it, change the model names.

Nothing here is pseudocode. Every call is checked against the source, and several of
these flows are pinned by tests in the package itself
(`tests/Feature/BusinessFlowsTest.php`, `tests/Unit/GenericRequestTest.php`).

**Reading the samples.** A *request* block is the PHP you write, sometimes followed by
the JSON the package puts on the wire. A *response* block is what Xero actually sends
back: PascalCase keys, and .NET dates such as `/Date(1539993600000+0000)/`. The package
never rewrites a response body, so what you get is what Xero sent, unwrapped from its
collection envelope. Every credential, GUID and registration number below is a
placeholder.

| Recipe | |
|---|---|
| [1. Find or create a customer, then invoice them](#1-find-or-create-a-customer-then-invoice-them) | The whole flow, from an email address to an invoice |
| [2. Queued invoice creation, done safely](#2-queued-invoice-creation-done-safely) | Including the dedupe strategy that actually works |
| [3. Payment time: buyer details, CC list, and emailing the invoice](#3-payment-time-buyer-details-cc-list-and-emailing-the-invoice) | `CompanyNumber`, `TaxNumber`, `IncludeInEmails` |
| [4. Incremental sync of invoices](#4-incremental-sync-of-invoices) | `modifiedSince()` and `all()` |
| [5. Reconciling a payment against an invoice](#5-reconciling-a-payment-against-an-invoice) | Applying, checking, and reversing |
| [6. Testing a consuming application](#6-testing-a-consuming-application) | `Http::fake()` and `Http::preventStrayRequests()` |
| [7. Which Xero APIs you can reach](#7-which-xero-apis-you-can-reach) | Wrapped, reachable, and scope-gated |

---

## 1. Find or create a customer, then invoice them

The flow the business asked for: a customer exists in your application, may or may not
exist in Xero, and needs an invoice.

### Discover the account code first

Account codes differ per Xero organisation. A `200` sales account in one organisation
is `4000` in another, so read them rather than hardcoding them. This is a settings call,
not an invoice call, and the result changes about once a year — cache it in your own
application.

```php
<?php

namespace App\Services\Xero;

use Illuminate\Support\Facades\Cache;
use Peoplelogy\XeroBridge\Facades\XeroBridge;

class XeroChartOfAccounts
{
    /** @return list<array<string, mixed>> */
    public function revenueAccounts(): array
    {
        return Cache::remember('xero.accounts.revenue', now()->addDay(), function (): array {
            return XeroBridge::settings()->accounts(['where' => 'Type=="REVENUE"']);
        });
    }

    /** @return list<array<string, mixed>> */
    public function taxRates(): array
    {
        return Cache::remember('xero.tax-rates', now()->addDay(), function (): array {
            return XeroBridge::settings()->taxRates();
        });
    }
}
```

**Response** — `GET /Accounts?where=Type=="REVENUE"`:

```json
{
  "Accounts": [
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

`accounts()` returns the inner array — the `Accounts` envelope is already unwrapped.

### The service

```php
<?php

namespace App\Services\Xero;

use App\Models\Customer;
use Peoplelogy\XeroBridge\Facades\XeroBridge;

class CustomerInvoicer
{
    /**
     * @param  list<array<string, mixed>>  $lineItems
     * @return array<string, mixed>  the invoice as Xero returned it
     */
    public function invoice(Customer $customer, array $lineItems, string $reference): array
    {
        $contact = $this->contactFor($customer);

        $invoice = XeroBridge::invoices()->create([
            'Type' => 'ACCREC',
            'Status' => 'DRAFT',

            // ONLY ContactID. See the warning below.
            'Contact' => ['ContactID' => $contact['ContactID']],

            'Reference' => $reference,
            'Date' => now()->toDateString(),
            'DueDate' => now()->addDays(30)->toDateString(),
            'LineAmountTypes' => 'Exclusive',
            'LineItems' => $lineItems,
        ]);

        $customer->forceFill([
            'xero_contact_id' => $contact['ContactID'],
        ])->save();

        return $invoice;
    }

    /** @return array<string, mixed> */
    private function contactFor(Customer $customer): array
    {
        if ($customer->xero_contact_id !== null) {
            $known = XeroBridge::contacts()->find($customer->xero_contact_id);

            if ($known !== null) {
                return $known;
            }
        }

        // One lookup key, then a create-only PUT. Never a blind POST.
        return XeroBridge::contacts()->firstOrCreate(
            ['EmailAddress' => $customer->billing_email],
            [
                'Name' => $customer->legal_name,
                'CompanyNumber' => $customer->registration_number,
                'TaxNumber' => $customer->tax_identification_number,
            ],
        );
    }
}
```

Calling it:

```php
$invoice = app(CustomerInvoicer::class)->invoice($customer, [
    [
        'Description' => 'Leadership training, 2 days',
        'Quantity' => 1,
        'UnitAmount' => 4800,
        'TaxType' => 'OUTPUT',
    ],
], 'CCF-2026-0042');
```

### What goes on the wire

`firstOrCreate()` is two requests. First the lookup:

```
GET /api.xro/2.0/Contacts?where=EmailAddress%3D%3D%22finance%40acme.test%22
```

If that comes back with an empty `Contacts` array, the create follows as a **PUT**, which
is create-only — a duplicate name errors instead of silently overwriting a live customer
record:

```json
{
  "Contacts": [
    {
      "EmailAddress": "finance@acme.test",
      "Name": "Acme Sdn Bhd",
      "CompanyNumber": "202401012345",
      "TaxNumber": "C12345678901"
    }
  ]
}
```

Then the invoice. Note the two keys you did not write — the connection defaults filled
`AccountCode` and `CurrencyCode` because the payload left them out:

```json
{
  "Invoices": [
    {
      "Type": "ACCREC",
      "Status": "DRAFT",
      "Contact": { "ContactID": "a3675fc4-f8dd-4f03-ba5b-f1870566bcd7" },
      "Reference": "CCF-2026-0042",
      "Date": "2026-09-28",
      "DueDate": "2026-10-28",
      "LineAmountTypes": "Exclusive",
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

**Response** — trimmed to what matters:

```json
{
  "Id": "ccece84a-075c-4fcd-9073-149d4f7a91cf",
  "Status": "OK",
  "ProviderName": "Your App Name",
  "DateTimeUTC": "/Date(1790553726140)/",
  "Invoices": [
    {
      "Type": "ACCREC",
      "InvoiceID": "ed255415-e141-4150-aab7-89c3bbbb851c",
      "InvoiceNumber": "INV-0042",
      "Reference": "CCF-2026-0042",
      "Status": "DRAFT",
      "LineAmountTypes": "Exclusive",
      "Contact": {
        "ContactID": "a3675fc4-f8dd-4f03-ba5b-f1870566bcd7",
        "Name": "Acme Sdn Bhd",
        "ContactPersons": [],
        "HasValidationErrors": false
      },
      "DateString": "2026-09-28T00:00:00",
      "Date": "/Date(1790553600000+0000)/",
      "DueDateString": "2026-10-28T00:00:00",
      "DueDate": "/Date(1793145600000+0000)/",
      "LineItems": [
        {
          "LineItemID": "5f7a612b-fdcc-4d33-90fa-a9f6bc6db32f",
          "Description": "Leadership training, 2 days",
          "Quantity": 1.0000,
          "UnitAmount": 4800.00,
          "TaxType": "OUTPUT",
          "TaxAmount": 384.00,
          "LineAmount": 4800.00,
          "AccountCode": "200",
          "Tracking": []
        }
      ],
      "SubTotal": 4800.00,
      "TotalTax": 384.00,
      "Total": 5184.00,
      "AmountDue": 5184.00,
      "AmountPaid": 0.00,
      "CurrencyCode": "MYR",
      "UpdatedDateUTC": "/Date(1790553726117+0000)/",
      "UpdatedDateUTCString": "2026-09-28T00:02:06Z"
    }
  ]
}
```

`create()` returns the single element of `Invoices`, not the envelope.

### Notes and gotchas

> ### ⚠️ Only `ContactID` may appear in an invoice's `Contact` block
>
> Xero applies any other field in that block to the **contact record itself**, and
> deletes any `ContactPersons` not included in the request. That is irreversible and
> nobody notices for weeks. The package throws `UnsafeContactPayloadException` rather
> than let it happen. If you genuinely mean to update the contact while writing an
> invoice, say so: `XeroBridge::invoices()->withContactMutation()->create($payload)`.

- **`Type` is never defaulted.** Omit it and you get `InvalidInvoicePayloadException`.
  `ACCREC` is a sale, `ACCPAY` is a bill; guessing wrong is a wrong-direction ledger
  entry that Finance has to journal back out.
- **Invoice dates are your job.** The package rewrites the date on a *payment* but not
  on an invoice, so pass `Date` and `DueDate` as plain `YYYY-MM-DD` strings —
  `now()->toDateString()`, not a `Carbon` instance.
- **Contact `Name` must be unique across active contacts.** If you look up by
  `EmailAddress` but the `Name` you supply already belongs to a different contact, the
  create fails and `firstOrCreate()` rethrows `XeroValidationException`, because its
  retry re-looks-up by the key you gave it and still finds nothing.
- **`firstOrCreate()` takes exactly one lookup key**, either `EmailAddress` or `Name`.
  Anything else throws `InvalidArgumentException`.
- **Neither lookup value may contain a double quote.** Xero's filter grammar has no
  escape for it, so the package refuses rather than send a broken filter.
- **Xero does not enforce email uniqueness.** `findByEmail()` silently returns the first
  of several. If duplicates are plausible in your data, use `findAllByEmail()` and decide
  deliberately.
- **Set `TaxType` per line.** Malaysian SST is per line — training at 8%, education and
  rental at 6% — so one invoice legitimately carries two rates. Leave
  `XERO_TAX_TYPE` unset unless every line of every invoice on this connection
  carries the same rate. The package will not inject a tax type onto a line that already
  has `TaxType` **or** `TaxAmount`.

---

## 2. Queued invoice creation, done safely

The hard part of this is not the queue. It is that **Xero's idempotency key is retained
for six minutes**, and no realistic backoff stays inside six minutes.

> ### ⚠️ The idempotency key will not save you
>
> The package sends an `Idempotency-Key` on every write and it genuinely protects an
> immediate transient-network retry. After six minutes Xero has forgotten the key and
> treats the request as brand new, so **a retried job creates a second invoice**. A job
> released for 300 seconds and picked up 90 seconds late is already outside the window
> on its second attempt.
>
> Your defence is persisting the returned `InvoiceID` and making the job a no-op once it
> is set. Treat the key as an optimisation, not a guarantee.

### The schema

```php
Schema::table('orders', function (Blueprint $table) {
    // Nullable, and UNIQUE. The unique index is what turns a race between two
    // workers into a database error instead of two invoices in Xero.
    $table->string('xero_invoice_id', 64)->nullable()->unique();
    $table->string('xero_invoice_number', 64)->nullable();
    $table->text('xero_error')->nullable();
});
```

### The job

```php
<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Peoplelogy\XeroBridge\Exceptions\XeroRateLimitException;
use Peoplelogy\XeroBridge\Exceptions\XeroServiceUnavailableException;
use Peoplelogy\XeroBridge\Exceptions\XeroValidationException;
use Peoplelogy\XeroBridge\Facades\XeroBridge;
use Peoplelogy\XeroBridge\Filters\InvoiceFilter;

class CreateXeroInvoice implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly int $orderId) {}

    /** Stops a second copy of this job running while the first is in flight. */
    public function uniqueId(): string
    {
        return 'xero-invoice-'.$this->orderId;
    }

    public function handle(): void
    {
        $order = Order::findOrFail($this->orderId);

        // 1. Already invoiced. THIS is the dedupe that survives a retry.
        if ($order->xero_invoice_id !== null) {
            return;
        }

        // 2. Recover from a lost response. A previous attempt may have reached
        //    Xero, been committed there, and died before step 4 — a connection
        //    timeout after Xero wrote the row looks identical to a failure.
        if (($recovered = $this->findInXero($order)) !== null) {
            $this->remember($order, $recovered);

            return;
        }

        try {
            // 3. A deterministic key, so an immediate in-process retry of this
            //    same logical operation reuses it. Max 128 characters; the
            //    package throws if you exceed that.
            $invoice = XeroBridge::invoices()->create(
                $this->payload($order),
                "order-{$order->id}",
            );
        } catch (XeroRateLimitException|XeroServiceUnavailableException $e) {
            // Never sleep a worker for a daily rate limit — that wait can be
            // hours. Hand the job back to the queue instead.
            $this->release($e->retryAfter() ?? 300);

            return;
        } catch (XeroValidationException $e) {
            // Xero rejected the payload. Retrying will produce the same answer,
            // so record why and stop.
            $order->forceFill([
                'xero_error' => implode('; ', $e->validationErrors()),
            ])->save();

            $this->fail($e);

            return;
        }

        // 4. Persist immediately, before anything else can throw.
        $this->remember($order, $invoice);
    }

    /** @return array<string, mixed>|null */
    private function findInXero(Order $order): ?array
    {
        $matches = XeroBridge::invoices()->list(
            InvoiceFilter::make()
                ->type('ACCREC')
                ->reference($order->reference)
                ->createdByMyApp()
                ->statuses(['DRAFT', 'SUBMITTED', 'AUTHORISED', 'PAID'])
        );

        return $matches[0] ?? null;
    }

    /** @param array<string, mixed> $invoice */
    private function remember(Order $order, array $invoice): void
    {
        $order->forceFill([
            'xero_invoice_id' => $invoice['InvoiceID'],
            'xero_invoice_number' => $invoice['InvoiceNumber'] ?? null,
            'xero_error' => null,
        ])->save();
    }

    /** @return array<string, mixed> */
    private function payload(Order $order): array
    {
        return [
            'Type' => 'ACCREC',
            'Status' => 'DRAFT',
            'Contact' => ['ContactID' => $order->customer->xero_contact_id],
            'Reference' => $order->reference,
            'Date' => $order->issued_on->toDateString(),
            'DueDate' => $order->due_on->toDateString(),
            'LineAmountTypes' => 'Exclusive',
            'LineItems' => $order->lines->map(fn ($line) => [
                'Description' => $line->description,
                'Quantity' => $line->quantity,
                'UnitAmount' => $line->unit_price,
                'TaxType' => $line->tax_type,
            ])->all(),
        ];
    }
}
```

### Persisting from the event instead

If several call sites create invoices, listen for the event rather than repeating step 4:

```php
use Illuminate\Support\Facades\Event;
use Peoplelogy\XeroBridge\Events\InvoiceCreated;

Event::listen(function (InvoiceCreated $event) {
    if ($event->invoiceId() === null) {
        return;
    }

    Order::where('reference', $event->invoice['Reference'] ?? '')
        ->whereNull('xero_invoice_id')
        ->update([
            'xero_invoice_id' => $event->invoiceId(),
            'xero_invoice_number' => $event->invoiceNumber(),
        ]);
});
```

`InvoiceCreated` carries `connectionKey`, the full `invoice` array and the
`idempotencyKey` that was used. `createMany()` dispatches it once per element Xero
actually accepted — successes and warnings, never failures.

### Notes and gotchas

- **Step 2 depends on `Reference` being unique per order.** If two orders can share a
  reference, filter on something that cannot collide, or drop step 2 and accept that a
  lost response means a manual check.
- **`createdByMyApp()` narrows the recovery lookup** to invoices your own app created,
  which matters in an organisation where staff also raise invoices by hand.
- **A `list()` with no status filter includes `VOIDED` and `DELETED` invoices.** In a
  recovery lookup that would make you "recover" an invoice somebody deliberately voided,
  hence the explicit `statuses()` call.
- **Writes are only retried when an idempotency key is present.** With
  `XERO_HTTP_IDEMPOTENCY=false` the package refuses to retry any `POST` or `PUT` at all,
  because a retried write that actually succeeded is a duplicate invoice.
- **Bulk creation is judged element by element.** `createMany()` sends
  `summarizeErrors=false`, so Xero answers `200` even when some rows failed. Read the
  `BatchResult`: `successful()`, `warned()`, `failed()`, `errorMessages()`,
  `submittedAt($index)` for "row 3 failed because…", or `throwIfAnyFailed()` if you want
  it to be an exception after all.

---

## 3. Payment time: buyer details, CC list, and emailing the invoice

The buyer supplies their business registration number and tax identification number at
payment time, names the people who should be copied, and expects Xero to send the
invoice. Three steps, in this order.

```php
<?php

namespace App\Services\Xero;

use App\Models\Order;
use Peoplelogy\XeroBridge\Facades\XeroBridge;

class SendInvoiceToBuyer
{
    /**
     * @param  list<array{name: string, email: string}>  $copyTo
     */
    public function __invoke(Order $order, BuyerDetails $details, array $copyTo = []): void
    {
        // (a) Capture what the buyer just gave us, onto the CONTACT record.
        XeroBridge::contacts()->update($order->customer->xero_contact_id, [
            // Xero: "Company registration number. Max 50 char."
            'CompanyNumber' => $details->registrationNumber,

            // Xero's TIN field: the ABN in Australia, GST Number in New Zealand,
            // VAT Number in the UK, Tax ID Number elsewhere. Max 50 characters.
            'TaxNumber' => $details->taxIdentificationNumber,

            'Addresses' => [[
                'AddressType' => 'STREET',
                'AddressLine1' => $details->line1,
                'AddressLine2' => $details->line2,
                'City' => $details->city,
                'Region' => $details->state,
                'PostalCode' => $details->postcode,
                'Country' => $details->country,
            ]],

            // The ONLY way to copy anyone. See below.
            'ContactPersons' => $this->contactPersons($details, $copyTo),
        ]);

        // (b) Xero only emails an ACCREC invoice that is SUBMITTED, AUTHORISED
        //     or PAID, so approve it first.
        XeroBridge::invoices()->authorise($order->xero_invoice_id);

        // (c) Ask Xero to send it. Returns 204 No Content; this method returns void.
        XeroBridge::invoices()->email($order->xero_invoice_id);
    }

    /**
     * Xero allows a maximum of FIVE ContactPersons. The list you send replaces
     * the stored one wholesale, so build the whole list every time.
     *
     * @param  list<array{name: string, email: string}>  $copyTo
     * @return list<array<string, mixed>>
     */
    private function contactPersons(BuyerDetails $details, array $copyTo): array
    {
        $people = [[
            'FirstName' => $details->contactFirstName,
            'LastName' => $details->contactLastName,
            'EmailAddress' => $details->contactEmail,
            'IncludeInEmails' => true,
        ]];

        foreach (array_slice($copyTo, 0, 4) as $person) {
            [$first, $last] = array_pad(explode(' ', $person['name'], 2), 2, '');

            $people[] = [
                'FirstName' => $first,
                'LastName' => $last,
                'EmailAddress' => $person['email'],
                'IncludeInEmails' => true,
            ];
        }

        return $people;
    }
}
```

### How copying people actually works

This is the part that surprises everyone, so it is worth stating plainly.

> ### ⚠️ Xero's email endpoint accepts an EMPTY body
>
> `POST /Invoices/{InvoiceID}/Email` takes no parameters at all. Xero's own OpenAPI
> specification types the request body as `RequestEmpty` with the example `{}`. There is
> no `to`, no `cc`, no `bcc`, no `subject`, no `body`, no `replyTo`. The package sends an
> empty body — `[]` on the wire — and there is a test asserting it stays empty.

Which means:

- **`IncludeInEmails` is the only CC mechanism.** Xero sends the invoice to the primary
  `EmailAddress` of the contact on the invoice, plus every `ContactPerson` on that
  contact whose `IncludeInEmails` is `true`.
- **It is per contact, not per send.** There is no way to copy somebody on one invoice
  only. Changing who gets copied means updating the contact record, and that change
  applies to every subsequent invoice for that customer until you change it back.
- **The template and the sender are not yours to control.** Subject and body come from
  the organisation's own email template, configured in Xero's UI. The sender is the user
  who authorised the app connection — not your application, not the invoice's owner.
- **You cannot read back what was sent.** There is no API for the rendered email. If you
  need an audit trail, log the `ContactPersons` you set and the moment you called
  `email()`.

If none of that fits, the alternative is to send the mail yourself: fetch
`XeroBridge::invoices()->pdf($invoiceId)` for the bytes, or
`XeroBridge::invoices()->onlineUrl($invoiceId)` for the public "view online" link, build
your own Mailable, and call `XeroBridge::invoices()->markAsSent($invoiceId)` so Xero's UI
still shows the invoice as sent.

### What goes on the wire

The contact update, `POST /Contacts/{ContactID}`:

```json
{
  "Contacts": [
    {
      "CompanyNumber": "202401012345",
      "TaxNumber": "C12345678901",
      "Addresses": [
        {
          "AddressType": "STREET",
          "AddressLine1": "Level 10, Menara ABC",
          "AddressLine2": "Jalan Example",
          "City": "Kuala Lumpur",
          "Region": "Wilayah Persekutuan",
          "PostalCode": "50450",
          "Country": "Malaysia"
        }
      ],
      "ContactPersons": [
        {
          "FirstName": "Siti",
          "LastName": "Rahman",
          "EmailAddress": "ap@acme.test",
          "IncludeInEmails": true
        },
        {
          "FirstName": "Internal",
          "LastName": "Copy",
          "EmailAddress": "billing@example.test",
          "IncludeInEmails": true
        }
      ]
    }
  ]
}
```

**Response** — trimmed:

```json
{
  "Id": "5c83b115-a6e8-4f2a-877f-ba63d009235b",
  "Status": "OK",
  "ProviderName": "Your App Name",
  "DateTimeUTC": "/Date(1790553726164)/",
  "Contacts": [
    {
      "ContactID": "8138a266-fb42-49b2-a104-014b7045753d",
      "ContactStatus": "ACTIVE",
      "Name": "Acme Sdn Bhd",
      "CompanyNumber": "202401012345",
      "TaxNumber": "C12345678901",
      "EmailAddress": "finance@acme.test",
      "Addresses": [
        {
          "AddressType": "STREET",
          "AddressLine1": "Level 10, Menara ABC",
          "City": "Kuala Lumpur",
          "Region": "Wilayah Persekutuan",
          "PostalCode": "50450",
          "Country": "Malaysia"
        }
      ],
      "ContactPersons": [
        {
          "FirstName": "Siti",
          "LastName": "Rahman",
          "EmailAddress": "ap@acme.test",
          "IncludeInEmails": true
        }
      ],
      "IsCustomer": true,
      "UpdatedDateUTC": "/Date(1790553726193+0000)/",
      "HasValidationErrors": false
    }
  ]
}
```

Then the approval, `POST /Invoices/{InvoiceID}` with nothing but a status:

```json
{ "Invoices": [ { "Status": "AUTHORISED" } ] }
```

Then the send, `POST /Invoices/{InvoiceID}/Email` with an empty JSON body (`[]` on the
wire), which answers `204 No Content` and an empty body.

### Notes and gotchas

- **`authorise()` costs two requests.** It reads the invoice first so it can refuse an
  illegal transition locally, with a message naming the current status. If you are
  certain of the status and want one request, `XeroBridge::invoices()->update($id,
  ['Status' => 'AUTHORISED'])` does the same write without the preflight.
- **Approving an already-`AUTHORISED` invoice is legal**, so this flow is safe to re-run.
  Approving a `PAID` or `VOIDED` one is not: the preflight `GET` is followed by
  `InvalidInvoiceTransitionException`, so the write is never sent.
- **Omitting an element from a contact update preserves it**; supplying one replaces it.
  That is why `contactPersons()` above rebuilds the whole list rather than appending.
- **Five `ContactPersons` is the hard limit.**
- **There is a daily email cap per organisation**, counting mail sent from the Xero UI
  as well as the API: 1,000 a day for paying organisations, 20 for trial organisations,
  and **0 for demo companies**. Check `XeroBridge::settings()->organisation()` for
  `IsDemoCompany` before you wonder why nothing arrives.
- **Failures come back as `400`, not a useful status.** An invalid invoice status, an
  organisation on a plan that cannot send email, and a hit email cap all surface as
  `XeroValidationException` or `XeroRequestException` — read `$e->getMessage()`.
- **`email()` returns `void`.** A `204` has nothing to unwrap. The package translates it
  to an empty array internally so a successful send is never mistaken for a decode
  failure.

---

## 4. Incremental sync of invoices

`modifiedSince()` sets the `If-Modified-Since` **header**, and `all()` walks Xero's
pagination object and hands you a `LazyCollection` — so a hundred thousand invoices
never land in memory at once.

```php
<?php

namespace App\Console\Commands;

use App\Models\XeroInvoiceMirror;
use App\Models\XeroSyncState;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Peoplelogy\XeroBridge\Exceptions\XeroRateLimitException;
use Peoplelogy\XeroBridge\Facades\XeroBridge;
use Peoplelogy\XeroBridge\Filters\InvoiceFilter;
use Peoplelogy\XeroBridge\Support\XeroDate;

class SyncXeroInvoices extends Command
{
    protected $signature = 'xero:sync-invoices
                            {--connection= : The connection key, default when omitted}
                            {--since= : Override the stored watermark, e.g. 2026-01-01}';

    protected $description = 'Pull invoices changed in Xero since the last successful run';

    public function handle(): int
    {
        $key = $this->option('connection') ?: config('xero-bridge.default_connection');
        $state = XeroSyncState::firstOrCreate(['connection' => $key]);

        // Take the NEW watermark before the first request, not after the last
        // one: anything changed mid-run must be picked up next time. The five
        // minutes of deliberate overlap absorbs clock skew, and costs nothing
        // because the upsert below is idempotent.
        $newWatermark = CarbonImmutable::now()->subMinutes(5);

        $since = $this->option('since')
            ? CarbonImmutable::parse($this->option('since'))
            : ($state->invoices_synced_at ?? CarbonImmutable::now()->subYear());

        $filter = InvoiceFilter::make()
            ->type('ACCREC')
            ->modifiedSince($since)
            ->orderBy('UpdatedDateUTC')
            ->pageSize(100);

        $this->info("Syncing invoices modified since {$since->toIso8601String()}…");

        $count = 0;

        try {
            XeroBridge::connection($key)
                ->invoices()
                ->all($filter)
                ->each(function (array $invoice) use (&$count): void {
                    $this->mirror($invoice);
                    $count++;
                });
        } catch (XeroRateLimitException $e) {
            // Partial progress is safe to keep: the watermark is only advanced
            // on a clean run, so the next run repeats from where this started.
            $this->error("Rate limited after {$count} invoices; retry in {$e->retryAfter()}s.");

            return self::FAILURE;
        }

        $state->forceFill(['invoices_synced_at' => $newWatermark])->save();

        $this->info("Synced {$count} invoices.");

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $invoice */
    private function mirror(array $invoice): void
    {
        XeroInvoiceMirror::updateOrCreate(
            ['xero_invoice_id' => $invoice['InvoiceID']],
            [
                'invoice_number' => $invoice['InvoiceNumber'] ?? null,
                'reference' => $invoice['Reference'] ?? null,
                'status' => $invoice['Status'] ?? null,
                'contact_id' => $invoice['Contact']['ContactID'] ?? null,
                'total' => $invoice['Total'] ?? 0,
                'amount_due' => $invoice['AmountDue'] ?? 0,
                'amount_paid' => $invoice['AmountPaid'] ?? 0,
                'currency_code' => $invoice['CurrencyCode'] ?? null,

                // .NET dates. XeroDate::from() prefers the ISO sibling field
                // (DateString, UpdatedDateUTCString) when Xero supplied one.
                'issued_on' => XeroDate::from($invoice, 'Date'),
                'due_on' => XeroDate::from($invoice, 'DueDate'),
                'updated_in_xero_at' => XeroDate::from($invoice, 'UpdatedDateUTC'),
            ],
        );
    }
}
```

Schedule it:

```php
Schedule::command('xero:sync-invoices')->hourly()->withoutOverlapping();
```

### What goes on the wire

Two requests for a two-page result, each carrying the header:

```
GET /api.xro/2.0/Invoices?where=Type%3D%3D%22ACCREC%22&order=UpdatedDateUTC&page=1&pageSize=100
If-Modified-Since: 2026-01-01T00:00:00

GET /api.xro/2.0/Invoices?where=Type%3D%3D%22ACCREC%22&order=UpdatedDateUTC&page=2&pageSize=100
If-Modified-Since: 2026-01-01T00:00:00
```

**Response** — the pagination object is what `all()` reads to know when to stop:

```json
{
  "Id": "900c500b-e83c-4ce2-902a-b8ba04751748",
  "Status": "OK",
  "ProviderName": "Your App Name",
  "DateTimeUTC": "/Date(1790553726230)/",
  "pagination": { "page": 1, "pageSize": 100, "pageCount": 2, "itemCount": 143 },
  "Invoices": [
    {
      "Type": "ACCREC",
      "InvoiceID": "d4956132-ed94-4dd7-9eaa-aa22dfdf06f2",
      "InvoiceNumber": "INV-0001",
      "Reference": "CCF-2026-0001",
      "Payments": [],
      "AmountDue": 0.00,
      "AmountPaid": 0.00,
      "SentToContact": true,
      "Contact": {
        "ContactID": "a3675fc4-f8dd-4f03-ba5b-f1870566bcd7",
        "Name": "Acme Sdn Bhd",
        "HasValidationErrors": false
      },
      "DateString": "2026-01-20T00:00:00",
      "Date": "/Date(1768867200000+0000)/",
      "DueDateString": "2026-02-19T00:00:00",
      "DueDate": "/Date(1771459200000+0000)/",
      "Status": "VOIDED",
      "LineAmountTypes": "Exclusive",
      "LineItems": [],
      "SubTotal": 40.00,
      "TotalTax": 0.00,
      "Total": 40.00,
      "CurrencyCode": "MYR",
      "UpdatedDateUTC": "/Date(1790467326160+0000)/",
      "UpdatedDateUTCString": "2026-09-27T00:02:06Z"
    }
  ]
}
```

### Notes and gotchas

- **`modifiedSince()` is a header, not a query parameter.** Passing `modifiedSince` as a
  query parameter — which is the obvious guess — makes Xero silently ignore it and return
  the entire unfiltered set. The filter object handles this for you; if you drop to
  `XeroBridge::request()`, set `If-Modified-Since` yourself, formatted UTC to the second
  with **no trailing `Z`**: `2026-01-01T00:00:00`.
- **Do not put `UpdatedDateUTC` in a `where` clause.** `dateBetween()` throws if you try.
  Xero recommends the header, and a `where` on that field is markedly slower.
- **Advance the watermark from the run's start, not from the newest row you saw.** A row
  written while you were on page 1 can sort onto a page you have already passed.
- **A changed invoice includes one that was voided or deleted.** That is a feature — it
  is how a mirror learns about cancellations — but a mirror that assumes every row is
  live will quietly resurrect them. Key on `Status`.
- **`summaryOnly()` is much faster and drops real data.** It excludes exactly `Payments`,
  `HasAttachments`, `LineItems` and `CISDeduction`, and forces pagination on. Use it for
  a header-level mirror; do not use it if you need line items.
- **`all()` stops at 1,000 pages** and throws `XeroBridgeException` rather than loop
  forever. The check runs after each page and before the last-page check, so a result
  that is *exactly* 1,000 pages throws even though it had finished. Raise the limit if a
  run genuinely needs more: `all($filter, maxPages: 5000)`.
- **Page size is capped at 1,000.** Xero clamps an out-of-range value silently, which
  makes a typo'd `5000` look like it worked, so the filter rejects it with an
  `InvalidArgumentException` instead.
- **Mind the rate limit.** One request per page against 60 calls per minute per
  organisation. A 143-invoice sync is 2 calls; a 60,000-invoice backfill at
  `pageSize(1000)` is 60 calls and will brush the limit. The package retries `429`
  automatically, honouring `Retry-After`.
- **Prefer `ids()` over an `or` filter.** Xero only optimises `or` for `InvoiceId`, so an
  explicit ID list is dramatically faster than the equivalent `where`.

---

## 5. Reconciling a payment against an invoice

```php
<?php

namespace App\Services\Xero;

use Illuminate\Support\Facades\Log;
use Peoplelogy\XeroBridge\Facades\XeroBridge;
use RuntimeException;

class PaymentReconciler
{
    /**
     * Apply a received payment to a Xero invoice.
     *
     * @return array<string, mixed>  the payment as Xero returned it
     */
    public function apply(
        string $invoiceId,
        float $amount,
        string $paidOn,
        string $bankAccountCode,
    ): array {
        $invoice = XeroBridge::invoices()->find($invoiceId);

        if ($invoice === null) {
            throw new RuntimeException("Invoice [{$invoiceId}] is not in Xero.");
        }

        // Xero will only accept a payment against an approved invoice.
        if (($invoice['Status'] ?? null) !== 'AUTHORISED') {
            throw new RuntimeException(sprintf(
                'Invoice [%s] is %s; a payment can only be applied to an AUTHORISED invoice.',
                $invoiceId,
                $invoice['Status'] ?? 'unknown',
            ));
        }

        $due = (float) ($invoice['AmountDue'] ?? 0);

        if ($amount > $due) {
            throw new RuntimeException(sprintf(
                'Payment of %.2f exceeds the %.2f outstanding on invoice [%s].',
                $amount,
                $due,
                $invoiceId,
            ));
        }

        $payment = XeroBridge::payments()->createForInvoice(
            $invoiceId,
            $amount,
            $bankAccountCode,
            $paidOn,
        );

        // A CurrencyRate warning arrives on a SUCCESSFUL 200. The package logs
        // it; check it here too if a wrong foreign-currency amount matters.
        foreach ($payment['Warnings'] ?? [] as $warning) {
            Log::warning('Xero accepted the payment with a warning.', [
                'payment_id' => $payment['PaymentID'] ?? null,
                'message' => $warning['Message'] ?? null,
            ]);
        }

        return $payment;
    }

    /**
     * Reverse a payment, then void the invoice it was against.
     *
     * The order matters: an invoice with a payment applied cannot be voided.
     */
    public function reverseAndVoid(string $paymentId, string $invoiceId): void
    {
        XeroBridge::payments()->delete($paymentId);
        XeroBridge::invoices()->void($invoiceId);
    }

    /**
     * The accounts Xero will actually accept a payment against.
     *
     * @return list<array<string, mixed>>
     */
    public function payableAccounts(): array
    {
        return XeroBridge::settings()->paymentAccounts();
    }
}
```

### What goes on the wire

`createForInvoice()` builds the payload for you. `POST /Payments`:

```json
{
  "Payments": [
    {
      "Invoice": { "InvoiceID": "c7c37b83-ac95-45ea-88ba-8ad83a5f22fe" },
      "Account": { "Code": "090" },
      "Amount": 5184,
      "Date": "2026-09-28"
    }
  ]
}
```

**Response** — trimmed; note that Xero echoes the whole invoice back, already updated:

```json
{
  "Id": "83b5715a-6a77-4c16-b5b8-2da08b5fde44",
  "Status": "OK",
  "ProviderName": "Your App Name",
  "DateTimeUTC": "/Date(1790553726716)/",
  "Payments": [
    {
      "PaymentID": "61ed71fc-01bf-4eb8-8419-8a18789ff45f",
      "Date": "/Date(1790553600000+0000)/",
      "Amount": 5184.00,
      "BankAmount": 5184.00,
      "CurrencyRate": 1.000000,
      "PaymentType": "ACCRECPAYMENT",
      "Status": "AUTHORISED",
      "IsReconciled": false,
      "HasAccount": true,
      "Account": {
        "AccountID": "5690f1e8-1d02-4893-90c2-ee1a69eff942",
        "Code": "090",
        "Name": "Business Bank Account"
      },
      "Invoice": {
        "Type": "ACCREC",
        "InvoiceID": "c7c37b83-ac95-45ea-88ba-8ad83a5f22fe",
        "InvoiceNumber": "INV-0042",
        "Status": "PAID",
        "AmountDue": 0.00,
        "AmountPaid": 5184.00,
        "Total": 5184.00,
        "CurrencyCode": "MYR"
      },
      "UpdatedDateUTC": "/Date(1790553726623+0000)/",
      "UpdatedDateUTCString": "2026-09-28T00:02:06Z"
    }
  ]
}
```

Xero flipped the invoice to `PAID` itself. You do not transition it.

### Notes and gotchas

- **Not every account can receive a payment.** It must be of type `BANK`, or have
  "enable payments to this account" switched on. `settings()->paymentAccounts()` applies
  that rule — it is an `OR` across two different fields, so it is filtered client-side.
  Passing a bad account gives a validation error that does not explain itself.
- **`payment_account_code` is not in the shipped config.** `createForInvoice()` falls
  back to that key on the connection defaults, so either pass the code explicitly (as the
  recipe above does) or add it to your `config/xero-bridge.php` under
  `connections.default`. Without either you get an `InvalidArgumentException` naming
  `settings()->paymentAccounts()` as the way to find a valid one.
  > `XeroBridge::withDefaults(['payment_account_code' => '090'])` also works, but only if
  > nothing has already resolved `payments()` for that connection earlier in the same
  > process — resources are memoised per connection key, so a later `withDefaults()` is
  > silently ignored. Passing the argument is reliable; this is not.
- **Dates: Xero takes `YYYY-MM-DD` on write and returns `/Date(…)/` on read.** The
  package converts `Date` for you on a payment, so a payment read back and re-posted does
  not fail with a message that never mentions dates. It does not do this for invoices.
- **Payments cannot be modified, only created and deleted.** There is deliberately no
  `update()`. `delete()` posts `Status: DELETED`.
- **Payments created through `BatchPayments` or `Receipts` cannot be removed this way.**
  Xero rejects it.
- **One payment, one invoice.** `POST /Payments` does not settle several invoices at
  once. Passing an `Invoices` key throws `XeroBridgeException` pointing you at
  `/BatchPayments` via `XeroBridge::request()`.
- **A `PAID` invoice can become nothing at all** — not `VOIDED`, not `DELETED`. That is
  why `reverseAndVoid()` deletes the payment first. `void()` preflights for exactly this
  and throws `InvoiceCannotBeVoidedException` naming the blocking payment IDs, because
  Xero's own error for the case is opaque.
- **`invoices()->void()` and `invoices()->delete()` both preflight by default.** Pass
  `false` as the second argument to skip the extra `GET` if you already know the status.
  `payments()->delete()` takes no such argument — it never preflights.

---

## 6. Testing a consuming application

Your suite must never reach `api.xero.com`. Two calls on the `Http` facade do the work:
`Http::preventStrayRequests()` turns any unstubbed request into a thrown exception, and
`Http::fake()` supplies the answers.

```php
<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\Xero\CustomerInvoicer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Peoplelogy\XeroBridge\Exceptions\XeroRateLimitException;
use Peoplelogy\XeroBridge\Facades\XeroBridge;
use Peoplelogy\XeroBridge\Models\XeroConnection;
use Tests\TestCase;

class CustomerInvoicerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Anything not explicitly faked below now throws instead of quietly
        // reaching Xero. Put this in your base TestCase.
        Http::preventStrayRequests();

        $this->connectedToXero();
    }

    /** A healthy connection row. Nothing here is a real credential. */
    private function connectedToXero(): XeroConnection
    {
        return XeroConnection::create([
            'key' => 'default',
            'tenant_id' => 'test-tenant-id',
            'connection_id' => 'test-connection-id',
            'tenant_name' => 'Test Organisation',
            'tenant_type' => 'ORGANISATION',
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_at' => now()->addMinutes(30),
            'scopes' => 'openid profile email offline_access '
                .'accounting.invoices accounting.contacts accounting.settings',
        ]);
    }

    public function test_it_reuses_an_existing_xero_contact(): void
    {
        Http::fake([
            'api.xero.com/api.xro/2.0/Contacts*' => Http::response([
                'Contacts' => [[
                    'ContactID' => '8138a266-fb42-49b2-a104-014b7045753d',
                    'Name' => 'Acme Sdn Bhd',
                ]],
            ]),
            'api.xero.com/api.xro/2.0/Invoices*' => Http::response([
                'Invoices' => [[
                    'InvoiceID' => 'ed255415-e141-4150-aab7-89c3bbbb851c',
                    'InvoiceNumber' => 'INV-0042',
                    'Status' => 'DRAFT',
                ]],
            ]),
        ]);

        $customer = Customer::factory()->create([
            'billing_email' => 'finance@acme.test',
            'xero_contact_id' => null,
        ]);

        $invoice = app(CustomerInvoicer::class)->invoice($customer, [[
            'Description' => 'Leadership training, 2 days',
            'Quantity' => 1,
            'UnitAmount' => 4800,
            'TaxType' => 'OUTPUT',
        ]], 'CCF-2026-0042');

        $this->assertSame('INV-0042', $invoice['InvoiceNumber']);

        // The contact already existed, so nothing was written to /Contacts.
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/Contacts')
            && in_array($r->method(), ['POST', 'PUT'], true));

        // Assert what the package actually sent, not just that it sent something.
        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), '/Invoices')) {
                return false;
            }

            $sent = $r->data()['Invoices'][0];

            return $sent['Contact'] === ['ContactID' => '8138a266-fb42-49b2-a104-014b7045753d']
                && $sent['Reference'] === 'CCF-2026-0042'
                && $sent['LineItems'][0]['AccountCode'] === '200';
        });
    }

    public function test_it_creates_the_contact_when_it_is_new(): void
    {
        Http::fake([
            'api.xero.com/api.xro/2.0/Contacts*' => Http::sequence()
                ->push(['Contacts' => []], 200)                                     // lookup misses
                ->push(['Contacts' => [['ContactID' => 'new-contact-id']]], 200),   // the PUT
            'api.xero.com/api.xro/2.0/Invoices*' => Http::response([
                'Invoices' => [['InvoiceID' => 'new-invoice-id', 'InvoiceNumber' => 'INV-0043']],
            ]),
        ]);

        $customer = Customer::factory()->create(['xero_contact_id' => null]);

        app(CustomerInvoicer::class)->invoice($customer, [], 'CCF-2026-0043');

        // Create-only PUT, never POST.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/Contacts')
            && $r->method() === 'PUT');
    }

    public function test_a_daily_rate_limit_surfaces_rather_than_blocking(): void
    {
        Http::fake([
            'api.xero.com/*' => Http::response('', 429, [
                'Retry-After' => '3600',
                'X-Rate-Limit-Problem' => 'day',
            ]),
        ]);

        try {
            XeroBridge::invoices()->find('any-invoice-id');
            $this->fail('Expected XeroRateLimitException.');
        } catch (XeroRateLimitException $e) {
            $this->assertSame(3600, $e->retryAfter());
            $this->assertSame('day', $e->limitProblem());
        }
    }
}
```

### Faking the email endpoint

`POST /Invoices/{id}/Email` answers `204` with an empty body, and `authorise()` reads the
invoice before writing, so both need a stub:

```php
Http::fake([
    // Specific patterns FIRST. See below.
    'api.xero.com/api.xro/2.0/Invoices/inv-1/Email' => Http::response('', 204),
    'api.xero.com/api.xro/2.0/Invoices/*' => Http::response([
        'Invoices' => [['InvoiceID' => 'inv-1', 'Status' => 'AUTHORISED']],
    ]),
]);

XeroBridge::invoices()->authorise('inv-1');
XeroBridge::invoices()->email('inv-1');

Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'Invoices/inv-1/Email')
    && $r->method() === 'POST'
    && in_array($r->body(), ['', '[]', '{}'], true));
```

### Notes and gotchas

- **The first declared pattern that matches wins.** Declare `Invoices/inv-1/Email` before
  `Invoices/*`, or the wildcard swallows it and your `204` stub is never used.
- **Patterns must include the API path.** The package's base URL is
  `https://api.xero.com/api.xro/2.0/`, so `'api.xero.com/Invoices*'` matches nothing. A
  bare `'api.xero.com/*'` is fine as a catch-all.
- **Set `APP_KEY` in `phpunit.xml`.** `access_token` and `refresh_token` use the
  `encrypted` cast and cannot be written without one.
- **Remember the preflight reads.** `authorise()`, `void()` and `delete()` each `GET` the
  invoice first, so a test that only stubs the `POST` fails on the read. Pass `false` as
  `void()`'s and `delete()`'s second argument to skip it.
- **Stub the settings calls too** if the code under test reads the chart of accounts —
  `Accounts`, `TaxRates`, `Organisation` and `BrandingThemes` are four separate endpoints.
- **Do not fake a short `Retry-After` on a `429`.** The package honours it and will sleep
  inline for anything up to `XERO_HTTP_RETRY_MAX_MS` (30 seconds by default), which makes
  the test take 30 seconds. Either fake a value above that ceiling, as the test above
  does, or set `config(['xero-bridge.http.retries' => 1])` for the test.
- **Never assert on the request headers.** They carry the bearer token. Assert on
  `$r->url()`, `$r->method()` and `$r->data()`.
- **`Http::assertSentCount()` is worth using.** It is how you prove `firstOrCreate()` did
  not write when the contact already existed.

---

## 7. Which Xero APIs you can reach

### Wrapped by this package

Four resources, with guards, defaults and typed errors:

| Accessor | Endpoints | Scope |
|---|---|---|
| `XeroBridge::invoices()` | `Invoices`, plus `/Email`, `/OnlineInvoice`, PDF | `accounting.invoices` |
| `XeroBridge::contacts()` | `Contacts` | `accounting.contacts` |
| `XeroBridge::payments()` | `Payments` | `accounting.payments` |
| `XeroBridge::settings()` | `Accounts`, `TaxRates`, `Organisation`, `BrandingThemes` | `accounting.settings` |

### Everything else in the Accounting API

`XeroBridge::request()` takes a relative path, prefixes the Accounting base URL, attaches
the access token and the `Xero-tenant-id` header, and returns the decoded body. Retries,
token refresh, rate-limit handling and error mapping all still apply.

```php
// Reachable on the DEFAULT scopes, because accounting.invoices covers credit
// notes, quotes, purchase orders, repeating invoices, linked transactions and items.
$creditNotes = XeroBridge::request('GET', 'CreditNotes');

$quotes = XeroBridge::request('GET', 'Quotes', [], [
    'If-Modified-Since' => '2026-01-01T00:00:00',
]);

// accounting.payments covers batch payments, overpayments and prepayments.
$batch = XeroBridge::request('POST', 'BatchPayments', [
    'BatchPayments' => [[
        'Account' => ['Code' => '090'],
        'Payments' => [
            ['Invoice' => ['InvoiceID' => 'first-invoice-id'], 'Amount' => 100],
            ['Invoice' => ['InvoiceID' => 'second-invoice-id'], 'Amount' => 250],
        ],
    ]],
]);

// raw() gives you the whole response when you need its status or headers.
$response = XeroBridge::raw('GET', 'Items');

$firstItemId = $response->json('Items.0.ItemID');
$remaining = $response->header('X-DayLimit-Remaining');
```

`request()` returns `array` and gives you `[]` for a `204`. `raw()` returns an
`Illuminate\Http\Client\Response`.

> ### ⚠️ You cannot ask an arbitrary endpoint for a PDF
>
> The client sets `Accept: application/json` with `replaceHeaders()`, which **overwrites**
> rather than merges — an `Accept` you pass to `request()` or `raw()` is discarded. That
> is deliberate: Laravel's `withHeaders()` merges recursively, so a stray
> `Accept: application/xml` would make Xero answer with XML and every `->json()` call
> silently return `null`.
>
> The only non-JSON path in the package is `XeroBridge::invoices()->pdf($idOrNumber)`,
> which passes the content type through a dedicated argument and verifies the response
> really was a PDF before handing you the bytes.

### Reachable, but needing a scope you do not have by default

The shipped `XERO_SCOPES` is:

```
openid profile email offline_access accounting.invoices accounting.payments
accounting.contacts accounting.settings accounting.attachments
```

Anything below needs a scope added to that list **and** a fresh authorisation:

| Resource | Scope required |
|---|---|
| `BankTransactions`, `BankTransfers` | `accounting.banktransactions` |
| `ManualJournals` | `accounting.manualjournals` |
| `Journals` (the general ledger) | `accounting.journals.read` |
| `Budgets` | `accounting.budgets.read` |
| `Reports/BalanceSheet` | `accounting.reports.balancesheet.read` |
| `Reports/ProfitAndLoss` | `accounting.reports.profitandloss.read` |
| `Reports/TrialBalance` | `accounting.reports.trialbalance.read` |
| `Reports/BankSummary` | `accounting.reports.banksummary.read` |
| `Reports/BudgetSummary` | `accounting.reports.budgetsummary.read` |
| `Reports/ExecutiveSummary` | `accounting.reports.executivesummary.read` |
| `Reports/AgedReceivablesByContact`, `Reports/AgedPayablesByContact` | `accounting.reports.aged.read` |
| `Reports/GSTReport`, `Reports/BASReport` | `accounting.reports.taxreports.read` |
| `ExpenseClaims`, `Receipts` | `accounting.transactions` — broad, and retires in September 2027 |

A call missing its scope comes back as `XeroScopeException`. That is a `401`, but
refreshing the token will never fix it, so the package identifies it by Xero's
`WWW-Authenticate: insufficent_scope` header — Xero's own spelling, matched alongside the
correct one — before it ever reaches the refresh path.

> ### ⚠️ Deploy a scope change BEFORE anyone reconnects
>
> Scopes are fixed at authorisation time. They are additive and cannot be removed from an
> existing token without revoking it, and an existing connection does not gain a scope
> when you edit `XERO_SCOPES`. Widen `XERO_SCOPES`, deploy, and only then send people
> through `XeroBridge::connectUrl()` again. Doing it in the other order means everybody
> connects twice.
>
> The granted scopes are stored per connection, so you can tell which connections predate
> the change:
>
> ```php
> use Peoplelogy\XeroBridge\Support\Scopes;
>
> $stale = XeroBridge::connections()->filter(
>     fn ($connection) => Scopes::missing($connection->scopes, 'accounting.journals.read') !== []
> );
> ```

### Other Xero APIs, outside Accounting

Pass an **absolute** URL and `request()` bypasses the Accounting base URL while still
attaching the token and tenant header. There is a test pinning this
(`tests/Unit/GenericRequestTest.php`), so it will not silently regress.

```php
$employees = XeroBridge::request('GET', 'https://api.xero.com/payroll.xro/2.0/Employees');
```

The same applies to Files (`files.xro`), Projects (`projects.xro`), Assets (`assets.xro`)
and Bank Feeds. Each needs its own scope, granted the same way and subject to the same
deploy-first warning.

### Not reachable

Anything needing a different OAuth **grant type** rather than a different URL. The Xero
App Store API and Custom Connections both use client credentials; this package implements
the authorisation code flow with refresh tokens, by design.
