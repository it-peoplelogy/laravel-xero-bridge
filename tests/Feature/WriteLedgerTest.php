<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Peoplelogy\XeroBridge\Events\XeroWriteBlocked;
use Peoplelogy\XeroBridge\Exceptions\UnsafeContactPayloadException;
use Peoplelogy\XeroBridge\Exceptions\XeroBridgeException;
use Peoplelogy\XeroBridge\Exceptions\XeroValidationException;
use Peoplelogy\XeroBridge\Exceptions\XeroWriteAlreadyClaimedException;
use Peoplelogy\XeroBridge\Exceptions\XeroWriteLedgerUnavailableException;
use Peoplelogy\XeroBridge\Facades\XeroBridge;
use Peoplelogy\XeroBridge\Models\XeroWriteRecord;
use Peoplelogy\XeroBridge\Support\TableGuard;
use Peoplelogy\XeroBridge\Writes\XeroWriteRecorder;

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

/** An invoice whose Contact block would rewrite the contact record. */
function ledgerRenamingInvoice(): array
{
    return array_merge(ledgerInvoice(), ['Contact' => ['ContactID' => 'c-1', 'Name' => 'Renamed']]);
}

/** One owned write of each kind the ledger protects. */
function ledgerWrite(string $kind, Model $owner): array
{
    return match ($kind) {
        'invoice' => XeroBridge::invoices()->for($owner)->create(ledgerInvoice()),
        'payment' => XeroBridge::payments()->for($owner)->create([
            'Invoice' => ['InvoiceID' => 'inv-1'],
            'Account' => ['Code' => '090'],
            'Amount' => 100,
        ]),
        'contact' => XeroBridge::contacts()->for($owner)->create(['Name' => 'Acme Sdn Bhd']),
    };
}

/**
 * Spy on the log the ledger writes to.
 *
 * The recorder is a singleton that keeps the logger it was built with, so it
 * is dropped here and rebuilt around the spy on first use.
 */
function ledgerLogSpy(): void
{
    Log::spy();
    app()->forgetInstance(XeroWriteRecorder::class);
}

/**
 * Make the next claim fail to record, in each way other than a missing table.
 *
 * 'not null' nulls one of the ledger's own required columns on the way in.
 * That is the portable stand-in for a NOT NULL column a host added without a
 * default: SQLite reports both as the same NOT NULL failure, under SQLSTATE
 * 23000 -- the code a duplicate also carries -- and will not ALTER such a
 * column into an existing table in the first place.
 */
function ledgerBreakRecording(string $how): void
{
    match ($how) {
        // The guard has already seen the table, and then it goes: a failed
        // insert rather than a "no table" answer.
        'insert' => (function (): void {
            app(TableGuard::class)->has(null, 'xero_write_records', 'xero-bridge-migrations');
            Schema::drop('xero_write_records');
        })(),
        'not null' => XeroWriteRecord::creating(function (XeroWriteRecord $record): void {
            $record->setAttribute('operation', null);
        }),
        'not the database' => XeroWriteRecord::creating(function (): void {
            throw new RuntimeException('database went away');
        }),
    };
}

/** The ways a claim can fail to record, and what each leaves as the cause. */
function ledgerRecordingFailures(): array
{
    return [
        'the insert fails' => ['insert', QueryException::class],
        'a required column arrives empty' => ['not null', QueryException::class],
        'something other than the database throws' => ['not the database', RuntimeException::class],
    ];
}

