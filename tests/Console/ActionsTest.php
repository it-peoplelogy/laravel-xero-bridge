<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Peoplelogy\XeroBridge\Models\XeroConnection;

/**
 * Every action the console is allowed to run, driven through the real HTTP
 * endpoint.
 *
 * Http::preventStrayRequests() is already on in TestCase::setUp(), so any Xero
 * endpoint the console reaches that the map below misses THROWS rather than
 * passing silently. That is what makes a green run meaningful.
 *
 * The organisation is a Demo Company, so the write guard lets writes through;
 * WriteGuardTest covers the refusals.
 */
beforeEach(fn () => connection());

/**
 * The happy-path Xero, with per-test overrides.
 *
 * Called explicitly by each test rather than from beforeEach, because a second
 * Http::fake() appends to the stub list rather than replacing it -- so a stub
 * registered in beforeEach would keep winning over the one a test set up to
 * replace it. Overrides go first, so they match first.
 */
function fakeXero(array $overrides = []): void
{
    Http::fake($overrides + [
        'api.xero.com/api.xro/2.0/Organisation' => Http::response([
            'Organisations' => [[
                'Name' => 'Demo Company (Global)',
                'IsDemoCompany' => true,
                'BaseCurrency' => 'MYR',
            ]],
        ], 200, xeroHeaders()),
        'api.xero.com/api.xro/2.0/Accounts*' => Http::response([
            'Accounts' => [
                ['Code' => '200', 'Name' => 'Sales', 'Type' => 'REVENUE'],
                ['Code' => '090', 'Name' => 'Business Bank', 'Type' => 'BANK', 'EnablePaymentsToAccount' => true],
            ],
        ], 200, xeroHeaders()),
        'api.xero.com/api.xro/2.0/TaxRates*' => Http::response([
            'TaxRates' => [['TaxType' => 'OUTPUT', 'Name' => 'Tax on Sales']],
        ], 200, xeroHeaders()),
        'api.xero.com/api.xro/2.0/Invoices/*/Email' => Http::response('', 204, xeroHeaders()),
        'api.xero.com/api.xro/2.0/Invoices*' => Http::response([
            'Invoices' => [[
                'InvoiceID' => 'inv-1',
                'InvoiceNumber' => 'INV-0042',
                'Status' => 'DRAFT',
                'AmountDue' => 100.0,
                'Total' => 100.0,
                'Contact' => ['ContactID' => 'c-1'],
            ]],
        ], 200, xeroHeaders()),
        'api.xero.com/api.xro/2.0/Payments*' => Http::response([
            'Payments' => [['PaymentID' => 'pay-1', 'Amount' => 100.0]],
        ], 200, xeroHeaders()),
        'api.xero.com/api.xro/2.0/Contacts*' => Http::response([
            'Contacts' => [['ContactID' => 'c-1', 'Name' => 'Acme Ltd', 'EmailAddress' => 'finance@acme.test']],
        ], 200, xeroHeaders()),
        'identity.xero.com/connect/token' => Http::response([
            'access_token' => 'access-2',
            'refresh_token' => 'refresh-2',
            'expires_in' => 1800,
            'token_type' => 'Bearer',
            'scope' => 'openid profile email offline_access accounting.invoices accounting.settings',
        ]),
    ]);
}

function run(array $body): TestResponse
{
    return test()->postJson('/xero/console/run', $body);
}

/**
 * The body of the first request whose URL contains $needle.
 *
 * By URL rather than by index, because every write action fetches
 * /Organisation first for the write guard -- so the interesting request is
 * never request zero.
 */
function sentBodyTo(string $needle): array
{
    $found = null;

    Http::assertSent(function (Request $r) use ($needle, &$found) {
        if ($found === null && str_contains($r->url(), $needle)) {
            $found = $r->data();
        }

        return true;
    });

    return $found ?? [];
}

/*
|--------------------------------------------------------------------------
| Every allow-listed action succeeds
|--------------------------------------------------------------------------
*/

