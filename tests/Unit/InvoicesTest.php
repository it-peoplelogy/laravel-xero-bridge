<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Peoplelogy\XeroBridge\Events\InvoiceCreated;
use Peoplelogy\XeroBridge\Exceptions\InvalidInvoicePayloadException;
use Peoplelogy\XeroBridge\Exceptions\InvalidInvoiceTransitionException;
use Peoplelogy\XeroBridge\Exceptions\InvoiceCannotBeVoidedException;
use Peoplelogy\XeroBridge\Exceptions\UnsafeContactPayloadException;
use Peoplelogy\XeroBridge\Exceptions\XeroBridgeException;
use Peoplelogy\XeroBridge\Exceptions\XeroValidationException;
use Peoplelogy\XeroBridge\Facades\XeroBridge;
use Peoplelogy\XeroBridge\Filters\InvoiceFilter;

beforeEach(fn () => connection());

function invoicePayload(array $overrides = []): array
{
    return array_merge([
        'Type' => 'ACCREC',
        'Contact' => ['ContactID' => 'c-1'],
        'LineItems' => [
            ['Description' => 'Training', 'Quantity' => 1, 'UnitAmount' => 1200],
        ],
    ], $overrides);
}

function fakeCreated(): void
{
    Http::fake(['api.xero.com/*' => Http::response([
        'Invoices' => [['InvoiceID' => 'inv-1', 'InvoiceNumber' => 'INV-0001', 'Status' => 'DRAFT']],
    ])]);
}

function sentBody(): array
{
    $body = [];

    Http::assertSent(function (Request $r) use (&$body) {
        if ($r->method() === 'POST') {
            $body = $r->data();
        }

        return true;
    });

    return $body;
}

/*
|--------------------------------------------------------------------------
| Defaults
|--------------------------------------------------------------------------
*/

it('fills in the account code, currency and branding theme', function () {
    config()->set('xero-bridge.connections.default.branding_theme_id', 'theme-1');
    fakeCreated();

    XeroBridge::invoices()->create(invoicePayload());

    $invoice = sentBody()['Invoices'][0];

    expect($invoice['LineItems'][0]['AccountCode'])->toBe('200')
        ->and($invoice['CurrencyCode'])->toBe('MYR')
        ->and($invoice['BrandingThemeID'])->toBe('theme-1');
});

it('never overwrites a value the caller supplied', function () {
    fakeCreated();

    XeroBridge::invoices()->create(invoicePayload([
        'CurrencyCode' => 'SGD',
        'LineItems' => [
            ['Description' => 'A', 'AccountCode' => '400'],
            ['Description' => 'B'],
        ],
    ]));

    $invoice = sentBody()['Invoices'][0];

    expect($invoice['CurrencyCode'])->toBe('SGD')
        ->and($invoice['LineItems'][0]['AccountCode'])->toBe('400')
        ->and($invoice['LineItems'][1]['AccountCode'])->toBe('200');
});

it('treats null and empty string as missing but zero as present', function (mixed $value, string $expected) {
    fakeCreated();

    XeroBridge::invoices()->create(invoicePayload([
        'LineItems' => [['Description' => 'A', 'AccountCode' => $value]],
    ]));

    expect(sentBody()['Invoices'][0]['LineItems'][0]['AccountCode'])->toBe($expected);
})->with([
    'null is missing' => [null, '200'],
    'empty string is missing' => ['', '200'],
    // '0' is a legitimate account code, not an absence.
    'zero is present' => ['0', '0'],
]);

it('does not add a second key when the caller used a different case', function () {
    fakeCreated();

    XeroBridge::invoices()->create(invoicePayload([
        'LineItems' => [['Description' => 'A', 'accountCode' => '400']],
    ]));

    $line = sentBody()['Invoices'][0]['LineItems'][0];

    expect($line)->not->toHaveKey('AccountCode')
        ->and($line['accountCode'])->toBe('400');
});

