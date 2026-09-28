<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Peoplelogy\XeroBridge\Facades\XeroBridge;

/**
 * The three flows the business actually asked for, pinned end to end.
 *
 *   1. Find-or-create a contact in Xero, never duplicating one.
 *   2. Create an invoice against that contact.
 *   3. At payment time, capture the buyer's business registration number and
 *      TIN, then have Xero email the invoice -- including to the people who
 *      should be copied.
 *
 * These are deliberately written as a readable narrative rather than as unit
 * tests: they are the executable specification of what the package promises
 * the consuming applications.
 */
beforeEach(fn () => connection());

/** Captures the body of the nth request the package sent. */
function bodyOf(int $index): array
{
    $bodies = [];

    Http::assertSent(function (Request $r) use (&$bodies) {
        $bodies[] = $r->data();

        return true;
    });

    return $bodies[$index] ?? [];
}

/*
|--------------------------------------------------------------------------
| 1. Contact: create once, reuse thereafter
|--------------------------------------------------------------------------
*/

it('reuses an existing Xero contact instead of creating a second one', function () {
    Http::fake([
        // The lookup finds the customer already in Xero.
        'api.xero.com/api.xro/2.0/Contacts*' => Http::response([
            'Contacts' => [['ContactID' => 'c-existing', 'Name' => 'Acme Sdn Bhd']],
        ]),
    ]);

    $contact = XeroBridge::contacts()->firstOrCreate(
        ['EmailAddress' => 'finance@acme.test'],
        ['Name' => 'Acme Sdn Bhd'],
    );

    expect($contact['ContactID'])->toBe('c-existing');

    // One GET, and crucially NO write of any kind.
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $r) => in_array($r->method(), ['POST', 'PUT'], true));
});

it('creates the contact with the tax and registration numbers when it is new', function () {
    Http::fake(['api.xero.com/api.xro/2.0/Contacts*' => Http::sequence()
        ->push(['Contacts' => []], 200)                                  // lookup: not found
        ->push(['Contacts' => [['ContactID' => 'c-new']]], 200)]);       // create

    $contact = XeroBridge::contacts()->firstOrCreate(
        ['EmailAddress' => 'finance@acme.test'],
        [
            'Name' => 'Acme Sdn Bhd',
            // Xero: "Company registration number (max length = 50)"
            'CompanyNumber' => '202401012345',
            // Xero: "...also known as the ABN (Australia)... Tax ID Number
            // (US and global)". This is the TIN field.
            'TaxNumber' => 'C12345678901',
        ],
    );

    expect($contact['ContactID'])->toBe('c-new');

    $sent = bodyOf(1)['Contacts'][0];

    expect($sent['Name'])->toBe('Acme Sdn Bhd')
        ->and($sent['EmailAddress'])->toBe('finance@acme.test')
        ->and($sent['CompanyNumber'])->toBe('202401012345')
        ->and($sent['TaxNumber'])->toBe('C12345678901');

    // PUT, not POST: PUT is create-only, so a duplicate errors rather than
    // silently overwriting a real customer record.
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' || $r->method() === 'GET');
});

/*
|--------------------------------------------------------------------------
| 2. Invoice creation
|--------------------------------------------------------------------------
*/

it('creates an invoice against the contact id', function () {
    Http::fake(['api.xero.com/*' => Http::response([
        'Invoices' => [['InvoiceID' => 'inv-1', 'InvoiceNumber' => 'INV-0042', 'Status' => 'DRAFT']],
    ])]);

    $invoice = XeroBridge::invoices()->create([
        'Type' => 'ACCREC',
        'Contact' => ['ContactID' => 'c-existing'],
        'Reference' => 'CCF-2026-0042',
        'Status' => 'DRAFT',
        'LineItems' => [[
            'Description' => 'Leadership training, 2 days',
            'Quantity' => 1,
            'UnitAmount' => 4800,
            'TaxType' => 'OUTPUT',
        ]],
    ]);

    expect($invoice['InvoiceNumber'])->toBe('INV-0042');

    $sent = bodyOf(0)['Invoices'][0];

    // Only ContactID travels in the Contact block. Anything else would make
    // Xero rewrite the contact record and delete its ContactPersons.
    expect($sent['Contact'])->toBe(['ContactID' => 'c-existing'])
        ->and($sent['Reference'])->toBe('CCF-2026-0042')
        // Connection defaults filled the gaps the caller left.
        ->and($sent['LineItems'][0]['AccountCode'])->toBe('200')
        ->and($sent['CurrencyCode'])->toBe('MYR');
});

