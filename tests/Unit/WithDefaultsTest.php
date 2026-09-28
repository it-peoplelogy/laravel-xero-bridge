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