it('never injects a tax type onto a line that already states tax', function () {
    // THE SST GUARD. Malaysian SST is per line: training 8%, education and
    // rental 6%. A connection-wide default would stamp 8% on a 6% venue
    // recharge -- an LHDN problem, not a software one.
    config()->set('xero-bridge.connections.default.tax_type', 'OUTPUT');
    fakeCreated();

    XeroBridge::invoices()->create(invoicePayload([
        'LineItems' => [
            ['Description' => 'Training', 'TaxAmount' => 96],
            ['Description' => 'Venue', 'TaxType' => 'SST6'],
            ['Description' => 'Other'],
        ],
    ]));

    $lines = sentBody()['Invoices'][0]['LineItems'];

    expect($lines[0])->not->toHaveKey('TaxType')
        ->and($lines[1]['TaxType'])->toBe('SST6')
        ->and($lines[2]['TaxType'])->toBe('OUTPUT');
});

it('injects no tax type at all when none is configured', function () {
    fakeCreated();

    XeroBridge::invoices()->create(invoicePayload());

    expect(sentBody()['Invoices'][0]['LineItems'][0])->not->toHaveKey('TaxType');
});

/*
|--------------------------------------------------------------------------
| Safety guards
|--------------------------------------------------------------------------
*/

it('refuses a Contact block carrying more than a ContactID', function () {
    // Xero would apply those fields to the CONTACT RECORD and delete any
    // ContactPersons not included -- silently and irreversibly.
    fakeCreated();

    expect(fn () => XeroBridge::invoices()->create(invoicePayload([
        'Contact' => ['ContactID' => 'c-1', 'Name' => 'Acme Sdn Bhd'],
    ])))->toThrow(UnsafeContactPayloadException::class, 'ContactPersons');

    Http::assertNothingSent();
});

it('allows an inline contact when there is no ContactID', function () {
    fakeCreated();

    XeroBridge::invoices()->create(invoicePayload([
        'Contact' => ['Name' => 'Brand New Customer'],
    ]));

    Http::assertSentCount(1);
});

it('allows contact mutation when it is explicitly requested', function () {
    fakeCreated();

    XeroBridge::invoices()->withContactMutation()->create(invoicePayload([
        'Contact' => ['ContactID' => 'c-1', 'Name' => 'Renamed'],
    ]));

    Http::assertSentCount(1);
});

it('resets the contact mutation opt-in after one call', function () {
    fakeCreated();

    $invoices = XeroBridge::invoices();
    $invoices->withContactMutation()->create(invoicePayload([
        'Contact' => ['ContactID' => 'c-1', 'Name' => 'Renamed'],
    ]));

    expect(fn () => $invoices->create(invoicePayload([
        'Contact' => ['ContactID' => 'c-1', 'Name' => 'Again'],
    ])))->toThrow(UnsafeContactPayloadException::class);
});

it('requires an explicit invoice type', function () {
    fakeCreated();

    // Guessing ACCREC would post a bill as a sale on the one occasion
    // someone meant ACCPAY.
    expect(fn () => XeroBridge::invoices()->create([
        'Contact' => ['ContactID' => 'c-1'],
    ]))->toThrow(InvalidInvoicePayloadException::class, 'ACCREC');

    Http::assertNothingSent();
});

it('refuses an update whose line items would be recreated', function () {
    Http::fake(['api.xero.com/*' => Http::response(['Invoices' => [['InvoiceID' => 'inv-1']]])]);

    // Omitting LineItemID deletes and recreates the line; omitting a line
    // deletes it. "Partial update" is a lie for LineItems.
    expect(fn () => XeroBridge::invoices()->update('inv-1', [
        'LineItems' => [['Description' => 'Changed']],
    ]))->toThrow(InvalidInvoicePayloadException::class, 'LineItemID');

    Http::assertNothingSent();
});

it('allows a line item replacement when it is explicitly requested', function () {
    Http::fake(['api.xero.com/*' => Http::response(['Invoices' => [['InvoiceID' => 'inv-1']]])]);

    XeroBridge::invoices()->replacingLineItems()->update('inv-1', [
        'LineItems' => [['Description' => 'Changed']],
    ]);

    Http::assertSentCount(1);
});

it('accepts an update whose lines all carry a LineItemID', function () {
    Http::fake(['api.xero.com/*' => Http::response(['Invoices' => [['InvoiceID' => 'inv-1']]])]);

    XeroBridge::invoices()->update('inv-1', [
        'LineItems' => [['LineItemID' => 'li-1', 'Description' => 'Changed']],
    ]);

    Http::assertSentCount(1);
});

