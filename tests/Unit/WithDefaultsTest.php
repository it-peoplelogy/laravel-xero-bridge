<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Peoplelogy\XeroBridge\Facades\XeroBridge;

/**
 * Regression tests for a silent cross-call bug.
 *
 * Resources are memoised per connection with their ConnectionDefaults baked in
 * at construction. The memo key does not include withDefaults() overrides, so
 * before the fix the cached instance either ignored an override entirely, or --
 * far worse -- kept serving it to every later plain call on the same
 * connection. Both directions are pinned below, because a leak of this kind
 * puts the wrong account code on a real invoice and nothing fails loudly.
 */
beforeEach(function () {
    connection();
    Http::fake(['api.xero.com/*' => Http::response([
        'Invoices' => [['InvoiceID' => 'inv-1']],
    ])]);
});

function sentAccountCode(int $index = 0): ?string
{
    $codes = [];

    Http::assertSent(function (Request $r) use (&$codes) {
        if ($r->method() === 'POST') {
            $codes[] = $r->data()['Invoices'][0]['LineItems'][0]['AccountCode'] ?? null;
        }

        return true;
    });

    return $codes[$index] ?? null;
}

function draft(): array
{
    return [
        'Type' => 'ACCREC',
        'Contact' => ['ContactID' => 'c-1'],
        'LineItems' => [['Description' => 'Training', 'Quantity' => 1, 'UnitAmount' => 100]],
    ];
}

/** The account code on the Nth payment sent, counting payments only. */
function sentPaymentAccountCode(int $index = 0): ?string
{
    $codes = [];

    Http::assertSent(function (Request $r) use (&$codes) {
        if ($r->method() === 'POST' && str_ends_with(parse_url($r->url(), PHP_URL_PATH) ?: '', '/Payments')) {
            $codes[] = $r->data()['Payments'][0]['Account']['Code'] ?? null;
        }

        return true;
    });

    return $codes[$index] ?? null;
}

it('applies an override even when the resource was already resolved', function () {
    // Resolve and use the memoised instance first.
    XeroBridge::invoices()->create(draft());
    expect(sentAccountCode(0))->toBe('200');

    // Before the fix this was silently ignored and still sent 200.
    XeroBridge::withDefaults(['account_code' => '4000'])->invoices()->create(draft());

    expect(sentAccountCode(1))->toBe('4000');
});

it('does not leak an override into later plain calls', function () {
    XeroBridge::withDefaults(['account_code' => '4000'])->invoices()->create(draft());
    expect(sentAccountCode(0))->toBe('4000');

    // Before the fix the overridden instance had been cached, so this sent
    // 4000 too -- the wrong account code on an unrelated invoice.
    XeroBridge::invoices()->create(draft());

    expect(sentAccountCode(1))->toBe('200');
});

it('keeps overrides scoped per connection', function () {
    connection(['key' => 'acme', 'tenant_id' => 'tenant-acme']);

    XeroBridge::connection('acme')->withDefaults(['account_code' => '4000'])->invoices()->create(draft());
    XeroBridge::connection('acme')->invoices()->create(draft());
    XeroBridge::invoices()->create(draft());

    expect(sentAccountCode(0))->toBe('4000')
        ->and(sentAccountCode(1))->toBe('200')
        ->and(sentAccountCode(2))->toBe('200');
});

it('still memoises when no override is in play', function () {
    // The fast path must survive: identical instance for repeated plain calls.
    expect(XeroBridge::invoices())->toBe(XeroBridge::invoices());
});

it('returns a fresh instance when an override is in play', function () {
    $overridden = XeroBridge::withDefaults(['account_code' => '4000'])->invoices();

    expect($overridden)->not->toBe(XeroBridge::invoices());
});

/*
| The same two directions for payments(), whose payment_account_code is read
| from the same defaults. The docs said for years that this override was
| ignored once payments() had been resolved; these pin what the code does.
*/

it('applies a payment account override even when payments() was already resolved', function () {
    // Resolve and use the memoised instance first.
    XeroBridge::payments()->createForInvoice('inv-0', 50, '091');

    XeroBridge::withDefaults(['payment_account_code' => '090'])->payments()->createForInvoice('inv-1', 100);

    expect(sentPaymentAccountCode(0))->toBe('091')
        ->and(sentPaymentAccountCode(1))->toBe('090');
});

it('does not leak a payment account override into a later plain payments() call', function () {
    XeroBridge::withDefaults(['payment_account_code' => '090'])->payments()->createForInvoice('inv-1', 100);
    expect(sentPaymentAccountCode(0))->toBe('090');

    // Neither the shipped config nor this suite sets payment_account_code, so
    // a plain call has nothing to fall back on -- unless the override leaked.
    expect(fn () => XeroBridge::payments()->createForInvoice('inv-2', 100))
        ->toThrow(InvalidArgumentException::class, 'paymentAccounts');
});

it('applies an override to every resource taken from a held clone', function () {
    // The plain instances exist first, so a clone served from the memo would
    // send their defaults instead.
    XeroBridge::invoices();
    XeroBridge::payments();

    $xero = XeroBridge::withDefaults(['account_code' => '4000', 'payment_account_code' => '090']);

    $xero->invoices()->create(draft());
    $xero->invoices()->create(draft());
    $xero->payments()->createForInvoice('inv-1', 100);

    expect(sentAccountCode(0))->toBe('4000')
        ->and(sentAccountCode(1))->toBe('4000')
        ->and(sentPaymentAccountCode(0))->toBe('090');
});
