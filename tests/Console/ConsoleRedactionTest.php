<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * What the console sends the browser.
 *
 * Its reads hand Xero's payloads back whole, and those carry fields the
 * package refuses to store anywhere: Organisation.APIKey, a live
 * Xero-to-Xero credential; the customer's BankAccountDetails and
 * BatchPayments on every contact read; the organisation's own
 * BankAccountNumber on GET /Accounts. Every value below that ends in
 * -SENTINEL is one of those and must never reach the response -- while the
 * fields around it, TaxNumber included, must.
 */
beforeEach(fn () => connection());

/** A contact as Xero returns it: identity, tax number, and the customer's bank details. */
function redactionContact(array $overrides = []): array
{
    return array_merge([
        'ContactID' => 'c-1',
        'Name' => 'Acme Ltd',
        'EmailAddress' => 'finance@acme.test',
        'TaxNumber' => 'C1234567890',
        // NOT a bank account: Xero defines it as the host's own customer code.
        'AccountNumber' => 'CUST-0042',
        'BankAccountDetails' => 'CUSTOMER-BANK-SENTINEL',
        'BatchPayments' => [
            'BankAccountNumber' => 'BATCH-NUMBER-SENTINEL',
            'BankAccountName' => 'BATCH-NAME-SENTINEL',
            'Details' => 'BATCH-DETAILS-SENTINEL',
            'Code' => 'BATCH-CODE-SENTINEL',
            'Reference' => 'BATCH-REFERENCE-SENTINEL',
        ],
    ], $overrides);
}

/**
 * Xero answering with those fields everywhere they really occur. Overrides go
 * first, so they match first -- a second Http::fake() appends rather than
 * replaces.
 */
function redactionFakeXero(array $overrides = []): void
{
    Http::fake($overrides + [
        'api.xero.com/api.xro/2.0/Organisation' => Http::response([
            'Organisations' => [[
                'Name' => 'Demo Company (Global)',
                'IsDemoCompany' => true,
                'TaxNumber' => 'C2584563200',
                'APIKey' => 'APIKEY-SENTINEL',
            ]],
        ], 200, xeroHeaders()),
        'api.xero.com/api.xro/2.0/Accounts*' => Http::response([
            'Accounts' => [
                ['Code' => '200', 'Name' => 'Sales', 'Type' => 'REVENUE'],
                [
                    'Code' => '090',
                    'Name' => 'Business Bank',
                    'Type' => 'BANK',
                    'BankAccountType' => 'BANK',
                    'BankAccountNumber' => 'ORG-BANK-SENTINEL',
                    'EnablePaymentsToAccount' => true,
                ],
            ],
        ], 200, xeroHeaders()),
        'api.xero.com/api.xro/2.0/Invoices/*/Email' => Http::response('', 204, xeroHeaders()),
        'api.xero.com/api.xro/2.0/Invoices*' => Http::response([
            'Invoices' => [['InvoiceID' => 'inv-1', 'Status' => 'AUTHORISED', 'Contact' => ['ContactID' => 'c-1']]],
        ], 200, xeroHeaders()),
        'api.xero.com/api.xro/2.0/Contacts*' => Http::response([
            'Contacts' => [redactionContact()],
        ], 200, xeroHeaders()),
    ]);
}

function redactionRun(string $action, array $params = []): TestResponse
{
    return test()->postJson('/xero/console/run', ['action' => $action, 'params' => $params])
        ->assertOk()
        ->assertJsonPath('ok', true);
}

/** The raw response body never carries a -SENTINEL value, at any depth. */
function assertNoSentinel(TestResponse $response): void
{
    expect($response->getContent())->not->toContain('SENTINEL');
}

it('masks the organisation API key and keeps the rest of the organisation', function () {
    redactionFakeXero();

    $response = redactionRun('settings.organisation')
        ->assertJsonPath('data.APIKey', '[redacted]')
        ->assertJsonPath('data.Name', 'Demo Company (Global)')
        // Tier two of the capture policy, NOT masked here: the console exists
        // to show what Xero holds, and a tax number is what it tests.
        ->assertJsonPath('data.TaxNumber', 'C2584563200');

    assertNoSentinel($response);
});

it('masks the organisation\'s own bank account number', function (string $action) {
    redactionFakeXero();

    $response = redactionRun($action);

    $bank = collect($response->json('data'))->firstWhere('Code', '090');

    expect($bank)->toMatchArray([
        'Name' => 'Business Bank',
        'BankAccountNumber' => '[redacted]',
        // The enum that tells a bank account from a credit card is not an
        // identifier, and shares a stem with the masked field.
        'BankAccountType' => 'BANK',
    ]);

    assertNoSentinel($response);
})->with([
    'chart of accounts' => ['settings.accounts'],
    'payment accounts' => ['settings.payment_accounts'],
]);