it('runs every allow-listed action', function (string $action, array $params) {
    fakeXero();

    run(['action' => $action, 'params' => $params])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('action', $action);
})->with([
    'status' => ['status', []],
    'organisation' => ['settings.organisation', []],
    'accounts' => ['settings.accounts', []],
    'accounts filtered' => ['settings.accounts', ['where' => 'Type=="REVENUE"']],
    'tax rates' => ['settings.tax_rates', []],
    'payment accounts' => ['settings.payment_accounts', []],
    'contact first or create' => ['contacts.first_or_create', ['email' => 'finance@acme.test', 'name' => 'Acme Ltd']],
    'create draft' => ['invoices.create_draft', ['contact_id' => 'c-1', 'description' => 'Training', 'unit_amount' => '100']],
    'create paid' => ['invoices.create_paid', ['contact_id' => 'c-1', 'description' => 'Training', 'unit_amount' => '100', 'payment_account_code' => '090']],
    'send to contact' => ['invoices.send_to_contact', ['id' => 'inv-1', 'tin' => 'C123', 'brn' => '202401', 'cc' => 'Finance Team <finance@acme.test>']],
    'find invoice' => ['invoices.find', ['id' => 'INV-0042']],
    'find contact by email' => ['contacts.find_by_email', ['email' => 'finance@acme.test']],
    'find contact' => ['contacts.find', ['contact_id' => 'c-1']],
    'list invoices' => ['invoices.list', []],
    'token refresh' => ['tokens.refresh', []],
    'forget connection' => ['connection.forget', []],
]);

/*
|--------------------------------------------------------------------------
| Invoice building
|--------------------------------------------------------------------------
*/

it('sends ContactID alone, never alongside other contact fields', function () {
    fakeXero();

    // ContactID plus any other field makes Xero rewrite the contact record as
    // a side effect, deleting ContactPersons that were left out.
    run(['action' => 'invoices.create_draft', 'params' => [
        'contact_id' => 'c-1',
        'contact_name' => 'Acme Ltd',
        'description' => 'Training',
    ]])->assertOk()->assertJsonPath('ok', true);

    expect(sentBodyTo('Invoices')['Invoices'][0]['Contact'])->toBe(['ContactID' => 'c-1']);
});

it('omits a blank account code so the connection default applies', function () {
    fakeXero();

    run(['action' => 'invoices.create_draft', 'params' => [
        'contact_id' => 'c-1',
        'description' => 'Training',
        'account_code' => '',
        'tax_type' => '  ',
    ]])->assertOk()->assertJsonPath('ok', true);

    // '200' is the connection default set in TestCase, not something the
    // console sent -- proof the blank was dropped rather than forwarded.
    expect(sentBodyTo('Invoices')['Invoices'][0]['LineItems'][0]['AccountCode'])->toBe('200');
});

it('refuses an invoice with no contact at all', function () {
    fakeXero();

    run(['action' => 'invoices.create_draft', 'params' => ['description' => 'Training']])
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.type', 'InvalidArgumentException');

    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
});

it('refuses an action that is missing a required field', function () {
    fakeXero();

    run(['action' => 'invoices.find', 'params' => []])
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.hint', 'Fill in the field this action needs, then run it again.');
});

/*
|--------------------------------------------------------------------------
| Paging
|--------------------------------------------------------------------------
*/

it('always pages a list, and clamps the page size', function (mixed $given, string $expected) {
    fakeXero();

    // An unbounded /Invoices on a live organisation is the fastest way to
    // spend the daily rate limit from a browser tab.
    run(['action' => 'invoices.list', 'params' => ['page_size' => $given]])->assertOk();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'pageSize='.$expected));
})->with([
    'over the maximum' => ['5000', '1000'],
    'zero' => ['0', '1'],
    'a normal size' => ['50', '50'],
]);

it('defaults to 25 per page', function () {
    fakeXero();

    run(['action' => 'invoices.list', 'params' => []])->assertOk();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'pageSize=25')
        && str_contains($r->url(), 'page=1'));
});

/*
|--------------------------------------------------------------------------
| 2.2 -- create then pay
|--------------------------------------------------------------------------
*/

it('creates the invoice AUTHORISED before paying it', function () {
    fakeXero();

    // A payment cannot attach to a DRAFT, and POSTing Status=PAID is rejected
    // outright -- Xero sets PAID itself once AmountDue reaches zero.
    run(['action' => 'invoices.create_paid', 'params' => [
        'contact_id' => 'c-1',
        'description' => 'Training',
        'unit_amount' => '100',
        'payment_account_code' => '090',
    ]])->assertOk()->assertJsonPath('ok', true);

    expect(sentBodyTo('Invoices')['Invoices'][0]['Status'])->toBe('AUTHORISED');
});

