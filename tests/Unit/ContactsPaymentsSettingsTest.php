<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Peoplelogy\XeroBridge\Exceptions\XeroBridgeException;
use Peoplelogy\XeroBridge\Exceptions\XeroValidationException;
use Peoplelogy\XeroBridge\Facades\XeroBridge;
use Peoplelogy\XeroBridge\Resources\Payments;

beforeEach(fn () => connection());

/*
|--------------------------------------------------------------------------
| Contacts
|--------------------------------------------------------------------------
*/

it('looks up a contact by exact email, never with StartsWith', function () {
    Http::fake(['api.xero.com/*' => Http::response(['Contacts' => [['ContactID' => 'c-1']]])]);

    XeroBridge::contacts()->findByEmail('a@b.com');

    Http::assertSent(function (Request $r) {
        $url = urldecode($r->url());

        // Xero documents the exact match as the optimised form and calls
        // EmailAddress.StartsWith(...) an anti-pattern.
        return str_contains($url, 'EmailAddress=="a@b.com"')
            && ! str_contains($url, 'StartsWith');
    });
});

it('returns null when no contact matches the email', function () {
    Http::fake(['api.xero.com/*' => Http::response(['Contacts' => []])]);

    expect(XeroBridge::contacts()->findByEmail('nobody@example.com'))->toBeNull();
});

it('returns every duplicate when Xero has more than one', function () {
    Http::fake(['api.xero.com/*' => Http::response([
        'Contacts' => [['ContactID' => 'c-1'], ['ContactID' => 'c-2']],
    ])]);

    expect(XeroBridge::contacts()->findAllByEmail('a@b.com'))->toHaveCount(2);
});

it('creates contacts with PUT so a duplicate errors instead of overwriting', function () {
    Http::fake(['api.xero.com/*' => Http::response(['Contacts' => [['ContactID' => 'c-9']]])]);

    XeroBridge::contacts()->create(['Name' => 'Acme Sdn Bhd']);

    // POST would silently UPSERT on ContactName, overwriting a real customer.
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT');
});

it('does not write at all when firstOrCreate finds a match', function () {
    Http::fake(['api.xero.com/*' => Http::response(['Contacts' => [['ContactID' => 'c-1']]])]);

    expect(XeroBridge::contacts()->firstOrCreate(['EmailAddress' => 'a@b.com'])['ContactID'])
        ->toBe('c-1');

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $r) => in_array($r->method(), ['POST', 'PUT'], true));
});

it('looks up then creates when firstOrCreate finds nothing', function () {
    Http::fake(['api.xero.com/*' => Http::sequence()
        ->push(['Contacts' => []], 200)
        ->push(['Contacts' => [['ContactID' => 'c-new']]], 200)]);

    expect(XeroBridge::contacts()->firstOrCreate(['Name' => 'Brand New'])['ContactID'])
        ->toBe('c-new');

    Http::assertSentCount(2);
});

it('resolves a firstOrCreate race by re-reading the winner', function () {
    // Somebody else created the contact between our lookup and our PUT.
    Http::fake(['api.xero.com/*' => Http::sequence()
        ->push(['Contacts' => []], 200)
        ->push([
            'Elements' => [['ValidationErrors' => [['Message' => 'The contact name must be unique']]]],
        ], 400)
        ->push(['Contacts' => [['ContactID' => 'c-winner']]], 200)]);

    expect(XeroBridge::contacts()->firstOrCreate(['Name' => 'Contended'])['ContactID'])
        ->toBe('c-winner');

    Http::assertSentCount(3);
});

it('rethrows when a firstOrCreate failure was not a race', function () {
    Http::fake(['api.xero.com/*' => Http::sequence()
        ->push(['Contacts' => []], 200)
        ->push(['Elements' => [['ValidationErrors' => [['Message' => 'Something else']]]]], 400)
        ->push(['Contacts' => []], 200)]);

    expect(fn () => XeroBridge::contacts()->firstOrCreate(['Name' => 'Doomed']))
        ->toThrow(XeroValidationException::class);
});

