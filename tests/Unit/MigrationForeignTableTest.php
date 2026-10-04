<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A package migration must never adopt a table it did not create.
 *
 * In 1.4.3 every stub returned early on hasTable() alone. A host that already
 * had a table of that name -- an earlier integration's xero_connections, say --
 * saw migrate succeed and the migration recorded as run over a table the
 * package cannot use. Worse, every down() dropped unconditionally, so the
 * README's uninstall step deleted the host's own table.
 *
 * Each stub now checks that an existing table carries its full original
 * column set (the stub's private COLUMNS) before calling it its own. up()
 * refuses a stranger's table with a message naming the setting that moves the
 * package elsewhere; down() leaves one alone.
 *
 * Every test starts from the base TestCase, which has already run every stub
 * against a fresh in-memory database, so a test may drop and rebuild package
 * tables freely: the next one starts clean.
 */

/**
 * One row per stub: the file, its default table, and the env variable and
 * config key its refusal must name.
 *
 * @return array<string, array{string, string, string, string}>
 */
function migrationStubCases(): array
{
    return [
        'xero_connections' => [
            'create_xero_connections_table.php.stub', 'xero_connections',
            'XERO_DB_TABLE', 'xero-bridge.database.table',
        ],
        'xero_write_records' => [
            'create_xero_write_records_table.php.stub', 'xero_write_records',
            'XERO_WRITES_TABLE', 'xero-bridge.writes.table',
        ],
        'xero_webhook_events' => [
            'create_xero_webhook_events_table.php.stub', 'xero_webhook_events',
            'XERO_WEBHOOK_DEDUPE_TABLE', 'xero-bridge.webhooks.dedupe.table',
        ],
        'xero_api_calls' => [
            'create_xero_api_calls_table.php.stub', 'xero_api_calls',
            'XERO_CAPTURE_TABLE', 'xero-bridge.capture.table',
        ],
        'myinvois_validations' => [
            'create_myinvois_validations_table.php.stub', 'myinvois_validations',
            'MYINVOIS_AUDIT_TABLE', 'myinvois.audit.table',
        ],
    ];
}

/**
 * A fresh instance of a shipped stub, exactly as a host would run it.
 */
function migrationStub(string $file): Migration
{
    return include __DIR__.'/../../database/migrations/'.$file;
}

/**
 * A same-named table the package did not create, holding one row of the
 * host's own data.
 */
function createForeignTable(string $name, ?string $connection = null): void
{
    Schema::connection($connection)->create($name, function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    DB::connection($connection)->table($name)->insert([
        'name' => "the host's own row",
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Run a stub's up() and hand back whatever it threw, or null.
 *
 * Caught as Throwable, not RuntimeException, on purpose: Laravel's
 * QueryException IS a RuntimeException, so without the guard the 1050 "table
 * already exists" from create() would pass for a refusal.
 */
function migrationRefusal(Migration $migration): ?Throwable
{
    try {
        $migration->up();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

it('covers every migration the package ships', function () {
    // A new stub has to join the cases above, or none of these tests see it.
    $shipped = array_map('basename', glob(__DIR__.'/../../database/migrations/*.php.stub'));
    $covered = array_column(migrationStubCases(), 0);

    sort($shipped);
    sort($covered);

    expect($covered)->toBe($shipped);
});

it('refuses a same-named table it did not create, and changes nothing', function (string $stub, string $table, string $env, string $key) {
    Schema::drop($table);
    createForeignTable($table);

    $columns = Schema::getColumnListing($table);
    $indexes = Schema::getIndexes($table);

    $refusal = migrationRefusal(migrationStub($stub));

    expect($refusal)->not->toBeNull("up() adopted a {$table} it did not create")
        // Exactly RuntimeException: a published copy outlives `composer
        // remove`, so it can never throw a class from this package.
        ->and($refusal::class)->toBe(RuntimeException::class)
        ->and($refusal->getMessage())
        ->toContain("Table \"{$table}\" on database connection \"testing\"")
        ->toContain('Nothing was changed')
        ->toContain("set {$env} (config key {$key})")
        ->toContain('config:clear')
        ->toContain('drop or rename it');

    // Refused before any DDL: the same columns, the same indexes, the row.
    expect(Schema::getColumnListing($table))->toBe($columns)
        ->and(Schema::getIndexes($table))->toBe($indexes)
        ->and(DB::table($table)->pluck('name')->all())->toBe(["the host's own row"]);
})->with(migrationStubCases());

it("refuses an earlier integration's token table, though it shares the token columns", function () {
    // The lookalike a first-time adopter is most likely to have: Xero tokens
    // keyed by tenant, left by whatever integration came before. A guard built
    // on generic columns would take it for ours.
    Schema::drop('xero_connections');

    Schema::create('xero_connections', function (Blueprint $table) {
        $table->id();
        $table->string('tenant_id');
        $table->text('access_token');
        $table->text('refresh_token');
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });

    DB::table('xero_connections')->insert([
        'tenant_id' => 'tenant-1',
        'access_token' => 'theirs',
        'refresh_token' => 'theirs',
    ]);

    $migration = migrationStub('create_xero_connections_table.php.stub');
    $refusal = migrationRefusal($migration);

    expect($refusal)->not->toBeNull('up() adopted a lookalike token table')
        ->and($refusal->getMessage())
        // Named by the columns only this package has, and counted by what is
        // missing: 11 of the 18, three of them quoted.
        ->toContain('missing columns: "key", "invalidated_at", "invalidated_reason" and 8 more')
        ->not->toContain('"tenant_id"')
        ->toContain('set XERO_DB_TABLE (config key xero-bridge.database.table)');

    // And a rollback -- the README's uninstall step -- leaves it as well.
    $migration->down();

    expect(DB::table('xero_connections')->pluck('access_token')->all())->toBe(['theirs']);
});

it('names the physical table, connection prefix included', function (string $stub, string $table) {
    config()->set('database.connections.prefixed', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => 'app_',
        'prefix_indexes' => true,
    ]);

    // BOTH, as in TablePrefixTest: MyInvois reads its own database config.
    config()->set('xero-bridge.database.connection', 'prefixed');
    config()->set('myinvois.database.connection', 'prefixed');

    createForeignTable($table, 'prefixed');

    $refusal = migrationRefusal(migrationStub($stub));

    // The name to look for in a database client is the prefixed one; the
    // name to put in the setting is the bare one, and the message says so.
    expect($refusal)->not->toBeNull("up() adopted app_{$table}")
        ->and($refusal->getMessage())
        ->toContain("Table \"app_{$table}\" on database connection \"prefixed\"")
        ->toContain('bare table name');
})->with(migrationStubCases());

it('rolls back only a table it created', function (string $stub, string $table) {
    $migration = migrationStub($stub);

    // Its own table, made by the base TestCase, still goes.
    $migration->down();

    expect(Schema::hasTable($table))->toBeFalse();

    // Nothing left to drop is not an error.
    $migration->down();

    // A stranger's table of the same name stays, row and all.
    createForeignTable($table);
    $migration->down();

    expect(Schema::hasTable($table))->toBeTrue()
        ->and(DB::table($table)->pluck('name')->all())->toBe(["the host's own row"]);
})->with(migrationStubCases());

it('recognises the table it creates as its own', function (string $stub, string $table) {
    config()->set('database.connections.fresh', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);

    config()->set('xero-bridge.database.connection', 'fresh');
    config()->set('myinvois.database.connection', 'fresh');

    $migration = migrationStub($stub);
    $migration->up();

    $created = Schema::connection('fresh')->getColumnListing($table);
    $fingerprint = (new ReflectionObject($migration))->getConstant('COLUMNS');

    expect($fingerprint)->toBeArray()->not->toBeEmpty()
        // Every name in COLUMNS is one create() really makes; otherwise the
        // stub would refuse its own table on the next run.
        ->and(array_values(array_diff($fingerprint, $created)))->toBe([])
        // And it is the FULL set: a few generic names would let a lookalike
        // through. create() never changes -- a new column arrives in a
        // migration of its own -- so this holds for good.
        ->and(array_values(array_diff($created, $fingerprint)))->toBe([]);

    // So a second run over it is no refusal, and a rollback still drops it.
    $migration->up();
    $migration->down();

    expect(Schema::connection('fresh')->hasTable($table))->toBeFalse();
})->with(migrationStubCases());
