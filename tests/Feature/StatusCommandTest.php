<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Peoplelogy\XeroBridge\Commands\StatusCommand;
use Peoplelogy\XeroBridge\Contracts\ConnectionRepository;
use Peoplelogy\XeroBridge\Models\XeroConnection;
use Peoplelogy\XeroBridge\Models\XeroWriteRecord;
use Peoplelogy\XeroBridge\OAuth\TenantInfo;
use Peoplelogy\XeroBridge\OAuth\TokenManager;
use Peoplelogy\XeroBridge\OAuth\TokenResponse;
use Peoplelogy\XeroBridge\Support\Diagnostics;

/**
 * xero-bridge:status is what consumers wire to monitoring, so these pin what
 * it reports, the exit code each state produces, and the --json contract.
 *
 * Every --strict case that expects a pass starts from healthyLockStore(): the
 * suite runs on the `array` cache, whose locks only exclude inside one
 * process -- which is exactly what --strict exists to refuse.
 *
 * Output is read through Artisan::call() with its whitespace collapsed. The
 * console components wrap a long line at the terminal's width, which on a CI
 * runner is narrower than here, and would split a phrase over two lines.
 */

/**
 * @return array{0: int, 1: string} the exit code and the collapsed output
 */
function statusRun(array $options = []): array
{
    $exit = Artisan::call('xero-bridge:status', $options);

    return [$exit, (string) preg_replace('/\s+/', ' ', Artisan::output())];
}

/**
 * @return array<string, mixed>
 */
function statusJson(): array
{
    Artisan::call('xero-bridge:status', ['--json' => true]);

    return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Replace one of the package's tables with a stranger's of the same name --
 * what 1.4.3's migrations adopted without a word.
 */
function statusForeignTable(string $table): void
{
    Schema::drop($table);

    Schema::create($table, function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->timestamps();
    });
}

/**
 * A ConnectionRepository of the host's own -- the documented seam -- holding
 * one working connection in memory, or failing every read with $failure.
 * Bound as a host's service provider binds it, before anything is built
 * around the package's.
 */
function statusOwnRepository(?Throwable $failure = null): void
{
    $row = (new XeroConnection)->forceFill([
        'key' => 'default',
        'tenant_id' => 'tenant-1',
        'tenant_name' => 'Acme Sdn Bhd',
        'tenant_type' => 'ORGANISATION',
        'access_token' => 'access-token-1',
        'refresh_token' => 'refresh-token-1',
        'expires_at' => now()->addMinutes(30),
        'scopes' => 'offline_access accounting.invoices',
        'failure_count' => 0,
    ]);

    app()->instance(ConnectionRepository::class, new class($row, $failure) implements ConnectionRepository
    {
        public function __construct(
            private readonly XeroConnection $row,
            private readonly ?Throwable $failure,
        ) {}

        public function findByKey(string $key): ?XeroConnection
        {
            return $key === 'default' ? $this->row : null;
        }

        public function findByTenantId(string $tenantId): ?XeroConnection
        {
            return $tenantId === 'tenant-1' ? $this->row : null;
        }

        public function all(): Collection
        {
            if ($this->failure !== null) {
                throw $this->failure;
            }

            return new Collection([$this->row]);
        }

        public function upsert(string $key, TenantInfo $tenant, TokenResponse $tokens): XeroConnection
        {
            return $this->row;
        }

        public function persistRefreshedTokens(XeroConnection $connection, TokenResponse $tokens): XeroConnection
        {
            return $connection;
        }

        public function recordTransientFailure(XeroConnection $connection, string $reason): XeroConnection
        {
            return $connection;
        }

        public function markInvalidated(XeroConnection $connection, string $reason): XeroConnection
        {
            return $connection;
        }
    });

    app()->forgetInstance(TokenManager::class);
    app()->forgetInstance(Diagnostics::class);
}