/*
|--------------------------------------------------------------------------
| Idempotency and events
|--------------------------------------------------------------------------
*/

it('sends an idempotency key within Xero\'s limit', function () {
    fakeCreated();

    XeroBridge::invoices()->create(invoicePayload());

    Http::assertSent(function (Request $r) {
        $key = $r->header('Idempotency-Key')[0] ?? '';

        return $key !== '' && strlen($key) <= 128;
    });
});

it('uses a caller-supplied idempotency key verbatim', function () {
    fakeCreated();

    XeroBridge::invoices()->create(invoicePayload(), 'my-own-key');

    Http::assertSent(fn (Request $r) => $r->hasHeader('Idempotency-Key', 'my-own-key'));
});

it('rejects an idempotency key over 128 characters', function () {
    fakeCreated();

    // Xero returns a 400 for this; catching it here saves a round trip.
    expect(fn () => XeroBridge::invoices()->create(invoicePayload(), str_repeat('a', 129)))
        ->toThrow(XeroBridgeException::class, '128');
});

it('fires InvoiceCreated once with the id and key', function () {
    Event::fake([InvoiceCreated::class]);
    fakeCreated();

    XeroBridge::invoices()->create(invoicePayload(), 'key-1');

    Event::assertDispatchedTimes(InvoiceCreated::class, 1);
    Event::assertDispatched(InvoiceCreated::class, fn (InvoiceCreated $e) => $e->invoiceId() === 'inv-1'
        && $e->invoiceNumber() === 'INV-0001'
        && $e->idempotencyKey === 'key-1'
        && $e->connectionKey === 'default');
});

/*
|--------------------------------------------------------------------------
| Bulk create
|--------------------------------------------------------------------------
*/

it('reads per-element outcomes out of a 200', function () {
    Event::fake([InvoiceCreated::class]);

    // summarizeErrors=false makes Xero return 200 even when items failed.
    Http::fake(['api.xero.com/*' => Http::response([
        'Invoices' => [
            ['StatusAttributeString' => 'OK', 'InvoiceID' => 'a1'],
            ['StatusAttributeString' => 'ERROR', 'ValidationErrors' => [['Message' => 'Contact is required']]],
            ['StatusAttributeString' => 'ERROR', 'ValidationErrors' => [['Description' => 'Account code 999 is invalid']]],
            ['StatusAttributeString' => 'WARNING', 'InvoiceID' => 'a4', 'Warnings' => [['Message' => 'Currency rate used']]],
        ],
    ], 200)]);

    $result = XeroBridge::invoices()->createMany([invoicePayload(), invoicePayload()]);

    expect($result->successful())->toHaveCount(1)
        ->and($result->warned())->toHaveCount(1)
        ->and($result->failed())->toHaveCount(2)
        // Both key spellings must be read: Xero's docs use each in a
        // different example of this same feature.
        ->and($result->errorMessages())->toBe([
            'Contact is required',
            'Account code 999 is invalid',
        ])
        ->and($result->warningMessages())->toBe(['Currency rate used']);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'summarizeErrors=false'));

    // Fired for the accepted elements only -- never for a failure.
    Event::assertDispatchedTimes(InvoiceCreated::class, 2);
});

it('can throw on any bulk failure', function () {
    Http::fake(['api.xero.com/*' => Http::response([
        'Invoices' => [['StatusAttributeString' => 'ERROR', 'ValidationErrors' => [['Message' => 'nope']]]],
    ], 200)]);

    expect(fn () => XeroBridge::invoices()->createMany([invoicePayload()])->throwIfAnyFailed())
        ->toThrow(XeroValidationException::class, 'nope');
});

/*
|--------------------------------------------------------------------------
| Paging
|--------------------------------------------------------------------------
*/

function fakePages(array $pages): void
{
    $sequence = Http::sequence();

    foreach ($pages as $page) {
        $sequence->push($page, 200);
    }

    Http::fake(['api.xero.com/*' => $sequence]);
}