it('pays the amount Xero says is due, not the amount that was sent', function () {
    // Rounding and tax are applied server-side, so AmountDue is the only
    // number that can settle the invoice to the cent.
    fakeXero([
        'api.xero.com/api.xro/2.0/Invoices*' => Http::response([
            'Invoices' => [['InvoiceID' => 'inv-1', 'Status' => 'AUTHORISED', 'AmountDue' => 108.0, 'Total' => 108.0]],
        ], 200, xeroHeaders()),
        'api.xero.com/api.xro/2.0/Payments*' => Http::response([
            'Payments' => [['PaymentID' => 'pay-1']],
        ], 200, xeroHeaders()),
        'api.xero.com/api.xro/2.0/Organisation' => Http::response([
            'Organisations' => [['Name' => 'Demo Company (Global)', 'IsDemoCompany' => true]],
        ], 200, xeroHeaders()),
    ]);

    run(['action' => 'invoices.create_paid', 'params' => [
        'contact_id' => 'c-1',
        'description' => 'Training',
        'unit_amount' => '100',
        'payment_account_code' => '090',
    ]])->assertOk()->assertJsonPath('ok', true);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'Payments')
        && ($r->data()['Payments'][0]['Amount'] ?? null) === 108.0);
});

it('stops short of paying when Xero returns no InvoiceID', function () {
    fakeXero([
        'api.xero.com/api.xro/2.0/Organisation' => Http::response([
            'Organisations' => [['Name' => 'Demo Company (Global)', 'IsDemoCompany' => true]],
        ], 200, xeroHeaders()),
        'api.xero.com/api.xro/2.0/Invoices*' => Http::response([
            'Invoices' => [['Status' => 'AUTHORISED', 'AmountDue' => 100.0]],
        ], 200, xeroHeaders()),
    ]);

    run(['action' => 'invoices.create_paid', 'params' => [
        'contact_id' => 'c-1',
        'description' => 'Training',
        'payment_account_code' => '090',
    ]])
        ->assertOk()
        ->assertJsonPath('ok', false);

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'Payments'));
});

it('stops short of paying when there is nothing outstanding', function () {
    fakeXero([
        'api.xero.com/api.xro/2.0/Organisation' => Http::response([
            'Organisations' => [['Name' => 'Demo Company (Global)', 'IsDemoCompany' => true]],
        ], 200, xeroHeaders()),
        'api.xero.com/api.xro/2.0/Invoices*' => Http::response([
            'Invoices' => [['InvoiceID' => 'inv-1', 'Status' => 'AUTHORISED', 'AmountDue' => 0.0, 'Total' => 0.0]],
        ], 200, xeroHeaders()),
    ]);

    run(['action' => 'invoices.create_paid', 'params' => [
        'contact_id' => 'c-1',
        'description' => 'Training',
        'payment_account_code' => '090',
    ]])
        ->assertOk()
        ->assertJsonPath('ok', false);

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'Payments'));
});

/*
|--------------------------------------------------------------------------
| 3 -- CC list and send
|--------------------------------------------------------------------------
*/

it('turns the CC box into ContactPersons', function () {
    fakeXero();

    run(['action' => 'invoices.send_to_contact', 'params' => [
        'id' => 'inv-1',
        'cc' => "Finance Team <finance@acme.test>\naudit@acme.test",
    ]])->assertOk()->assertJsonPath('ok', true);

    $people = null;

    Http::assertSent(function (Request $r) use (&$people) {
        if (isset($r->data()['Contacts'][0]['ContactPersons'])) {
            $people = $r->data()['Contacts'][0]['ContactPersons'];
        }

        return true;
    });

    expect($people)->toHaveCount(2)
        ->and($people[0]['FirstName'])->toBe('Finance')
        ->and($people[0]['LastName'])->toBe('Team')
        ->and($people[0]['EmailAddress'])->toBe('finance@acme.test')
        ->and($people[0]['IncludeInEmails'])->toBeTrue()
        ->and($people[1]['EmailAddress'])->toBe('audit@acme.test');
});