it('requires exactly one firstOrCreate lookup key', function () {
    expect(fn () => XeroBridge::contacts()->firstOrCreate(['Name' => 'A', 'EmailAddress' => 'b@c.com']))
        ->toThrow(InvalidArgumentException::class, 'exactly one');

    Http::assertNothingSent();
});

it('enforces Xero\'s contact name rules before sending', function (string $name) {
    expect(fn () => XeroBridge::contacts()->create(['Name' => $name]))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with([
    'empty' => '',
    'too long' => [str_repeat('x', 256)],
    'angle brackets' => '<b>Acme</b>',
    'leading space' => ' Acme',
    'repeated spaces' => 'Acme  Sdn Bhd',
]);

/*
|--------------------------------------------------------------------------
| Payments
|--------------------------------------------------------------------------
*/

it('writes the payment date as a plain Y-m-d', function () {
    Http::fake(['api.xero.com/*' => Http::response(['Payments' => [['PaymentID' => 'p-1']]])]);

    XeroBridge::payments()->createForInvoice('inv-1', 1200, '090', '2026-09-28');

    Http::assertSent(function (Request $r) {
        $payment = $r->data()['Payments'][0];

        // Xero RETURNS /Date(...)/ but only ACCEPTS Y-m-d.
        return $payment['Date'] === '2026-09-28'
            && $payment['Invoice']['InvoiceID'] === 'inv-1'
            && $payment['Account']['Code'] === '090'
            && $payment['Amount'] === 1200;
    });
});

it('normalises a date round-tripped out of a Xero response', function () {
    Http::fake(['api.xero.com/*' => Http::response(['Payments' => [['PaymentID' => 'p-1']]])]);

    // Reposting a payment you just read would otherwise fail with a message
    // that never mentions dates.
    XeroBridge::payments()->create([
        'Invoice' => ['InvoiceID' => 'inv-1'],
        'Account' => ['Code' => '090'],
        'Amount' => 100,
        'Date' => '/Date(1439434356790)/',
    ]);

    Http::assertSent(fn (Request $r) => $r->data()['Payments'][0]['Date'] === '2015-08-13');
});

it('refuses a payment with no account', function () {
    expect(fn () => XeroBridge::payments()->create([
        'Invoice' => ['InvoiceID' => 'inv-1'],
        'Amount' => 100,
    ]))->toThrow(InvalidArgumentException::class, 'paymentAccounts');

    Http::assertNothingSent();
});

it('refuses a payment with no target', function () {
    expect(fn () => XeroBridge::payments()->create([
        'Account' => ['Code' => '090'],
        'Amount' => 100,
    ]))->toThrow(InvalidArgumentException::class, 'Invoice');
});

it('points a multi-invoice payment at BatchPayments', function () {
    expect(fn () => XeroBridge::payments()->create([
        'Invoices' => [['InvoiceID' => 'a'], ['InvoiceID' => 'b']],
        'Account' => ['Code' => '090'],
    ]))->toThrow(XeroBridgeException::class, 'BatchPayments');
});

it('surfaces a warning returned on a successful payment', function () {
    // A CurrencyRate warning arrives on a 200; the client only raises
    // failures, so it would otherwise be invisible -- and it can mean the
    // posted foreign-currency amount is wrong.
    Http::fake(['api.xero.com/*' => Http::response(['Payments' => [[
        'PaymentID' => 'p-1',
        'Warnings' => [['Message' => 'A currency rate was applied']],
    ]]])]);

    $payment = XeroBridge::payments()->createForInvoice('inv-1', 100, '090');

    expect($payment['Warnings'][0]['Message'])->toBe('A currency rate was applied');
});

it('deletes a payment by posting a DELETED status', function () {
    Http::fake(['api.xero.com/*' => Http::response(['Payments' => [['PaymentID' => 'p-1']]])]);

    XeroBridge::payments()->delete('p-1');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'Payments/p-1')
        && $r->data()['Payments'][0]['Status'] === 'DELETED');
});