it('walks to pageCount', function () {
    fakePages([
        ['Invoices' => array_fill(0, 100, ['InvoiceID' => 'x']), 'pagination' => ['page' => 1, 'pageSize' => 100, 'pageCount' => 3, 'itemCount' => 250]],
        ['Invoices' => array_fill(0, 100, ['InvoiceID' => 'x']), 'pagination' => ['page' => 2, 'pageSize' => 100, 'pageCount' => 3, 'itemCount' => 250]],
        ['Invoices' => array_fill(0, 50, ['InvoiceID' => 'x']), 'pagination' => ['page' => 3, 'pageSize' => 100, 'pageCount' => 3, 'itemCount' => 250]],
    ]);

    expect(XeroBridge::invoices()->all()->count())->toBe(250);

    Http::assertSentCount(3);
});

it('stops on pageCount even when the last page is full', function () {
    // This is what the pagination object buys: the old "fetch until a short
    // page" approach always wastes one request on an exact multiple.
    fakePages([
        ['Invoices' => array_fill(0, 100, ['InvoiceID' => 'x']), 'pagination' => ['page' => 1, 'pageSize' => 100, 'pageCount' => 2, 'itemCount' => 200]],
        ['Invoices' => array_fill(0, 100, ['InvoiceID' => 'x']), 'pagination' => ['page' => 2, 'pageSize' => 100, 'pageCount' => 2, 'itemCount' => 200]],
    ]);

    expect(XeroBridge::invoices()->all()->count())->toBe(200);

    Http::assertSentCount(2);
});

it('falls back to the short page when there is no pagination object', function () {
    fakePages([
        ['Invoices' => array_fill(0, 100, ['InvoiceID' => 'x'])],
        ['Invoices' => array_fill(0, 40, ['InvoiceID' => 'x'])],
    ]);

    expect(XeroBridge::invoices()->all()->count())->toBe(140);

    Http::assertSentCount(2);
});

it('is lazy', function () {
    fakePages([
        ['Invoices' => array_fill(0, 100, ['InvoiceID' => 'x']), 'pagination' => ['page' => 1, 'pageSize' => 100, 'pageCount' => 99, 'itemCount' => 9900]],
    ]);

    expect(XeroBridge::invoices()->all()->take(5)->all())->toHaveCount(5);

    // Only the first page was ever fetched.
    Http::assertSentCount(1);
});

it('sends no request until it is iterated', function () {
    fakePages([['Invoices' => []]]);

    XeroBridge::invoices()->all();

    Http::assertNothingSent();
});

it('stops immediately on an empty first page', function () {
    fakePages([['Invoices' => []]]);

    expect(XeroBridge::invoices()->all()->count())->toBe(0);

    Http::assertSentCount(1);
});

it('starts from a page the caller asked for', function () {
    fakePages([
        ['Invoices' => array_fill(0, 100, ['InvoiceID' => 'x']), 'pagination' => ['page' => 2, 'pageSize' => 100, 'pageCount' => 3, 'itemCount' => 300]],
        ['Invoices' => array_fill(0, 100, ['InvoiceID' => 'x']), 'pagination' => ['page' => 3, 'pageSize' => 100, 'pageCount' => 3, 'itemCount' => 300]],
    ]);

    expect(XeroBridge::invoices()->all(InvoiceFilter::make()->page(2))->count())->toBe(200);

    Http::assertSentCount(2);
});

it('aborts rather than paging forever', function () {
    Http::fake(['api.xero.com/*' => Http::response([
        'Invoices' => array_fill(0, 100, ['InvoiceID' => 'x']),
        'pagination' => ['page' => 1, 'pageSize' => 100, 'pageCount' => 9999, 'itemCount' => 999900],
    ])]);

    expect(fn () => XeroBridge::invoices()->all(null, maxPages: 2)->count())
        ->toThrow(XeroBridgeException::class, 'after 2 pages');
});

/*
|--------------------------------------------------------------------------
| Transitions and sub-resources
|--------------------------------------------------------------------------
*/

it('refuses to void an invoice that has a payment applied', function () {
    Http::fake(['api.xero.com/*' => Http::response(['Invoices' => [[
        'InvoiceID' => 'inv-1',
        'Status' => 'AUTHORISED',
        'AmountPaid' => 1200,
        'Payments' => [['PaymentID' => 'pay-1', 'Amount' => 1200]],
    ]]])]);

    // Xero's own error here is opaque, so the package names the payment.
    expect(fn () => XeroBridge::invoices()->void('inv-1'))
        ->toThrow(InvoiceCannotBeVoidedException::class, 'pay-1');

    // The preflight GET only -- no POST was attempted.
    Http::assertSentCount(1);
});