/** The ledger on a database connection of its own, as XERO_DB_CONNECTION allows. */
function ledgerOnItsOwnConnection(): void
{
    config()->set('database.connections.ledger', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('xero-bridge.database.connection', 'ledger');

    (include __DIR__.'/../../database/migrations/create_xero_write_records_table.php.stub')->up();
    app(TableGuard::class)->flush();
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
        // writing again -- the "no-op once it is set" the docs ask for. And
        // the connection it was for, so a multi-organisation consumer can log
        // it from context() without parsing the message.
        expect($e->xeroId())->toBe('inv-1')
            ->and($e->isPending())->toBeFalse()
            ->and($e->connectionKey())->toBe('default')
            ->and($e->context()['connection'] ?? null)->toBe('default');
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

it('does not leak an invoice opt-in through for() into later writes', function () {
    fakeLedgerInvoiceCreate();

    XeroBridge::invoices()->withContactMutation()->for(ledgerOrder(1))->create(ledgerRenamingInvoice());

    // Before the fix the shared instance kept the flag, every for() copied it
    // and only the copy cleared it -- so both of these went out unguarded.
    expect(fn () => XeroBridge::invoices()->for(ledgerOrder(2))->create(ledgerRenamingInvoice()))
        ->toThrow(UnsafeContactPayloadException::class);
    expect(fn () => XeroBridge::invoices()->create(ledgerRenamingInvoice()))
        ->toThrow(UnsafeContactPayloadException::class);

    Http::assertSentCount(1);
    // The guard refuses before a claim is taken, so no row is left behind.
    expect(XeroWriteRecord::count())->toBe(1);
});

it('honours an invoice opt-in on either side of for()', function () {
    fakeLedgerInvoiceCreate();

    XeroBridge::invoices()->for(ledgerOrder(1))->withContactMutation()->create(ledgerRenamingInvoice());
    XeroBridge::invoices()->withContactMutation()->for(ledgerOrder(2))->create(ledgerRenamingInvoice());

    Http::assertSentCount(2);
    expect(XeroWriteRecord::query()->orderBy('id')->pluck('owner_id')->all())->toBe(['1', '2']);
});

it('says that createMany() is not protected when it is named with for()', function () {
    Log::spy();
    Http::fake(['api.xero.com/*' => Http::response([
        'Invoices' => [['StatusAttributeString' => 'OK', 'InvoiceID' => 'inv-1']],
    ], 200, xeroHeaders())]);

    XeroBridge::invoices()->for(ledgerOrder())->createMany([ledgerInvoice()]);
    XeroBridge::invoices()->createMany([ledgerInvoice()]);

    // Both still go out -- refusing would break every caller already doing
    // this -- but no claim is taken, and the call that named an owner
    // expecting protection is told so.
    Http::assertSentCount(2);
    expect(XeroWriteRecord::count())->toBe(0);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'only create() is protected by the write ledger'))
        ->once();
});

/*
|--------------------------------------------------------------------------
| When the ledger itself fails -- writes.strict
|--------------------------------------------------------------------------
|
| Off (the default), a claim that cannot be recorded is logged and the write
| goes out unprotected, exactly as before strict mode existed. On, an OWNED
| write is refused instead, before anything is sent.
|
*/

it('refuses an owned write in strict mode when the table is missing', function () {
    config()->set('xero-bridge.writes.strict', true);
    config()->set('xero-bridge.writes.table', 'not_a_real_table');
    app(TableGuard::class)->flush();
    fakeLedgerInvoiceCreate();

    try {
        XeroBridge::invoices()->for(ledgerOrder())->create(ledgerInvoice());
        $this->fail('Expected XeroWriteLedgerUnavailableException.');
    } catch (XeroWriteLedgerUnavailableException $e) {
        expect($e)->toBeInstanceOf(XeroBridgeException::class)
            ->and($e->getMessage())->toContain('REFUSED')
            ->and($e->getMessage())->toContain('nothing was sent to Xero')
            ->and($e->getMessage())->toContain('XERO_WRITES_STRICT')
            ->and($e->getMessage())->toContain(LedgerOrder::class.'#1')
            ->and($e->connectionKey())->toBe('default')
            ->and($e->context()['connection'] ?? null)->toBe('default')
            ->and($e->getPrevious())->toBeInstanceOf(QueryException::class);
    }

    Http::assertNothingSent();
});

it('refuses an owned write in strict mode when its claim cannot be recorded', function (string $how, string $cause) {
    config()->set('xero-bridge.writes.strict', true);
    fakeLedgerInvoiceCreate();
    ledgerBreakRecording($how);

    try {
        XeroBridge::invoices()->for(ledgerOrder())->create(ledgerInvoice());
        $this->fail('Expected XeroWriteLedgerUnavailableException.');
    } catch (XeroWriteLedgerUnavailableException $e) {
        // A required column arriving empty shares SQLSTATE 23000 with a
        // duplicate. It used to come back as a "pending" collision, which the
        // documented catch recipe drops without a word.
        expect($e->getPrevious())->toBeInstanceOf($cause);
    }

    Http::assertNothingSent();
})->with(ledgerRecordingFailures());