it('has no update method', function () {
    // Xero payments cannot be modified, only created and deleted. This
    // one-line test stops a well-meaning contributor adding one.
    expect(method_exists(Payments::class, 'update'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Settings
|--------------------------------------------------------------------------
*/

it('lists the chart of accounts', function () {
    Http::fake(['api.xero.com/*' => Http::response(['Accounts' => [['Code' => '200']]])]);

    expect(XeroBridge::settings()->accounts())->toHaveCount(1);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '2.0/Accounts'));
});

it('filters down to the accounts a payment can actually use', function () {
    Http::fake(['api.xero.com/*' => Http::response(['Accounts' => [
        ['Code' => '090', 'Type' => 'BANK', 'EnablePaymentsToAccount' => false],
        ['Code' => '200', 'Type' => 'REVENUE', 'EnablePaymentsToAccount' => false],
        ['Code' => '800', 'Type' => 'CURRLIAB', 'EnablePaymentsToAccount' => true],
    ]])]);

    // Xero accepts an account only if it is BANK or has payments enabled --
    // an OR across two different fields.
    expect(array_column(XeroBridge::settings()->paymentAccounts(), 'Code'))
        ->toBe(['090', '800']);
});

it('lists the organisation tax rates', function () {
    Http::fake(['api.xero.com/*' => Http::response(['TaxRates' => [
        ['Name' => 'SST on Training', 'TaxType' => 'OUTPUT', 'EffectiveRate' => 8.0],
        ['Name' => 'SST on Rental', 'TaxType' => 'OUTPUT2', 'EffectiveRate' => 6.0],
    ]])]);

    // The org's real TaxType codes -- this is how a caller avoids hardcoding
    // a rate that differs per organisation.
    expect(array_column(XeroBridge::settings()->taxRates(), 'TaxType'))
        ->toBe(['OUTPUT', 'OUTPUT2']);
});

it('unwraps the single organisation', function () {
    Http::fake(['api.xero.com/*' => Http::response(['Organisations' => [
        ['Name' => 'Acme Sdn Bhd', 'BaseCurrency' => 'MYR', 'CountryCode' => 'MY'],
    ]])]);

    expect(XeroBridge::settings()->organisation()['BaseCurrency'])->toBe('MYR');
});

it('returns an empty array when no organisation comes back', function () {
    Http::fake(['api.xero.com/*' => Http::response(['Organisations' => []])]);

    expect(XeroBridge::settings()->organisation())->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Manager
|--------------------------------------------------------------------------
*/

it('does not leak a scoped connection into later calls', function () {
    connection(['key' => 'acme', 'tenant_id' => 'tenant-acme']);

    // The manager is a singleton behind a facade; a mutating setter here
    // would be a cross-tenant data bug.
    expect(XeroBridge::connection('acme')->key())->toBe('acme')
        ->and(XeroBridge::key())->toBe('default');
});

it('memoises the client per connection across clones', function () {
    connection(['key' => 'acme', 'tenant_id' => 'tenant-acme']);

    expect(XeroBridge::connection('acme')->client())
        ->toBe(XeroBridge::connection('acme')->client())
        ->and(XeroBridge::client())
        ->not->toBe(XeroBridge::connection('acme')->client());
});

it('inherits defaults for a connection that has no config block', function () {
    expect(XeroBridge::connection('unlisted')->defaults()->accountCode())->toBe('200')
        ->and(XeroBridge::connection('unlisted')->defaults()->currency())->toBe('MYR');
});

it('lets a named connection override a default', function () {
    config()->set('xero-bridge.connections.acme', ['account_code' => '4000']);

    expect(XeroBridge::connection('acme')->defaults()->accountCode())->toBe('4000')
        // Still inherits the rest from the default block.
        ->and(XeroBridge::connection('acme')->defaults()->currency())->toBe('MYR');
});

it('layers withDefaults on top', function () {
    expect(XeroBridge::withDefaults(['account_code' => '999'])->defaults()->accountCode())
        ->toBe('999')
        ->and(XeroBridge::defaults()->accountCode())->toBe('200');
});
