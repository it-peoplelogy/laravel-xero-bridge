<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Running the published migrations twice must not break a deployment.
 *
 * A package migration is PUBLISHED, and its filename carries the timestamp of
 * the moment somebody published it -- not a fixed one the way an application's
 * own migration does. Two environments that publish at different moments
 * therefore end up with two different filenames for the same table. Commit one
 * of them, deploy to a server that had already published its own, and Laravel
 * sees a migration it has never run, tries to create a table that is already
 * there, and stops the deployment on 1050 "table already exists" -- halfway
 * through, with some tables created and some not.
 *
 * That is not hypothetical; it is what happened on the first deployment of
 * v1.4.2. The guard in each stub makes a second run over its OWN table a
 * no-op, so the fix for anyone who hits it is simply to run migrate again. A
 * same-named table the package did not create is refused instead -- see
 * MigrationForeignTableTest.
 */
it('survives being run a second time', function () {
    // The base TestCase has already run every migration once.
    $tables = ['xero_connections', 'xero_webhook_events', 'xero_write_records', 'xero_api_calls', 'myinvois_validations'];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} should exist before the second run");
    }

    foreach (File::allFiles(__DIR__.'/../../database/migrations') as $migration) {
        // Without the hasTable() guard in the stub this throws, and the failure
        // message is the one a consumer sees mid-deployment.
        (include $migration->getRealPath())->up();
    }

    // Still there, and still exactly one of each.
    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} should survive the second run");
    }
});

it('leaves an existing table untouched rather than rebuilding it', function () {
    // Existing and ours means done, and a column the host added on top does not
    // make the table any less ours: the stub returns early, so nothing is
    // dropped and no data is lost by a second run.
    Schema::table('xero_api_calls', function ($table) {
        $table->string('a_host_added_column')->nullable();
    });

    foreach (File::allFiles(__DIR__.'/../../database/migrations') as $migration) {
        (include $migration->getRealPath())->up();
    }

    expect(Schema::hasColumn('xero_api_calls', 'a_host_added_column'))->toBeTrue();
});
