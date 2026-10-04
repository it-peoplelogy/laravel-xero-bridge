<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Peoplelogy\XeroBridge\Support\TableGuard;
use Psr\Log\LoggerInterface;

/**
 * TableGuard is what lets a consumer upgrade without migrating: a recorder
 * whose table is not there is a silent no-op, and does not ask the database
 * again on every call. These pin how long it believes each kind of answer.
 *
 * The connection under test is a SQLite file. It starts pointed at a path
 * that does not exist, so every check throws; "repairing" it points it at a
 * real, empty database file and purges the cached connection, the way a
 * worker sees a database come back.
 */
beforeEach(function () {
    config()->set('database.connections.guarded', [
        'driver' => 'sqlite',
        'database' => sys_get_temp_dir().'/xero-bridge-no-such-dir/guarded.sqlite',
        'prefix' => '',
    ]);
});

afterEach(function () {
    DB::purge('guarded');
    @unlink(guardDatabase());
});

/**
 * Where the repaired database lives: one file per process, so parallel runs
 * never share it.
 */
function guardDatabase(): string
{
    return sys_get_temp_dir().'/xero-bridge-guard-'.getmypid().'.sqlite';
}

/**
 * Built after any Log::spy(), so its warnings land on the spy.
 */
function guardUnderTest(): TableGuard
{
    return new TableGuard(app(LoggerInterface::class));
}

/**
 * The database comes back -- with the table, or without it. An empty file is
 * an empty SQLite database.
 */
function guardRepair(bool $withTable = true): void
{
    @unlink(guardDatabase());
    touch(guardDatabase());

    config()->set('database.connections.guarded.database', guardDatabase());
    DB::purge('guarded');

    if ($withTable) {
        Schema::connection('guarded')->create('xero_write_records', function (Blueprint $table) {
            $table->id();
        });
    }
}

it('answers no when the database cannot be asked, and does not throw', function () {
    expect(guardUnderTest()->has('guarded', 'xero_write_records', 'xero-bridge-migrations'))->toBeFalse();
});

it('asks again once the window has passed, and believes the answer it gets', function () {
    // Remembered for the whole process, one blip at a worker's first write
    // would switch recording -- and the write ledger's protection -- off
    // until the worker restarted.
    $guard = guardUnderTest();

    expect($guard->has('guarded', 'xero_write_records', 'xero-bridge-migrations'))->toBeFalse();

    guardRepair();
    $this->travel(TableGuard::RETRY_AFTER + 1)->seconds();

    expect($guard->has('guarded', 'xero_write_records', 'xero-bridge-migrations'))->toBeTrue();
});

it('does not ask again within the window', function () {
    // Through an outage, every call would otherwise wait out a connection
    // timeout before the Xero call it is recording.
    $guard = guardUnderTest();
    $guard->has('guarded', 'xero_write_records', 'xero-bridge-migrations');

    guardRepair();
    $this->travel(TableGuard::RETRY_AFTER - 1)->seconds();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    // The repaired database would answer yes -- it is simply not asked.
    expect($guard->has('guarded', 'xero_write_records', 'xero-bridge-migrations'))->toBeFalse()
        ->and($queries)->toBe(0);
});

it('keeps a definitive answer for the life of the process', function () {
    guardRepair();
    $guard = guardUnderTest();

    expect($guard->has('guarded', 'xero_write_records', 'xero-bridge-migrations'))->toBeTrue()
        ->and($guard->has('guarded', 'xero_api_calls', 'xero-bridge-migrations'))->toBeFalse();

    Schema::connection('guarded')->drop('xero_write_records');
    Schema::connection('guarded')->create('xero_api_calls', function (Blueprint $table) {
        $table->id();
    });
    $this->travel(1)->hours();

    // Unchanged: a yes or a no is the answer, and asking again on every write
    // would put a round trip on the hot path forever.
    expect($guard->has('guarded', 'xero_write_records', 'xero-bridge-migrations'))->toBeTrue()
        ->and($guard->has('guarded', 'xero_api_calls', 'xero-bridge-migrations'))->toBeFalse();
});

it('warns once for a check that keeps failing, and once more for a table that is absent', function () {
    Log::spy();
    $guard = guardUnderTest();

    foreach (range(1, 3) as $attempt) {
        $guard->has('guarded', 'xero_write_records', 'xero-bridge-migrations');
        $this->travel(TableGuard::RETRY_AFTER + 1)->seconds();
    }

    // Back, but without the table: a different fact, worth its own line.
    guardRepair(withTable: false);

    expect($guard->has('guarded', 'xero_write_records', 'xero-bridge-migrations'))->toBeFalse()
        ->and($guard->has('guarded', 'xero_write_records', 'xero-bridge-migrations'))->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'could not check for the table [xero_write_records]'))
        ->once();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'the table [xero_write_records] does not exist'))
        ->once();
});

it('forgets a failed check on flush()', function () {
    $guard = guardUnderTest();
    $guard->has('guarded', 'xero_write_records', 'xero-bridge-migrations');

    guardRepair();
    $guard->flush();

    expect($guard->has('guarded', 'xero_write_records', 'xero-bridge-migrations'))->toBeTrue();
});