it('masks a contact\'s bank details wherever the contact is read', function (string $action, array $params) {
    redactionFakeXero();

    $response = redactionRun($action, $params)
        ->assertJsonPath('data.ContactID', 'c-1')
        ->assertJsonPath('data.Name', 'Acme Ltd')
        ->assertJsonPath('data.TaxNumber', 'C1234567890')
        ->assertJsonPath('data.AccountNumber', 'CUST-0042')
        ->assertJsonPath('data.BankAccountDetails', '[redacted]')
        // The whole object goes, not just its number: its siblings are the
        // account name, details, code and reference.
        ->assertJsonPath('data.BatchPayments', '[redacted]');

    assertNoSentinel($response);
})->with([
    'find' => ['contacts.find', ['contact_id' => 'c-1']],
    'find by email' => ['contacts.find_by_email', ['email' => 'finance@acme.test']],
    'first or create, finding one' => ['contacts.first_or_create', ['email' => 'finance@acme.test', 'name' => 'Acme Ltd']],
]);

it('masks the contact nested inside a send-to-contact result', function () {
    redactionFakeXero();

    $response = redactionRun('invoices.send_to_contact', ['id' => 'inv-1', 'tin' => 'C1234567890'])
        ->assertJsonPath('data.contact.TaxNumber', 'C1234567890')
        ->assertJsonPath('data.contact.BankAccountDetails', '[redacted]')
        ->assertJsonPath('data.contact.BatchPayments', '[redacted]');

    assertNoSentinel($response);
});

it('matches the whole key in any case, never a stem', function () {
    redactionFakeXero([
        'api.xero.com/api.xro/2.0/Contacts*' => Http::response([
            'Contacts' => [[
                'ContactID' => 'c-1',
                'bankaccountdetails' => 'LOWER-CASE-SENTINEL',
                'BANKACCOUNTNUMBER' => 'UPPER-CASE-SENTINEL',
                'BankAccountDetailsNote' => 'kept: only the exact key is masked',
            ]],
        ], 200, xeroHeaders()),
    ]);

    $response = redactionRun('contacts.find', ['contact_id' => 'c-1'])
        ->assertJsonPath('data.bankaccountdetails', '[redacted]')
        ->assertJsonPath('data.BANKACCOUNTNUMBER', '[redacted]')
        ->assertJsonPath('data.BankAccountDetailsNote', 'kept: only the exact key is masked');

    assertNoSentinel($response);
});

it('uses the capture placeholder when one is configured', function () {
    config()->set('xero-bridge.capture.redact.placeholder', '(hidden)');
    redactionFakeXero();

    redactionRun('settings.organisation')->assertJsonPath('data.APIKey', '(hidden)');
});

it('leaves the status snapshot alone', function () {
    // client_secret_set says whether the secret is configured; it is not the
    // secret, and only an exact key match is masked.
    redactionRun('status')
        ->assertJsonPath('data.config.client_secret_set', true)
        ->assertJsonPath('data.connections.0.tenant_id', 'tenant-1');
});

it('returns a full 1000-invoice page, masked and untruncated', function () {
    // Why the console does not use the capture Redactor: its node and byte
    // budgets cut a page this size short, and a list the console truncated
    // would be indistinguishable from a short one.
    $invoices = [];

    for ($i = 1; $i <= 1000; $i++) {
        $invoices[] = [
            'InvoiceID' => "inv-{$i}",
            'InvoiceNumber' => "INV-{$i}",
            'Type' => 'ACCREC',
            'Status' => 'AUTHORISED',
            'Reference' => "Training cohort {$i}",
            'Date' => '2026-01-15',
            'DueDate' => '2026-02-14',
            'CurrencyCode' => 'MYR',
            'SubTotal' => 100.0,
            'TotalTax' => 8.0,
            'Total' => 108.0,
            'AmountDue' => 108.0,
            'Contact' => [
                'ContactID' => "c-{$i}",
                'Name' => "Customer {$i} Sdn Bhd",
                'TaxNumber' => "C{$i}",
                'BankAccountDetails' => "CUSTOMER-{$i}-SENTINEL",
            ],
            'LineItems' => [
                ['Description' => 'Two-day leadership workshop, all materials included', 'Quantity' => 1.0, 'UnitAmount' => 60.0, 'AccountCode' => '200', 'TaxType' => 'OUTPUT', 'LineAmount' => 60.0],
                ['Description' => 'Assessment and certification for each participant', 'Quantity' => 1.0, 'UnitAmount' => 40.0, 'AccountCode' => '200', 'TaxType' => 'OUTPUT', 'LineAmount' => 40.0],
            ],
        ];
    }

    redactionFakeXero([
        'api.xero.com/api.xro/2.0/Invoices*' => Http::response(['Invoices' => $invoices], 200, xeroHeaders()),
    ]);

    $response = redactionRun('invoices.list', ['page_size' => '1000'])
        ->assertJsonPath('count', 1000)
        ->assertJsonPath('data.999.InvoiceNumber', 'INV-1000')
        ->assertJsonPath('data.999.Contact.TaxNumber', 'C1000')
        ->assertJsonPath('data.999.Contact.BankAccountDetails', '[redacted]')
        ->assertJsonPath('data.999.LineItems.1.Description', 'Assessment and certification for each participant');

    expect($response->getContent())->not->toContain('[truncated');
    assertNoSentinel($response);
});
