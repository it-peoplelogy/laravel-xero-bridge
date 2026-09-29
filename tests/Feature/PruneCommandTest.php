<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Peoplelogy\XeroBridge\Commands\PruneCommand;
use Peoplelogy\XeroBridge\Models\XeroWebhookEvent;
use Peoplelogy\XeroBridge\Models\XeroWriteRecord;
use Peoplelogy\XeroBridge\MyInvois\Models\MyInvoisValidation;
use Peoplelogy\XeroBridge\Support\TableGuard;

/**
 * One command for all three tables, so a host schedules one thing rather than
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
    // scheduled command.
    config()->set('xero-bridge.webhooks.dedupe.table', 'absent_table');
    config()->set('xero-bridge.writes.table', 'also_absent');
    config()->set('myinvois.audit.table', 'absent_too');
    app(TableGuard::class)->flush();

    expect(Artisan::call('xero-bridge:prune'))->toBe(0);

    expect(Artisan::output())->not->toContain('absent_table');
});
