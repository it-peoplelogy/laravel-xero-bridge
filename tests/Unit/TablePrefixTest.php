<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Peoplelogy\XeroBridge\Models\XeroConnection;

/**
 * pips sets prefix 'pips_' with prefix_indexes on its mysql connection, and
 * its models declare table names WITHOUT the prefix. A package that hardcoded
 * a prefix, or named its indexes by hand, would break there. These tests run
 * the real migration against a prefixed connection and prove the package
 * neither adds nor assumes a prefix of its own.
 */
beforeEach(function () {
    config()->set('database.connections.prefixed', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => 'pips_',
        'prefix_indexes' => true,
    ]);

    config()->set('xero-bridge.database.connection', 'prefixed');

    foreach (File::allFiles(__DIR__.'/../../database/migrations') as $migration) {
        (include $migration->getRealPath())->up();
    }
});

it('lets the host connection apply its own prefix', function () {
    $schema = Schema::connection('prefixed');

    // Schema::hasTable() goes through the same prefix, so it sees the bare
    // name; the underlying table is physically pips_xero_connections.
    expect($schema->hasTable('xero_connections'))->toBeTrue();

    $physical = DB::connection('prefixed')
        ->select("select name from sqlite_master where type='table' and name='pips_xero_connections'");

    expect($physical)->toHaveCount(1);
});

it('reads and writes through the prefixed connection', function () {
    $connection = connection(['key' => 'prefixed-key', 'tenant_id' => 'tenant-prefixed']);

    expect($connection->getConnectionName())->toBe('prefixed')
        ->and($connection->getTable())->toBe('xero_connections')
        ->and(XeroConnection::forKey('prefixed-key')->exists())->toBeTrue();
});

it('honours a custom table name', function () {
    config()->set('xero-bridge.database.table', 'custom_xero_connections');

    expect((new XeroConnection)->getTable())->toBe('custom_xero_connections');
});
