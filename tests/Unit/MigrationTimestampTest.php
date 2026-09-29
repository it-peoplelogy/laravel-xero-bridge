<?php

declare(strict_types=1);

/**
 * A guard the rest of the suite structurally cannot provide.
 *
 * Every other migration test runs the real migrations against in-memory sqlite,
 * which has none of MySQL's timestamp behaviour. So a stub can be green here,
 * green in CI, and still fail on the first `php artisan migrate` a consumer runs
 * -- which is exactly what happened: `create_myinvois_validations_table` died
 * on MySQL 5.7 with "Invalid default value for 'last_checked_at'".
 *
 * MySQL does two separate things to a `TIMESTAMP NOT NULL` column with no
 * explicit default:
 *
 *   THE FIRST such column in a table gets DEFAULT CURRENT_TIMESTAMP **ON UPDATE
 *   CURRENT_TIMESTAMP**. That is the dangerous one, and it is silent: it would
 *   rewrite claimed_at every time a write claim was confirmed, first_seen_at on
 *   every webhook replay, and first_checked_at on every re-check -- destroying
 *   the stuck-claim detection and the replay evidence those columns exist for,
 *   with no error anywhere.
 *
 *   EVERY LATER one gets an implicit zero-date default, which strict mode
 *   rejects, so the table cannot be created at all. That is the loud one.
 *
 * Naming a default explicitly avoids both. This test reads the stubs as TEXT
 * because that is the only way to assert it without a MySQL connection.
 */
it('gives every non-nullable timestamp column an explicit default', function () {
    $stubs = glob(__DIR__.'/../../database/migrations/*.php.stub');

    expect($stubs)->not->toBeEmpty();

    $offenders = [];

    foreach ($stubs as $path) {
        $source = (string) file_get_contents($path);

        preg_match_all('/\$table->timestamp\([^;]*;/', $source, $matches);

        foreach ($matches[0] as $line) {
            $flat = preg_replace('/\s+/', ' ', $line);

            // A nullable timestamp is safe: MySQL defaults it to NULL and adds
            // no ON UPDATE clause.
            if (str_contains($flat, '->nullable()')) {
                continue;
            }

            if (! str_contains($flat, '->useCurrent()')) {
                $offenders[] = basename($path).': '.$flat;
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'These columns would break `migrate` on MySQL, or silently rewrite themselves on update. '
        .'Add ->useCurrent(), or ->nullable() if the column is genuinely optional.'
    );
});
