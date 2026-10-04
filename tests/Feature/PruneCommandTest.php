<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Peoplelogy\XeroBridge\Commands\PruneCommand;
use Peoplelogy\XeroBridge\Models\XeroWebhookEvent;
use Peoplelogy\XeroBridge\Models\XeroWriteRecord;
use Peoplelogy\XeroBridge\MyInvois\Models\MyInvoisValidation;
use Peoplelogy\XeroBridge\Support\TableGuard;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One command for all four tables, so a host schedules one thing rather than
 * remembering which features it enabled.
 */
function oldWebhookEvent(int $daysAgo = 90): XeroWebhookEvent
{
    return XeroWebhookEvent::create([
        'dedupe_key' => hash('sha256', 'e'.$daysAgo.uniqid()),
        'tenant_id' => 'tenant-1',
        'resource_id' => 'inv-1',
        'event_type' => 'UPDATE',
        'event_category' => 'INVOICE',
        'event_date_utc' => '2026-01-01T00:00:00.000',
        'first_seen_at' => now()->subDays($daysAgo),
        'last_seen_at' => now()->subDays($daysAgo),
        'delivery_count' => 1,
    ]);
}

function writeRecord(string $status, int $daysAgo = 200, int $minutesAgo = 0): XeroWriteRecord
{
    return XeroWriteRecord::create([
        'claim_key' => hash('sha256', uniqid('', true)),
        'connection_key' => 'default',
        'operation' => 'invoice.create',
        'status' => $status,
        'claimed_at' => $minutesAgo > 0 ? now()->subMinutes($minutesAgo) : now()->subDays($daysAgo),
        'xero_id' => $status === 'succeeded' ? 'inv-1' : null,
    ]);
}

it('prunes each table it finds', function () {
    oldWebhookEvent();
    writeRecord('succeeded');

    MyInvoisValidation::create([
        'subject_hash' => hash('sha256', 'a'),
        'id_type' => 'BRN',
        'verdict' => true,
        'environment' => 'sandbox',
        'rules_version' => 2,
        'first_checked_at' => now()->subDays(500),
        'last_checked_at' => now()->subDays(500),
        'check_count' => 1,
    ]);

    Artisan::call('xero-bridge:prune');

    expect(XeroWebhookEvent::count())->toBe(0)
        ->and(XeroWriteRecord::count())->toBe(0)
        ->and(MyInvoisValidation::count())->toBe(0);
});

it('deletes nothing on a dry run', function () {
    oldWebhookEvent();

    Artisan::call('xero-bridge:prune', ['--dry-run' => true]);

    expect(XeroWebhookEvent::count())->toBe(1)
        ->and(Artisan::output())->toContain('would delete');
});

it('never prunes a pending write claim, however old', function () {
    // A pending claim means we sent something to Xero and never learned the
    // outcome. Deleting it would free the claim and allow the duplicate the
    // ledger exists to prevent.
    writeRecord('pending', daysAgo: 400);
    writeRecord('succeeded', daysAgo: 400);

    Artisan::call('xero-bridge:prune');

    expect(XeroWriteRecord::count())->toBe(1)
        ->and(XeroWriteRecord::sole()->status)->toBe('pending');
});

it('exits non-zero and names the problem when claims are stuck', function () {
    // Wired to monitoring: a stuck claim BLOCKS further writes for that record,
    // so it must not fail silently.
    writeRecord('pending', minutesAgo: 120);

    $exit = Artisan::call('xero-bridge:prune');

    expect($exit)->toBe(PruneCommand::EXIT_STUCK_CLAIMS)
        ->and(Artisan::output())->toContain('pending');
});

it('exits cleanly when a recent claim is still in flight', function () {
    // Minutes old is normal operation, not a stuck claim.
    writeRecord('pending', minutesAgo: 2);

    expect(Artisan::call('xero-bridge:prune'))->toBe(0);
});