/** A host's own connection model, keeping its rows in a table it names. */
class StatusHostConnection extends XeroConnection
{
    public function getTable(): string
    {
        return 'host_xero_connections';
    }
}

afterEach(function () {
    File::deleteDirectory(sys_get_temp_dir().'/xero-bridge-status-cache');
});

/*
|--------------------------------------------------------------------------
| The connections table
|--------------------------------------------------------------------------
|
| Status used to call connections() unconditionally, and its query died on a
| missing or foreign table with an uncaught QueryException. Artisan::call()
| rethrows anything that escapes, so each of these would fail on one.
|
*/

it('stops at a connections table that is not the package\'s, naming the setting', function () {
    statusForeignTable('xero_connections');

    [$exit, $output] = statusRun();

    expect($exit)->toBe(StatusCommand::EXIT_CONFIG_INCOMPLETE)
        ->and($output)->toContain('ERROR The table [xero_connections] is not the package\'s connections table')
        ->toContain('"key", "invalidated_at", "invalidated_reason" and 12 more')
        ->toContain('XERO_DB_TABLE (config key xero-bridge.database.table)')
        // In place of the connections it could not read, not as a guess.
        ->not->toContain('No Xero organisations are connected')
        // An error, printed once -- not repeated among the warnings.
        ->and(substr_count($output, 'is not the package\'s connections table'))->toBe(1);
});

it('stops at a missing connections table, saying how to create it', function () {
    Schema::drop('xero_connections');

    [$exit, $output] = statusRun();

    expect($exit)->toBe(StatusCommand::EXIT_CONFIG_INCOMPLETE)
        ->and($output)->toContain('The connections table [xero_connections] does not exist')
        ->toContain('Run php artisan migrate -- first php artisan vendor:publish --tag=xero-bridge-migrations');
});