it('sends that write unprotected, and says so, when strict mode is off', function (string $how) {
    ledgerLogSpy();
    fakeLedgerInvoiceCreate();
    ledgerBreakRecording($how);

    $created = XeroBridge::invoices()->for(ledgerOrder())->create(ledgerInvoice());

    // Today's fail-open, unchanged: never mistaken for a duplicate.
    expect($created['InvoiceID'])->toBe('inv-1');
    Http::assertSentCount(1);
    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message) => str_contains($message, 'WITHOUT duplicate protection'))
        ->once();
})->with(ledgerRecordingFailures());

it('refuses an owned payment and contact in strict mode too', function (string $kind) {
    config()->set('xero-bridge.writes.strict', true);
    config()->set('xero-bridge.writes.table', 'not_a_real_table');
    app(TableGuard::class)->flush();
    Http::fake(['api.xero.com/*' => Http::response([], 200, xeroHeaders())]);

    expect(fn () => ledgerWrite($kind, ledgerOrder()))
        ->toThrow(XeroWriteLedgerUnavailableException::class, $kind.'.create');

    Http::assertNothingSent();
})->with(['invoice', 'payment', 'contact']);

it('ignores a stale negative table check in strict mode', function () {
    // A worker that looked before the table existed, then had it migrated
    // underneath it. The guard remembers "no table" for the whole process.
    config()->set('xero-bridge.writes.strict', true);
    fakeLedgerInvoiceCreate();

    Schema::drop('xero_write_records');
    expect(app(TableGuard::class)->has(null, 'xero_write_records', 'xero-bridge-migrations'))->toBeFalse();
    (include __DIR__.'/../../database/migrations/create_xero_write_records_table.php.stub')->up();

    XeroBridge::invoices()->for(ledgerOrder())->create(ledgerInvoice());

    // The insert decided, not the stale answer: the write is protected.
    expect(XeroWriteRecord::sole()->succeeded())->toBeTrue();
});

it('reads a writes block that has no strict key as strict mode off', function () {
    // A config published before the key existed, cached before the package
    // could fill it in.
    config()->set('xero-bridge.writes', ['enabled' => true, 'table' => 'not_a_real_table']);
    app(TableGuard::class)->flush();
    fakeLedgerInvoiceCreate();

    $created = XeroBridge::invoices()->for(ledgerOrder())->create(ledgerInvoice());

    expect($created['InvoiceID'])->toBe('inv-1');
});

it('never refuses an unowned write in strict mode', function () {
    // Nothing to deduplicate it against, so refusing it would be an outage
    // bought for no protection.
    config()->set('xero-bridge.writes.strict', true);
    config()->set('xero-bridge.writes.table', 'not_a_real_table');
    app(TableGuard::class)->flush();
    fakeLedgerInvoiceCreate();

    $created = XeroBridge::invoices()->create(ledgerInvoice());

    expect($created['InvoiceID'])->toBe('inv-1');
    Http::assertSentCount(1);
});

it('still refuses a duplicate in strict mode, as a duplicate', function () {
    config()->set('xero-bridge.writes.strict', true);
    fakeLedgerInvoiceCreate();

    XeroBridge::invoices()->for(ledgerOrder())->create(ledgerInvoice());

    // The ledger worked, so this is the "stop" exception, not the
    // "retry is safe" one.
    expect(fn () => XeroBridge::invoices()->for(ledgerOrder(1))->create(ledgerInvoice()))
        ->toThrow(XeroWriteAlreadyClaimedException::class);

    Http::assertSentCount(1);
});

it('does not refuse a write inside a transaction in strict mode', function () {
    // A consumer's own suite runs inside RefreshDatabase's transaction, so
    // this stays a warning in every mode.
    config()->set('xero-bridge.writes.strict', true);
    ledgerLogSpy();
    fakeLedgerInvoiceCreate();
    $order = ledgerOrder();

    DB::beginTransaction();

    try {
        XeroBridge::invoices()->for($order)->create(ledgerInvoice());

        expect(XeroWriteRecord::count())->toBe(1);
    } finally {
        DB::rollBack();
    }

    Http::assertSentCount(1);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'inside a database transaction'))
        ->once();
});

