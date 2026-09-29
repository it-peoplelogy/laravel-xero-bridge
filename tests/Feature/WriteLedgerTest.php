<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Peoplelogy\XeroBridge\Exceptions\XeroValidationException;
use Peoplelogy\XeroBridge\Exceptions\XeroWriteAlreadyClaimedException;
use Peoplelogy\XeroBridge\Facades\XeroBridge;
use Peoplelogy\XeroBridge\Models\XeroWriteRecord;
use Peoplelogy\XeroBridge\Support\TableGuard;

/**
 * The write-dedupe ledger.
 *
 * Xero honours an idempotency key for only six minutes, which no realistic
 * queue backoff stays inside -- so a job retried an hour later creates a
 * SECOND invoice. This is the defence that outlives that window.
 */

/** A stand-in for the consuming application's own record. */
class LedgerOrder extends Model
{
    protected $table = 'ledger_orders';

    protected $guarded = [];

    public $timestamps = false;
}

beforeEach(function () {
    connection();

    config()->set('xero-bridge.writes.enabled', true);
    app(TableGuard::class)->flush();

    Schema::create('ledger_orders', function ($table) {
        $table->id();
        $table->string('label')->nullable();
    });
});

function ledgerOrder(int $id = 1): LedgerOrder
{
    // firstOrCreate, because several tests deliberately reference the SAME
    // record twice -- that is the duplicate they are testing for.
    return LedgerOrder::firstOrCreate(['id' => $id], ['label' => 'order-'.$id]);
}

function fakeLedgerInvoiceCreate(): void
{
    Http::fake(['api.xero.com/*' => Http::response([
        'Invoices' => [[
            'InvoiceID' => 'inv-1',
            'InvoiceNumber' => 'INV-0042',
            'Status' => 'DRAFT',
        ]],
    ], 200, xeroHeaders())]);
}

function ledgerInvoice(): array
{
    return [
        'Type' => 'ACCREC',
        'Contact' => ['ContactID' => 'c-1'],
        'LineItems' => [['Description' => 'Training', 'Quantity' => 1, 'UnitAmount' => 100]],
    ];
}

/*
|--------------------------------------------------------------------------
| The happy path
|--------------------------------------------------------------------------
*/

it('records a write against the record it was for', function () {
    fakeLedgerInvoiceCreate();

    XeroBridge::invoices()->for(ledgerOrder())->create(ledgerInvoice());

    $row = XeroWriteRecord::sole();

    expect($row->operation)->toBe('invoice.create')
        ->and($row->owner_id)->toBe('1')
        ->and($row->owner_type)->toBe(LedgerOrder::class)
        ->and($row->connection_key)->toBe('default')
        ->and($row->xero_id)->toBe('inv-1')
        ->and($row->xero_number)->toBe('INV-0042')
        ->and($row->xero_status)->toBe('DRAFT')
        ->and($row->succeeded())->toBeTrue();
});

it('refuses a second write for the same record', function () {
    // THE POINT OF THE WHOLE TABLE. This is the duplicate invoice that a
    // six-minute idempotency key cannot prevent.
    fakeLedgerInvoiceCreate();

    XeroBridge::invoices()->for(ledgerOrder())->create(ledgerInvoice());

    try {
        XeroBridge::invoices()->for(ledgerOrder(1))->create(ledgerInvoice());
        $this->fail('Expected XeroWriteAlreadyClaimedException.');
    } catch (XeroWriteAlreadyClaimedException $e) {
        // Carries the id already created, so the caller reads it rather than
        // writing again -- the "no-op once it is set" the docs ask for.
        expect($e->xeroId())->toBe('inv-1')
            ->and($e->isPending())->toBeFalse();
    }

    // And crucially: only ONE invoice reached Xero.
    Http::assertSentCount(1);
    expect(XeroWriteRecord::count())->toBe(1);
});

it('allows two writes for one record when they are referenced differently', function () {
    // A deposit invoice and a final invoice are both legitimate.
    fakeLedgerInvoiceCreate();

    $order = ledgerOrder();

    XeroBridge::invoices()->for($order, 'deposit')->create(ledgerInvoice());
    XeroBridge::invoices()->for($order, 'final')->create(ledgerInvoice());

    expect(XeroWriteRecord::count())->toBe(2);
    Http::assertSentCount(2);
});