it('reports a broken connections table in --json too', function () {
    statusForeignTable('xero_connections');

    $exit = Artisan::call('xero-bridge:status', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(StatusCommand::EXIT_CONFIG_INCOMPLETE)
        ->and($decoded['connections'])->toBe([])
        ->and($decoded['table_problems'])->toHaveKey('database.table')
        ->and($decoded['table_problems']['database.table'])->toContain('XERO_DB_TABLE')
        // Among the warnings as well, where the console reads it.
        ->and($decoded['warnings'])->toContain($decoded['table_problems']['database.table']);
});

it('ranks a broken connections table with missing configuration', function () {
    // Both mean nothing can connect or refresh: the same exit code, and both
    // reasons on screen.
    config()->set('xero-bridge.client_secret', null);
    Schema::drop('xero_connections');

    [$exit, $output] = statusRun();

    expect($exit)->toBe(StatusCommand::EXIT_CONFIG_INCOMPLETE)
        ->and($output)->toContain('XERO_CLIENT_SECRET')
        ->toContain('[xero_connections] does not exist');
});

it('lists the connections a host\'s own ConnectionRepository keeps, with no package table at all', function () {
    // A host that stores connections its own way owes the package's table
    // nothing, so its absence is no fault: 1.4.x listed these connections
    // through the repository and exited 0, and so does this.
    statusOwnRepository();
    Schema::drop('xero_connections');

    [$exit, $output] = statusRun();

    expect($exit)->toBe(0)
        ->and($output)->toContain('| default | Acme Sdn Bhd | valid |')
        ->not->toContain('[xero_connections]');
});

it('stops at a host\'s own ConnectionRepository that cannot be read, saying why', function () {
    statusOwnRepository(new RuntimeException('The tenant database is unreachable.'));

    [$exit, $output] = statusRun();

    expect($exit)->toBe(StatusCommand::EXIT_CONFIG_INCOMPLETE)
        ->and($output)->toContain('ERROR Could not read the stored connections: The tenant database is '
            .'unreachable. Until they can be read, the state of every connection is unknown.')
        ->not->toContain('No Xero organisations are connected');

    // Filed where --json and the console look for the connections problem.
    expect(statusJson()['table_problems'])->toHaveKey(Diagnostics::CONNECTIONS_TABLE);
});

it('looks for the connections table where the configured model keeps it', function () {
    // xero-bridge.model may be a host's subclass that names its own table,
    // which the package's repository then reads through.
    connection();
    Schema::rename('xero_connections', 'host_xero_connections');
    config()->set('xero-bridge.model', StatusHostConnection::class);

    [$exit, $output] = statusRun();

    expect($exit)->toBe(0)
        ->and($output)->toContain('| default | Acme Sdn Bhd | valid |');
});

/*
|--------------------------------------------------------------------------
| The feature tables
|--------------------------------------------------------------------------
*/

it('says nothing about a foreign ledger table while the ledger is off', function () {
    healthyLockStore();
    connection();
    statusForeignTable('xero_write_records');

    [$exit, $output] = statusRun(['--strict' => true]);

    expect($exit)->toBe(0)
        ->and($output)->not->toContain('XERO_WRITES_TABLE')
        ->not->toContain('xero_write_records');
});

it('warns about a foreign ledger table once the ledger is on', function () {
    healthyLockStore();
    connection();
    config()->set('xero-bridge.writes.enabled', true);
    statusForeignTable('xero_write_records');

    [$exit, $output] = statusRun();

    // A warning, not an outage: plain status stays green, --strict does not.
    expect($exit)->toBe(0)
        ->and($output)->toContain('The table [xero_write_records] is not the package\'s write ledger table')
        ->toContain('writes are NOT protected against duplicates')
        ->toContain('XERO_WRITES_TABLE');

    expect(statusRun(['--strict' => true])[0])->toBe(StatusCommand::EXIT_CONFIG_INCOMPLETE);
});

it('warns when the ledger is on and its table is gone', function () {
    healthyLockStore();
    connection();
    config()->set('xero-bridge.writes.enabled', true);
    Schema::drop('xero_write_records');

    [$exit, $output] = statusRun();

    expect($exit)->toBe(0)
        ->and($output)->toContain('The write ledger is on (XERO_WRITES_LEDGER) but its table [xero_write_records] does not exist')
        ->toContain('NOT protected against duplicates');

    expect(statusRun(['--strict' => true])[0])->toBe(StatusCommand::EXIT_CONFIG_INCOMPLETE);
});

it('stays silent about missing replay and capture tables, even under --strict', function () {
    // docs/09 promises a switched-on flag with no table is a no-op, and the
    // README's own sample block switches capture on.
    healthyLockStore();
    connection();
    config()->set('xero-bridge.webhooks.dedupe.enabled', true);
    config()->set('xero-bridge.capture.enabled', true);
    Schema::drop('xero_webhook_events');
    Schema::drop('xero_api_calls');

    [$exit, $output] = statusRun(['--strict' => true]);

    expect($exit)->toBe(0)
        ->and($output)->not->toContain('xero_webhook_events')
        ->not->toContain('xero_api_calls');
});

it('fails --strict on a claim stuck for over an hour', function () {
    healthyLockStore();
    connection();
    config()->set('xero-bridge.writes.enabled', true);

    XeroWriteRecord::create([
        'claim_key' => hash('sha256', 'stuck'),
        'connection_key' => 'default',
        'operation' => 'invoice.create',
        'status' => XeroWriteRecord::STATUS_PENDING,
        'claimed_at' => now()->subHours(2),
    ]);

    [$exit, $output] = statusRun();

    expect($exit)->toBe(0)
        ->and($output)->toContain('1 write claim(s) have been pending for over an hour');

    expect(statusRun(['--strict' => true])[0])->toBe(StatusCommand::EXIT_CONFIG_INCOMPLETE);
});

it('fails --strict on a package table recorded by two migrations', function () {
    healthyLockStore();
    connection();

    $migrations = app('migration.repository');
    $migrations->createRepository();
    $migrations->log('2026_01_15_090000_create_xero_connections_table', 1);
    $migrations->log('2026_03_02_101500_create_xero_connections_table', 4);

    [$exit, $output] = statusRun();

    expect($exit)->toBe(0)
        ->and($output)->toContain('as created more than once')
        ->toContain('2026_03_02_101500_create_xero_connections_table (batch 4)');

    expect(statusRun(['--strict' => true])[0])->toBe(StatusCommand::EXIT_CONFIG_INCOMPLETE);
});

/*
|--------------------------------------------------------------------------
| Locks and pre-flight
|--------------------------------------------------------------------------
*/

it('passes --strict when nothing is wrong', function () {
    healthyLockStore();

    // Nothing connected is not a fault.
    expect(statusRun(['--strict' => true])[0])->toBe(0);

    connection();

    expect(statusRun(['--strict' => true])[0])->toBe(0);
});

it('fails --strict on the suite\'s own array cache, and says why', function () {
    connection();

    [$exit, $output] = statusRun(['--strict' => true]);

    expect($exit)->toBe(StatusCommand::EXIT_CONFIG_INCOMPLETE)
        ->and($output)->toContain('only locks inside one process')
        ->toContain('XERO_LOCK_STORE');
});

it('reports a lock store that does not exist without crashing', function () {
    connection();
    config()->set('xero-bridge.tokens.lock_store', 'nope');

    [$exit, $output] = statusRun();

    // The pre-flight failure, named: every refresh would throw on it.
    expect($exit)->toBe(0)
        ->and($output)->toContain('Token-refresh lock is usable: XeroConfigurationException')
        ->toContain('XERO_LOCK_STORE names the cache store [nope], which cannot be used');

    expect(statusRun(['--strict' => true])[0])->toBe(StatusCommand::EXIT_CONFIG_INCOMPLETE);
});

it('prints a consent-flow pre-flight failure without counting it', function () {
    // The CLI derives an unset redirect URI from APP_URL, the browser from the
    // request, so this check can fail here and pass where it matters.
    healthyLockStore();
    connection();
    config()->set('xero-bridge.redirect_uri', 'http://127.0.0.1/xero/callback');

    [$exit, $output] = statusRun(['--strict' => true]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('Consent flow can start: XeroConfigurationException');
});

it('treats a file lock store chosen explicitly as a note, not a warning', function () {
    healthyLockStore();
    connection();
    config()->set('cache.stores.local', ['driver' => 'file', 'path' => sys_get_temp_dir().'/xero-bridge-status-cache']);
    config()->set('xero-bridge.tokens.lock_store', 'local');

    [$exit, $output] = statusRun(['--strict' => true]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('INFO XERO_LOCK_STORE names the [local] cache store, which locks within one server only')
        ->not->toContain('WARN');
});

it('prints its warnings even when nothing is connected', function () {
    // It used to return before the warnings, so --strict could exit 1 with
    // no reason given anywhere.
    healthyLockStore();
    config()->set('xero-bridge.webhook_key', null);

    [$exit, $output] = statusRun();

    expect($exit)->toBe(0)
        ->and($output)->toContain('No Xero organisations are connected')
        ->toContain('No XERO_WEBHOOK_KEY is set');

    [$exit, $output] = statusRun(['--strict' => true]);

    expect($exit)->toBe(StatusCommand::EXIT_CONFIG_INCOMPLETE)
        ->and($output)->toContain('No XERO_WEBHOOK_KEY is set');
});

it('puts a connection that needs re-authorising ahead of --strict', function () {
    connection(['invalidated_at' => now(), 'invalidated_reason' => 'invalid_grant']);

    // Warnings are present too (the array cache), but 2 is the code that
    // tells monitoring a human must reconnect.
    expect(statusRun(['--strict' => true])[0])->toBe(StatusCommand::EXIT_NEEDS_REAUTH);
});

/*
|--------------------------------------------------------------------------
| The test console
|--------------------------------------------------------------------------
*/

it('says the test console is off, and why', function (mixed $value, string $reason) {
    healthyLockStore();
    config()->set('xero-bridge.console.enabled', $value);

    [$exit, $output] = statusRun(['--strict' => true]);

    // Information, never a warning.
    expect($exit)->toBe(0)
        ->and($output)->toContain("INFO Test console: off ({$reason}).");
})->with([
    'unset' => [null, 'XERO_CONSOLE_ENABLED is not set'],
    'false' => [false, 'XERO_CONSOLE_ENABLED=false'],
    'the string "false"' => ['false', 'XERO_CONSOLE_ENABLED=false'],
]);

it('says the test console is on, and what it sits behind', function () {
    healthyLockStore();
    config()->set('xero-bridge.console.enabled', true);
    config()->set('xero-bridge.console.middleware', ['web', 'auth', 'can:manage-xero']);

    [$exit, $output] = statusRun(['--strict' => true]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('INFO Test console: ON (XERO_CONSOLE_ENABLED=true)')
        ->toContain('behind web, auth, can:manage-xero.');
});

it('says when the test console is on with no middleware in front of it', function () {
    // The most exposed state the console can be in, so the line must never
    // claim a gate that is not there.
    config()->set('xero-bridge.console.enabled', true);
    config()->set('xero-bridge.console.middleware', []);

    $output = statusRun()[1];

    expect($output)->toContain('INFO Test console: ON (XERO_CONSOLE_ENABLED=true)')
        ->toContain('with no middleware in front of it.');
});

it('describes the test console in the words xero-bridge:install uses', function (mixed $value) {
    // One gate, read by both commands: an administrator who runs install and
    // then status must not be left wondering whether "on" and "ON" differ.
    config()->set('xero-bridge.console.enabled', $value);

    preg_match('/^Test console: .+$/m', installCommandOutput(), $install);

    expect($install)->toHaveCount(1)
        ->and(statusRun()[1])->toContain('INFO '.$install[0]);
})->with([
    'unset' => [null],
    'false' => [false],
    'true' => [true],
]);

/*
|--------------------------------------------------------------------------
| --json
|--------------------------------------------------------------------------
*/

it('reports the write ledger as the recorder reads it', function () {
    healthyLockStore();
    config()->set('xero-bridge.writes.enabled', true);
    config()->set('xero-bridge.writes.strict', true);

    expect(statusJson()['write_ledger'])->toBe([
        'enabled' => true,
        'strict' => true,
        'table' => 'xero_write_records',
        'table_present' => true,
        'stuck' => 0,
    ]);
});

it('reports the test console as data', function () {
    expect(statusJson()['console'])->toBe([
        'enabled' => false,
        'reason' => 'XERO_CONSOLE_ENABLED is not set',
        'middleware' => ['web', 'auth'],
        'url' => null,
    ]);
});

it('reports the pre-flight checks, notes and warnings as data', function () {
    connection();
    config()->set('xero-bridge.tokens.lock_store', 'nope');

    $decoded = statusJson();

    expect($decoded['preflight']['connect']['ok'])->toBeTrue()
        ->and($decoded['preflight']['lock']['ok'])->toBeFalse()
        ->and($decoded['preflight']['lock']['severity'])->toBe('fail')
        ->and($decoded['notes'])->toBe([])
        ->and($decoded['table_problems'])->toBe([])
        // The undefined store is a pre-flight failure, and said only there.
        ->and(implode(' ', $decoded['warnings']))->not->toContain('nope');
});

/*
| Written last on purpose: every key above is a contract, and this pins the
| order they were added in. A field the console needs one day is APPENDED --
| never inserted, reordered or renamed.
*/
it('keeps the json keys in order, every newer one appended', function () {
    connection();

    expect(array_keys(statusJson()))->toBe([
        'missing_config',
        'connections',
        'warnings',
        'notes',
        'preflight',
        'table_problems',
        'write_ledger',
        'console',
    ]);
});