/*
|--------------------------------------------------------------------------
| A duplicate is a duplicate, and nothing else is
|--------------------------------------------------------------------------
*/

it('still refuses a duplicate whose insert fails on another constraint first', function () {
    // NOT NULL is checked before the unique index, so the second insert fails
    // with that -- which says nothing about whether the claim is held. It is,
    // and sending again would create the second invoice.
    fakeLedgerInvoiceCreate();

    XeroBridge::invoices()->for(ledgerOrder())->create(ledgerInvoice());

    ledgerBreakRecording('not null');

    try {
        XeroBridge::invoices()->for(ledgerOrder(1))->create(ledgerInvoice());
        $this->fail('Expected XeroWriteAlreadyClaimedException.');
    } catch (XeroWriteAlreadyClaimedException $e) {
        expect($e->xeroId())->toBe('inv-1');
    }

    Http::assertSentCount(1);
});

it('looks for a surrounding transaction on the ledger connection', function () {
    // The dangerous case: the claim itself would be rolled back.
    ledgerOnItsOwnConnection();
    ledgerLogSpy();
    $order = ledgerOrder();

    DB::connection('ledger')->beginTransaction();

    try {
        app(XeroWriteRecorder::class)->claim('default', 'invoice.create', $order);
    } finally {
        DB::connection('ledger')->rollBack();
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'inside a database transaction'))
        ->once();
});

it('ignores a transaction on a connection the ledger does not use', function () {
    // Harmless: the claim is committed on its own connection, so the old
    // check on the default connection warned about nothing.
    ledgerOnItsOwnConnection();
    ledgerLogSpy();
    $order = ledgerOrder();

    DB::beginTransaction();

    try {
        $claim = app(XeroWriteRecorder::class)->claim('default', 'invoice.create', $order);
    } finally {
        DB::rollBack();
    }

    expect($claim->isHeld())->toBeTrue();
    Log::shouldNotHaveReceived('warning');
});

/*
|--------------------------------------------------------------------------
| Blocks are announced -- XeroWriteBlocked
|--------------------------------------------------------------------------
*/

it('announces a confirmed block', function () {
    Event::fake([XeroWriteBlocked::class]);
    fakeLedgerInvoiceCreate();

    XeroBridge::invoices()->for(ledgerOrder())->create(ledgerInvoice());

    expect(fn () => XeroBridge::invoices()->for(ledgerOrder(1))->create(ledgerInvoice()))
        ->toThrow(XeroWriteAlreadyClaimedException::class);

    $claimedAt = XeroWriteRecord::sole()->claimed_at;

    Event::assertDispatchedTimes(XeroWriteBlocked::class, 1);
    Event::assertDispatched(XeroWriteBlocked::class, fn (XeroWriteBlocked $e) => $e->connectionKey === 'default'
        && $e->operation === 'invoice.create'
        && $e->ownerType === LedgerOrder::class
        && $e->ownerId === '1'
        && $e->reference === null
        && $e->pending === false
        && $e->xeroId === 'inv-1'
        && $e->claimedAt?->equalTo($claimedAt) === true);
    Http::assertSentCount(1);
});

it('announces a pending block with the age of the claim', function () {
    Event::fake([XeroWriteBlocked::class]);
    Sleep::fake();
    Http::fake(['api.xero.com/*' => Http::response('', 500, xeroHeaders())]);

    try {
        XeroBridge::invoices()->for(ledgerOrder(), 'deposit')->create(ledgerInvoice());
    } catch (Throwable) {
        // expected: the outcome is unknown, so the claim stays pending
    }

    // Two hours on, no worker is still racing for it: this is the stuck claim
    // worth paging on, and claimedAt is what tells it from a race.
    XeroWriteRecord::query()->update(['claimed_at' => now()->subHours(2)]);
    $claimedAt = XeroWriteRecord::sole()->claimed_at;

    expect(fn () => XeroBridge::invoices()->for(ledgerOrder(1), 'deposit')->create(ledgerInvoice()))
        ->toThrow(XeroWriteAlreadyClaimedException::class);

    Event::assertDispatched(XeroWriteBlocked::class, fn (XeroWriteBlocked $e) => $e->pending
        && $e->xeroId === null
        && $e->reference === 'deposit'
        && $e->claimedAt?->equalTo($claimedAt) === true
        && $e->claimedAt->lessThan(now()->subMinutes(5)));
});