it('keeps writes for different records apart', function () {
    fakeLedgerInvoiceCreate();

    XeroBridge::invoices()->for(ledgerOrder(1))->create(ledgerInvoice());
    XeroBridge::invoices()->for(ledgerOrder(2))->create(ledgerInvoice());

    expect(XeroWriteRecord::count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Failure -- the half that decides whether this table helps or hurts
|--------------------------------------------------------------------------
*/

it('frees the claim when Xero proves nothing was created', function () {
    // A 400 means the payload was rejected outright. Nothing exists, so the
    // slot must be free for a corrected retry.
    Http::fake(['api.xero.com/*' => Http::response([
        'ErrorNumber' => 10,
        'Type' => 'ValidationException',
        'Message' => 'A validation exception occurred',
        'Elements' => [['ValidationErrors' => [['Message' => 'Account code is invalid']]]],
    ], 400, xeroHeaders())]);

    expect(fn () => XeroBridge::invoices()->for(ledgerOrder())->create(ledgerInvoice()))
        ->toThrow(XeroValidationException::class);

    expect(XeroWriteRecord::count())->toBe(0);
});

it('retries successfully after a rejected payload is corrected', function () {
    Http::fake(['api.xero.com/*' => Http::sequence()
        ->push(['ErrorNumber' => 10, 'Type' => 'ValidationException', 'Message' => 'bad',
            'Elements' => [['ValidationErrors' => [['Message' => 'nope']]]]], 400)
        ->push(['Invoices' => [['InvoiceID' => 'inv-2', 'Status' => 'DRAFT']]], 200)]);

    $order = ledgerOrder();

    expect(fn () => XeroBridge::invoices()->for($order)->create(ledgerInvoice()))
        ->toThrow(XeroValidationException::class);

    XeroBridge::invoices()->for($order)->create(ledgerInvoice());

    expect(XeroWriteRecord::sole()->xero_id)->toBe('inv-2');
});

it('holds the claim when the outcome is unknown, and blocks rather than re-sending', function () {
    // A 500 proves NOTHING. The invoice may well exist. Re-sending is how you
    // duplicate it, so the row stays pending and blocks at any age.
    //
    // One fake, not two: a second Http::fake() appends rather than replaces,
    // so the first stub would keep winning.
    Sleep::fake();
    Http::fake(['api.xero.com/*' => Http::sequence()
        ->push('', 500)->push('', 500)->push('', 500)
        ->push(['Invoices' => [['InvoiceID' => 'x']]], 200)]);

    try {
        XeroBridge::invoices()->for(ledgerOrder())->create(ledgerInvoice());
    } catch (Throwable) {
        // expected
    }

    $row = XeroWriteRecord::sole();
    expect($row->isPending())->toBeTrue();

    try {
        XeroBridge::invoices()->for(ledgerOrder(1))->create(ledgerInvoice());
        $this->fail('Expected the pending claim to block.');
    } catch (XeroWriteAlreadyClaimedException $e) {
        expect($e->isPending())->toBeTrue()
            ->and($e->xeroId())->toBeNull();
    }
});

it('still blocks a stale pending claim, however old', function () {
    // Deliberately no expiry. Expiring a pending claim would re-send after a
    // worker died mid-write, which is the exact duplicate this prevents.
    Sleep::fake();
    Http::fake(['api.xero.com/*' => Http::response('', 500, xeroHeaders())]);

    try {
        XeroBridge::invoices()->for(ledgerOrder())->create(ledgerInvoice());
    } catch (Throwable) {
    }

    XeroWriteRecord::query()->update(['claimed_at' => now()->subYear()]);

    expect(fn () => XeroBridge::invoices()->for(ledgerOrder(1))->create(ledgerInvoice()))
        ->toThrow(XeroWriteAlreadyClaimedException::class);
});

/*
|--------------------------------------------------------------------------
| Unowned writes, and the disabled default
|--------------------------------------------------------------------------
*/

it('records an unowned write but never deduplicates it', function () {
    // Nothing to deduplicate against. A nullable unique column would look like
    // protection while silently permitting duplicates, so unowned rows get a
    // random claim key instead.
    fakeLedgerInvoiceCreate();

    XeroBridge::invoices()->create(ledgerInvoice());
    XeroBridge::invoices()->create(ledgerInvoice());

    expect(XeroWriteRecord::count())->toBe(2);
    Http::assertSentCount(2);
});

it('behaves exactly as before when the ledger is off', function () {
    config()->set('xero-bridge.writes.enabled', false);

    fakeLedgerInvoiceCreate();

    XeroBridge::invoices()->for(ledgerOrder())->create(ledgerInvoice());
    XeroBridge::invoices()->for(ledgerOrder(1))->create(ledgerInvoice());

    expect(XeroWriteRecord::count())->toBe(0);
    Http::assertSentCount(2);
});

it('behaves exactly as before when the table is not migrated', function () {
    config()->set('xero-bridge.writes.table', 'not_a_real_table');
    app(TableGuard::class)->flush();

    fakeLedgerInvoiceCreate();

    $created = XeroBridge::invoices()->for(ledgerOrder())->create(ledgerInvoice());

    expect($created['InvoiceID'])->toBe('inv-1');
});

/*
|--------------------------------------------------------------------------
| The scoped-to-one-write rule
|--------------------------------------------------------------------------
*/

it('does not leak the owner into the next write', function () {
    // for() returns a clone, so a lingering owner cannot silently attach the
    // wrong record to a later invoice.
    fakeLedgerInvoiceCreate();

    $invoices = XeroBridge::invoices();

    $invoices->for(ledgerOrder())->create(ledgerInvoice());
    $invoices->create(ledgerInvoice());

    expect(XeroWriteRecord::whereNull('owner_type')->count())->toBe(1)
        ->and(XeroWriteRecord::whereNotNull('owner_type')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Pruning
|--------------------------------------------------------------------------
*/

it('prunes old succeeded rows but never a pending one', function () {
    // One fake with a sequence: a 200 for the first write, then 500s for the
    // second. Two separate Http::fake() calls would leave the 200 matching.
    Sleep::fake();
    Http::fake(['api.xero.com/*' => Http::sequence()
        ->push(['Invoices' => [['InvoiceID' => 'inv-1', 'Status' => 'DRAFT']]], 200)
        ->push('', 500)->push('', 500)->push('', 500)]);

    XeroBridge::invoices()->for(ledgerOrder(1))->create(ledgerInvoice());

    try {
        XeroBridge::invoices()->for(ledgerOrder(2))->create(ledgerInvoice());
    } catch (Throwable) {
    }

    XeroWriteRecord::query()->update(['claimed_at' => now()->subDays(365)]);

    $prunable = (new XeroWriteRecord)->prunable()->get();

    // A pending row is an unresolved question. Deleting it would free the
    // claim and permit the duplicate the ledger exists to prevent.
    expect($prunable)->toHaveCount(1)
        ->and($prunable->first()->status)->toBe('succeeded');
});