it('says nothing about tables that were never migrated', function () {
    // A host that never switched a feature on should not be told off by a
    // scheduled command -- on screen, or in the log. Asking TableGuard wrote a
    // warning per absent table on every run, advising a config key prune
    // never reads.
    config()->set('xero-bridge.webhooks.dedupe.table', 'absent_table');
    config()->set('xero-bridge.writes.table', 'also_absent');
    config()->set('xero-bridge.capture.table', 'absent_as_well');
    config()->set('myinvois.audit.table', 'absent_too');

    Log::spy();
    // So a TableGuard built from here on would log through the spy.
    app()->forgetInstance(TableGuard::class);

    expect(Artisan::call('xero-bridge:prune'))->toBe(0);

    expect(Artisan::output())->not->toContain('absent_table')
        ->not->toContain('not migrated');

    Log::shouldNotHaveReceived('warning');
});

it('names each table that was never migrated under -v, with the tag that creates it', function () {
    config()->set('xero-bridge.capture.table', 'absent_calls');
    config()->set('myinvois.audit.table', 'absent_verdicts');

    // A verbose output rather than '-v': symfony/console before 7.3.4 -- what
    // the prefer-lowest CI legs install -- answers '-v' by setting
    // SHELL_VERBOSITY=1 in the environment and never unsetting it, so every
    // later Artisan call in the process would run verbose too.
    $buffer = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);

    expect(Artisan::call('xero-bridge:prune', [], $buffer))->toBe(0);

    $output = (string) preg_replace('/\s+/', ' ', $buffer->fetch());

    // MyInvois ships under its own tag; the Xero tables under theirs.
    expect($output)->toMatch('/captured API calls \.+ not migrated \(--tag=xero-bridge-migrations\)/')
        ->toMatch('/MyInvois verdicts \.+ not migrated \(--tag=myinvois-migrations\)/');
});

it('reports a table it could not check, and carries on', function () {
    // An unreachable database is not a feature left off, so it is not silent.
    config()->set('database.connections.unreachable', [
        'driver' => 'sqlite',
        'database' => sys_get_temp_dir().'/xero-bridge-no-such-dir/none.sqlite',
        'prefix' => '',
    ]);
    config()->set('xero-bridge.database.connection', 'unreachable');

    $exit = Artisan::call('xero-bridge:prune');
    $output = (string) preg_replace('/\s+/', ' ', Artisan::output());

    expect($output)->toContain('Could not check for the write ledger entries table [xero_write_records]')
        ->toContain('Could not check for the webhook replay records table [xero_webhook_events]')
        // MyInvois keeps its own connection, so it is still pruned.
        ->toContain('MyInvois verdicts ')
        ->and($exit)->toBe(0);
});

it('never prunes a host table that only shares a package table name', function (string $table, string $envKey) {
    // A host's own table by a package table name: prunable() filters on age
    // alone, so 1.4.x deleted this two-year-old host row every night, whether
    // or not the feature was switched on.
    Schema::drop($table);
    Schema::create($table, function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->timestamps();
        $t->timestamp('claimed_at')->nullable();
        $t->timestamp('first_seen_at')->nullable();
        $t->timestamp('last_checked_at')->nullable();
    });
    $old = now()->subYears(2);
    DB::table($table)->insert([
        'name' => 'host row', 'created_at' => $old, 'updated_at' => $old,
        'claimed_at' => $old, 'first_seen_at' => $old, 'last_checked_at' => $old,
    ]);

    $buffer = new BufferedOutput;
    Artisan::call('xero-bridge:prune', [], $buffer);
    $output = (string) preg_replace('/\s+/', ' ', $buffer->fetch());

    expect(DB::table($table)->count())->toBe(1)
        ->and($output)->toContain("The table [{$table}] is not the package's")
        ->toContain($envKey);
})->with([
    'webhook replay records' => ['xero_webhook_events', 'XERO_WEBHOOK_DEDUPE_TABLE'],
    'write ledger' => ['xero_write_records', 'XERO_WRITES_TABLE'],
    'captured API calls' => ['xero_api_calls', 'XERO_CAPTURE_TABLE'],
    'MyInvois verdicts' => ['myinvois_validations', 'MYINVOIS_AUDIT_TABLE'],
]);