it('announces nothing for a write that went through, or while the ledger is off', function () {
    Event::fake([XeroWriteBlocked::class]);
    fakeLedgerInvoiceCreate();

    XeroBridge::invoices()->for(ledgerOrder())->create(ledgerInvoice());

    config()->set('xero-bridge.writes.enabled', false);
    XeroBridge::invoices()->for(ledgerOrder(1))->create(ledgerInvoice());

    Event::assertNotDispatched(XeroWriteBlocked::class);
    Http::assertSentCount(2);
});

it('never lets a failing XeroWriteBlocked listener change what the caller catches', function () {
    ledgerLogSpy();
    Event::listen(XeroWriteBlocked::class, function (): void {
        throw new RuntimeException('listener broke');
    });
    fakeLedgerInvoiceCreate();

    XeroBridge::invoices()->for(ledgerOrder())->create(ledgerInvoice());

    // Still the exception the documented recipe catches -- with the id to
    // read -- not the listener's, which would fail the job into a retry loop.
    try {
        XeroBridge::invoices()->for(ledgerOrder(1))->create(ledgerInvoice());
        $this->fail('Expected XeroWriteAlreadyClaimedException.');
    } catch (XeroWriteAlreadyClaimedException $e) {
        expect($e->xeroId())->toBe('inv-1');
    }

    Http::assertSentCount(1);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'XeroWriteBlocked listener failed'))
        ->once();
});

it('announces a claim it cannot read as in flight, and still refuses the write', function () {
    // XeroWriteBlocked's contract: claimedAt is null when the existing row
    // could not be read, which a listener treats as pending of unknown age.
    // And the caller still gets the exception the documented recipe catches,
    // never the read error -- nothing is sent twice either way.
    Event::fake([XeroWriteBlocked::class]);
    fakeLedgerInvoiceCreate();

    XeroBridge::invoices()->for(ledgerOrder())->create(ledgerInvoice());

    XeroWriteRecord::retrieved(function (): void {
        throw new RuntimeException('read failed');
    });

    try {
        XeroBridge::invoices()->for(ledgerOrder(1))->create(ledgerInvoice());
        $this->fail('Expected XeroWriteAlreadyClaimedException.');
    } catch (XeroWriteAlreadyClaimedException $e) {
        expect($e->isPending())->toBeTrue()
            ->and($e->xeroId())->toBeNull();
    }

    Event::assertDispatched(XeroWriteBlocked::class, fn (XeroWriteBlocked $e) => $e->pending
        && $e->claimedAt === null
        && $e->xeroId === null);
    Http::assertSentCount(1);
});

/*
|--------------------------------------------------------------------------
| Reading the ledger -- existingId()
|--------------------------------------------------------------------------
*/

it('reads back the Xero id of a write that succeeded, and of nothing else', function () {
    fakeLedgerInvoiceCreate();

    $recorder = app(XeroWriteRecorder::class);
    $order = ledgerOrder();

    expect($recorder->existingId('default', 'invoice.create', $order))->toBeNull();

    XeroBridge::invoices()->for($order)->create(ledgerInvoice());

    expect($recorder->existingId('default', 'invoice.create', $order))->toBe('inv-1');

    // A claim whose outcome was never recorded: nothing proves the invoice
    // exists, so there is no id to hand back, whatever the row holds.
    XeroWriteRecord::query()->update(['status' => XeroWriteRecord::STATUS_PENDING]);

    expect($recorder->existingId('default', 'invoice.create', $order))->toBeNull();

    // And with the ledger off, it is not consulted at all.
    XeroWriteRecord::query()->update(['status' => XeroWriteRecord::STATUS_SUCCEEDED]);
    config()->set('xero-bridge.writes.enabled', false);

    expect($recorder->existingId('default', 'invoice.create', $order))->toBeNull();
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