it('refuses more than five people on the CC list', function () {
    fakeXero();

    // Xero drops everyone past five silently, which is worse than refusing.
    run(['action' => 'invoices.send_to_contact', 'params' => [
        'id' => 'inv-1',
        'cc' => "a@x.test\nb@x.test\nc@x.test\nd@x.test\ne@x.test\nf@x.test",
    ]])
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.type', 'InvalidArgumentException');
});

it('refuses a CC entry that is not an email address', function () {
    fakeXero();

    run(['action' => 'invoices.send_to_contact', 'params' => ['id' => 'inv-1', 'cc' => 'not-an-address']])
        ->assertOk()
        ->assertJsonPath('ok', false);
});

it('refuses a tax number longer than Xero allows', function () {
    fakeXero();

    // Xero truncates past 50 characters silently; nobody notices until an audit.
    run(['action' => 'invoices.send_to_contact', 'params' => [
        'id' => 'inv-1',
        'tin' => str_repeat('X', 51),
    ]])
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.type', 'InvalidArgumentException');
});

it('authorises a draft before asking Xero to email it', function () {
    fakeXero();

    run(['action' => 'invoices.send_to_contact', 'params' => ['id' => 'inv-1']])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.authorised_now', true)
        ->assertJsonPath('data.emailed', true);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'Invoices/inv-1/Email')
        && $r->method() === 'POST');
});

it('leaves an already-authorised invoice alone', function () {
    fakeXero([
        'api.xero.com/api.xro/2.0/Organisation' => Http::response([
            'Organisations' => [['Name' => 'Demo Company (Global)', 'IsDemoCompany' => true]],
        ], 200, xeroHeaders()),
        'api.xero.com/api.xro/2.0/Invoices/*/Email' => Http::response('', 204, xeroHeaders()),
        'api.xero.com/api.xro/2.0/Invoices*' => Http::response([
            'Invoices' => [['InvoiceID' => 'inv-1', 'Status' => 'AUTHORISED', 'Contact' => ['ContactID' => 'c-1']]],
        ], 200, xeroHeaders()),
        'api.xero.com/api.xro/2.0/Contacts*' => Http::response([
            'Contacts' => [['ContactID' => 'c-1']],
        ], 200, xeroHeaders()),
    ]);

    run(['action' => 'invoices.send_to_contact', 'params' => ['id' => 'inv-1']])
        ->assertOk()
        ->assertJsonPath('data.authorised_now', false);
});

it('refuses to send an invoice that is not in Xero', function () {
    fakeXero([
        'api.xero.com/api.xro/2.0/Organisation' => Http::response([
            'Organisations' => [['Name' => 'Demo Company (Global)', 'IsDemoCompany' => true]],
        ], 200, xeroHeaders()),
        'api.xero.com/api.xro/2.0/Invoices*' => Http::response(['Invoices' => []], 200, xeroHeaders()),
    ]);

    run(['action' => 'invoices.send_to_contact', 'params' => ['id' => 'nope']])
        ->assertOk()
        ->assertJsonPath('ok', false);
});

/*
|--------------------------------------------------------------------------
| Contact lookup
|--------------------------------------------------------------------------
*/

it('refuses to guess which key a contact should be looked up by', function () {
    fakeXero();

    run(['action' => 'contacts.first_or_create', 'params' => ['lookup_by' => 'Phone', 'email' => 'a@b.test']])
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.type', 'InvalidArgumentException');
});

/*
|--------------------------------------------------------------------------
| Connection lifecycle
|--------------------------------------------------------------------------
*/

it('rotates the stored token on a forced refresh', function () {
    fakeXero();

    run(['action' => 'tokens.refresh'])
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect(XeroConnection::sole()->refresh_token)->toBe('refresh-2');
});

it('reports a forced refresh against a key that is not connected', function () {
    fakeXero();

    run(['action' => 'tokens.refresh', 'connection' => 'nothing-here'])
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.type', 'XeroConnectionNotFoundException');
});

it('deletes the stored row on forget', function () {
    fakeXero();

    run(['action' => 'connection.forget'])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.deleted', true);

    expect(XeroConnection::count())->toBe(0);
});

it('says so when forgetting a key that is not stored', function () {
    fakeXero();

    run(['action' => 'connection.forget', 'connection' => 'nothing-here'])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.deleted', false);

    expect(XeroConnection::count())->toBe(1);
});