it('refuses to void a draft', function () {
    Http::fake(['api.xero.com/*' => Http::response(['Invoices' => [['InvoiceID' => 'inv-1', 'Status' => 'DRAFT']]])]);

    expect(fn () => XeroBridge::invoices()->void('inv-1'))
        ->toThrow(InvalidInvoiceTransitionException::class, 'DRAFT');
});

it('can skip the preflight', function () {
    Http::fake(['api.xero.com/*' => Http::response(['Invoices' => [['InvoiceID' => 'inv-1', 'Status' => 'VOIDED']]])]);

    XeroBridge::invoices()->void('inv-1', preflight: false);

    Http::assertSentCount(1);
    expect(sentBody()['Invoices'][0]['Status'])->toBe('VOIDED');
});

it('deletes a SUBMITTED invoice, not only a draft', function () {
    // The spec said "drafts"; Xero allows DELETED from SUBMITTED too.
    Http::fake(['api.xero.com/*' => Http::response(['Invoices' => [['InvoiceID' => 'inv-1', 'Status' => 'SUBMITTED']]])]);

    XeroBridge::invoices()->delete('inv-1');

    Http::assertSentCount(2);
});

it('refuses to delete an authorised invoice', function () {
    Http::fake(['api.xero.com/*' => Http::response(['Invoices' => [['InvoiceID' => 'inv-1', 'Status' => 'AUTHORISED']]])]);

    expect(fn () => XeroBridge::invoices()->delete('inv-1'))
        ->toThrow(InvalidInvoiceTransitionException::class, 'AUTHORISED');
});

it('treats a 204 from the email endpoint as success', function () {
    Http::fake(['api.xero.com/*' => Http::response('', 204)]);

    XeroBridge::invoices()->email('inv-1');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'Invoices/inv-1/Email'));
});

it('returns the pdf bytes', function () {
    Http::fake(['api.xero.com/*' => Http::response('%PDF-1.4 fake', 200, ['Content-Type' => 'application/pdf'])]);

    expect(XeroBridge::invoices()->pdf('inv-1'))->toStartWith('%PDF');

    // The Accept header must REPLACE the default, not merge with it -- a
    // merged "application/json, application/pdf" makes Xero return JSON and
    // nothing fails until someone opens the file.
    Http::assertSent(fn (Request $r) => $r->header('Accept') === ['application/pdf']);
});

it('refuses a pdf request that came back as JSON', function () {
    Http::fake(['api.xero.com/*' => Http::response(['Invoices' => []], 200, ['Content-Type' => 'application/json'])]);

    expect(fn () => XeroBridge::invoices()->pdf('inv-1'))
        ->toThrow(XeroBridgeException::class, 'PDF');
});

it('reads the online invoice url', function () {
    Http::fake(['api.xero.com/*' => Http::response([
        'OnlineInvoices' => [['OnlineInvoiceUrl' => 'https://in.xero.com/abc']],
    ])]);

    expect(XeroBridge::invoices()->onlineUrl('inv-1'))->toBe('https://in.xero.com/abc');
});

it('reports when no online invoice url is available', function () {
    Http::fake(['api.xero.com/*' => Http::response(['OnlineInvoices' => []])]);

    expect(fn () => XeroBridge::invoices()->onlineUrl('inv-1'))
        ->toThrow(XeroBridgeException::class, 'online invoice');
});

it('finds an invoice by number as well as by id', function () {
    Http::fake(['api.xero.com/*' => Http::response(['Invoices' => [['InvoiceID' => 'inv-1']]])]);

    XeroBridge::invoices()->find('INV-01514');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'Invoices/INV-01514'));
});

it('rejects an identifier containing a slash', function () {
    // %2F in a path segment is rejected by many edge proxies before it ever
    // reaches Xero.
    expect(fn () => XeroBridge::invoices()->find('foo/bar'))
        ->toThrow(XeroBridgeException::class, 'identifier');
});

it('returns null when an invoice is not found', function () {
    Http::fake(['api.xero.com/*' => Http::response(['Invoices' => []])]);

    expect(XeroBridge::invoices()->find('inv-missing'))->toBeNull();
});