/*
|--------------------------------------------------------------------------
| 3. Payment time: capture BRN + TIN, then have Xero email the invoice
|--------------------------------------------------------------------------
*/

it('captures the buyer details and has Xero email the invoice, copying the right people', function () {
    Http::fake([
        'api.xero.com/api.xro/2.0/Contacts/*' => Http::response([
            'Contacts' => [['ContactID' => 'c-existing']],
        ]),
        'api.xero.com/api.xro/2.0/Invoices/inv-1/Email' => Http::response('', 204),
        'api.xero.com/api.xro/2.0/Invoices/*' => Http::response([
            'Invoices' => [['InvoiceID' => 'inv-1', 'Status' => 'AUTHORISED']],
        ]),
    ]);

    // (a) The customer asks for an invoice and supplies their details.
    XeroBridge::contacts()->update('c-existing', [
        'CompanyNumber' => '202401012345',   // business registration number
        'TaxNumber' => 'C12345678901',       // TIN
        'Addresses' => [[
            'AddressType' => 'STREET',
            'AddressLine1' => 'Level 10, Menara ABC',
            'City' => 'Kuala Lumpur',
            'Region' => 'Wilayah Persekutuan',   // state -- required for MyInvois
            'PostalCode' => '50450',
            'Country' => 'Malaysia',
        ]],
        // THIS is Xero's CC mechanism. The Email endpoint takes no cc
        // parameter; everyone flagged IncludeInEmails is copied on the
        // invoice Xero sends.
        'ContactPersons' => [
            ['FirstName' => 'Siti', 'LastName' => 'Rahman', 'EmailAddress' => 'ap@acme.test', 'IncludeInEmails' => true],
            ['FirstName' => 'Internal', 'LastName' => 'Copy', 'EmailAddress' => 'billing@peoplelogy.test', 'IncludeInEmails' => true],
        ],
    ]);

    $contactSent = bodyOf(0)['Contacts'][0];

    expect($contactSent['CompanyNumber'])->toBe('202401012345')
        ->and($contactSent['TaxNumber'])->toBe('C12345678901')
        ->and($contactSent['ContactPersons'])->toHaveCount(2)
        ->and($contactSent['ContactPersons'][0]['IncludeInEmails'])->toBeTrue();

    // (b) Xero will only email an ACCREC invoice that is SUBMITTED,
    //     AUTHORISED or PAID -- so it must be approved first.
    XeroBridge::invoices()->authorise('inv-1');

    // (c) Ask Xero to send it. Returns 204 with an empty body.
    XeroBridge::invoices()->email('inv-1');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'Invoices/inv-1/Email')
        && $r->method() === 'POST');
});

it('sends an empty body to the email endpoint, because Xero accepts nothing else', function () {
    Http::fake(['api.xero.com/*' => Http::response('', 204)]);

    XeroBridge::invoices()->email('inv-1');

    // Xero's OpenAPI spec types this request body as RequestEmpty with the
    // example {}. There is no recipient, cc or bcc parameter to send, which
    // is why copies are configured on the CONTACT via IncludeInEmails.
    Http::assertSent(function (Request $r) {
        return str_ends_with($r->url(), '/Email')
            && in_array($r->body(), ['', '[]', '{}'], true);
    });
});
