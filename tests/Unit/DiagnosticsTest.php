<?php

declare(strict_types=1);

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Peoplelogy\XeroBridge\Contracts\ConnectionRepository;
use Peoplelogy\XeroBridge\Models\XeroWriteRecord;
use Peoplelogy\XeroBridge\OAuth\TokenManager;
use Peoplelogy\XeroBridge\Support\Diagnostics;
use Peoplelogy\XeroBridge\Support\TableGuard;

/**
 * Diagnostics is shared by xero-bridge:status and the test console, so these
 * pin the shape both of them read.
 */
function diagnostics(): Diagnostics
{
    return app(Diagnostics::class);
}

/**
 * A cache store whose driver implements no locking at all -- the apc, session
 * and storage drivers, and any Cache::extend() store that skips LockProvider.
 */
function diagNoLockStore(): void
{
    Cache::extend('nolock', fn ($app) => new Repository(
        new class implements Store
        {
            public function get($key) {}

            public function many(array $keys)
            {
                return [];
            }

            public function put($key, $value, $seconds)
            {
                return true;
            }

            public function putMany(array $values, $seconds)
            {
                return true;
            }

            public function increment($key, $value = 1)
            {
                return 1;
            }

            public function decrement($key, $value = 1)
            {
                return 1;
            }

            public function forever($key, $value)
            {
                return true;
            }

            // Added to the Store contract in Laravel 13. Declared
            // unconditionally: an extra method is harmless on 11 and 12.
            public function touch($key, $seconds)
            {
                return true;
            }

            public function forget($key)
            {
                return true;
            }

            public function flush()
            {
                return true;
            }

            public function getPrefix()
            {
                return '';
            }
        }
    ));

    config()->set('cache.stores.nolock', ['driver' => 'nolock']);
}

/**
 * A file-driver store under a name that is not "file", in a directory of its
 * own: the class is what decides how far its locks reach, never the name.
 */
function diagLocalFileStore(): void
{
    config()->set('cache.stores.local', [
        'driver' => 'file',
        'path' => sys_get_temp_dir().'/xero-bridge-diagnostics-cache',
    ]);
}

/**
 * Point the lock store at one of the kinds status tells apart, by name.
 */
function diagLockSetup(string $setup): void
{
    match ($setup) {
        'the default array store' => config()->set('xero-bridge.tokens.lock_store', null),
        'an array store, named explicitly' => config()->set('xero-bridge.tokens.lock_store', 'array'),
        'the default store, a file store named local' => (function (): void {
            diagLocalFileStore();
            config()->set('cache.default', 'local');
            config()->set('xero-bridge.tokens.lock_store', null);
        })(),
        'a file store, named explicitly' => (function (): void {
            diagLocalFileStore();
            config()->set('xero-bridge.tokens.lock_store', 'local');
        })(),
        'the null store' => (function (): void {
            config()->set('cache.stores.none', ['driver' => 'null']);
            config()->set('xero-bridge.tokens.lock_store', 'none');
        })(),
        'a store with no locks' => (function (): void {
            diagNoLockStore();
            config()->set('xero-bridge.tokens.lock_store', 'nolock');
        })(),
        'a database store' => healthyLockStore(),
        'a store that does not exist' => config()->set('xero-bridge.tokens.lock_store', 'nope'),
    };
}

/**
 * Replace one of the package's tables with a stranger's of the same name.
 */
function diagForeignTable(string $table): void
{
    Schema::drop($table);

    Schema::create($table, function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->timestamps();
    });
}

function diagLedgerRow(string $status, int $minutesAgo): XeroWriteRecord
{
    return XeroWriteRecord::create([
        'claim_key' => hash('sha256', uniqid('', true)),
        'connection_key' => 'default',
        'operation' => 'invoice.create',
        'status' => $status,
        'claimed_at' => now()->subMinutes($minutesAgo),
        'xero_id' => $status === XeroWriteRecord::STATUS_SUCCEEDED ? 'inv-1' : null,
    ]);
}

/**
 * Rows in the migrations table, as `php artisan migrate` would have written
 * them. The suite runs the stubs directly, so there is none until this.
 *
 * @param  array<string, int>  $migrations  name => batch
 */
function diagRecordMigrations(array $migrations): void
{
    $repository = app('migration.repository');
    $repository->createRepository();

    foreach ($migrations as $name => $batch) {
        $repository->log($name, $batch);
    }
}

/**
 * A file in the application's database/migrations, where `migrate` finds it:
 * a copy of the package's connections stub, or a host's own migration that
 * Laravel happened to give the same name. Returns the path, for the caller to
 * delete in a finally -- under Testbench the directory is the skeleton every
 * later boot on this machine shares.
 */
function diagMigrationFile(string $name, bool $package): string
{
    $path = database_path('migrations/'.$name.'.php');

    File::put($path, $package
        ? File::get(__DIR__.'/../../database/migrations/create_xero_connections_table.php.stub')
        : <<<'PHP'
            <?php

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration
            {
                public function up(): void
                {
                    Schema::create('xero_connections', function (Blueprint $table) {
                        $table->id();
                        $table->string('name');
                        $table->timestamps();
                    });
                }
            };
            PHP);

    return $path;
}

/**
 * Every line the console's pre-flight panel would show -- the checks that did
 * not pass, then the warnings -- plus the notes status prints after them.
 *
 * @return list<string>
 */
function diagReportedLines(): array
{
    $checks = collect(diagnostics()->preflight())
        ->reject(fn (array $check): bool => $check['ok'])
        ->map(fn (array $check): string => $check['message'])
        ->values()
        ->all();

    return [...$checks, ...diagnostics()->warnings([]), ...diagnostics()->notes()];
}

afterEach(function () {
    File::deleteDirectory(sys_get_temp_dir().'/xero-bridge-diagnostics-cache');
});

/*
|--------------------------------------------------------------------------
| Missing configuration
|--------------------------------------------------------------------------
*/

it('names the environment variable behind each missing setting', function () {
    config()->set('xero-bridge.client_id', null);
    config()->set('xero-bridge.client_secret', '');
    config()->set('xero-bridge.scopes', 'openid profile');

    expect(diagnostics()->missingConfig())->toBe([
        'client_id' => 'XERO_CLIENT_ID',
        'client_secret' => 'XERO_CLIENT_SECRET',
        'scopes' => 'XERO_SCOPES (must include offline_access)',
    ]);
});

it('reports nothing missing when the configuration is complete', function () {
    expect(diagnostics()->missingConfig())->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Describing a connection
|--------------------------------------------------------------------------
*/

it('describes a connection without ever exposing a token', function () {
    $row = diagnostics()->describe(connection());

    expect($row['key'])->toBe('default')
        ->and($row['organisation'])->toBe('Acme Sdn Bhd')
        ->and($row['expired'])->toBeFalse()
        ->and($row['usable'])->toBeTrue()
        ->and($row['needs_reauthorisation'])->toBeFalse()
        ->and($row['expires_in'])->toBe(1800)
        ->and($row['scopes'])->toContain('offline_access');

    // The model hides the tokens, but this array is built key by key, so the
    // guarantee has to be asserted rather than assumed.
    $encoded = json_encode($row);

    expect($encoded)->not->toContain('access-token-1')
        ->not->toContain('refresh-token-1');
});

it('keeps the status command json shape stable', function () {
    // xero-bridge:status --json can be wired to monitoring, so its keys are a
    // contract. If a console-only field is ever needed, append it -- do not
    // reorder or rename what is already here.
    expect(array_keys(diagnostics()->describe(connection())))->toBe([
        'key',
        'organisation',
        'tenant_id',
        'tenant_type',
        'expires_at',
        'expires_in',
        'expired',
        'usable',
        'needs_reauthorisation',
        'invalidated_reason',
        'failure_count',
        'last_refreshed_at',
        'last_failure_at',
        'scopes',
        'connect_url',
        // Appended in 1.5.0, after every key that was already here.
        'tokens_readable',
    ]);
});

it('flags a connection that needs reauthorising', function () {
    $row = diagnostics()->describe(connection([
        'invalidated_at' => now(),
        'invalidated_reason' => 'invalid_grant',
    ]));

    expect($row['needs_reauthorisation'])->toBeTrue()
        ->and($row['usable'])->toBeFalse()
        ->and($row['invalidated_reason'])->toBe('invalid_grant');
});

/*
|--------------------------------------------------------------------------
| Warnings
|--------------------------------------------------------------------------
*/

it('warns when no webhook key is set', function () {
    config()->set('xero-bridge.webhook_key', null);

    expect(diagnostics()->warnings([]))
        ->toContain('No XERO_WEBHOOK_KEY is set, so every webhook will be rejected with a 401.');
});

it('warns when the default cache store only locks within one server', function () {
    // A file store does serialise processes -- through flock(), on one
    // server's disk -- so "not across processes" would be wrong. Not across
    // servers is what it cannot do.
    config()->set('cache.default', 'file');
    config()->set('xero-bridge.tokens.lock_store', null);

    expect(implode(' ', diagnostics()->warnings([])))
        ->toContain('XERO_LOCK_STORE')
        ->toContain('only locks within one server')
        ->not->toContain('across processes');
});

it('warns about transient failures, but not on a dead connection', function () {
    $failing = diagnostics()->describe(connection(['failure_count' => 3]));

    expect(implode(' ', diagnostics()->warnings([$failing])))
        ->toContain('3 recent transient failure(s)');

    // Once it needs reauthorising, the failure count is no longer the story.
    $dead = diagnostics()->describe(connection([
        'key' => 'dead',
        'tenant_id' => 'tenant-2',
        'failure_count' => 3,
        'invalidated_at' => now(),
    ]));

    expect(implode(' ', diagnostics()->warnings([$dead])))
        ->not->toContain('transient failure');
});

/*
|--------------------------------------------------------------------------
| Pre-flight
|--------------------------------------------------------------------------
*/

it('passes pre-flight with a valid configuration', function () {
    $preflight = diagnostics()->preflight();

    expect($preflight['connect']['ok'])->toBeTrue()
        ->and($preflight['lock']['ok'])->toBeTrue();
});

it('fails pre-flight on a redirect URI Xero would reject', function () {
    // Xero rejects http://127.0.0.1 explicitly, and its own error for that is
    // vague -- which is exactly why this is proven up front.
    config()->set('xero-bridge.redirect_uri', 'http://127.0.0.1/xero/callback');

    $connect = diagnostics()->preflight()['connect'];

    expect($connect['ok'])->toBeFalse()
        ->and($connect['severity'])->toBe('fail')
        ->and($connect['type'])->toBe('XeroConfigurationException');
});

it('warns rather than fails when the cache store supports no locks', function () {
    diagNoLockStore();
    config()->set('xero-bridge.tokens.lock_store', 'nolock');

    $lock = diagnostics()->preflight()['lock'];

    // A warning, not a failure: the bridge logs and carries on unlocked, which
    // is fine in one process and loses rotated refresh tokens across several.
    expect($lock['ok'])->toBeFalse()
        ->and($lock['severity'])->toBe('warn')
        ->and($lock['message'])->toContain('XERO_LOCK_STORE');
});

it('leaves no lock behind after probing', function () {
    diagnostics()->preflight();

    expect(Cache::lock('xero-bridge:preflight-probe', 2)->get())->toBeTrue();
});

it('fails --strict on a store with no locks, and prints why either way', function () {
    // The name-based check this replaced only knew `array` and `file`, which
    // both CAN lock -- so a store that cannot was never reported by status.
    diagNoLockStore();
    config()->set('xero-bridge.tokens.lock_store', 'nolock');
    connection();

    expect(Artisan::call('xero-bridge:status'))->toBe(0)
        ->and(Artisan::output())->toContain('XERO_LOCK_STORE');

    expect(Artisan::call('xero-bridge:status', ['--strict' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('XERO_LOCK_STORE');
});

it('treats the null store as a store that cannot lock', function () {
    // It does hand out locks, but grants every one at once: taking one proves
    // nothing, so the probe must not report it as usable.
    config()->set('cache.stores.none', ['driver' => 'null']);
    config()->set('xero-bridge.tokens.lock_store', 'none');

    $lock = diagnostics()->preflight()['lock'];

    expect($lock['ok'])->toBeFalse()
        ->and($lock['severity'])->toBe('warn')
        ->and($lock['message'])->toContain('[none]')
        ->toContain('null driver')
        ->toContain('XERO_LOCK_STORE')
        // Named there, it no longer holds the webhook lock: uniqueVia() falls
        // back to the default store, as for a store with no locks at all.
        ->toContain('webhook retries cannot lock in it either')
        ->not->toContain('queued twice');
});

it('names XERO_LOCK_STORE when the store it names does not exist', function () {
    config()->set('xero-bridge.tokens.lock_store', 'nope');

    $lock = diagnostics()->preflight()['lock'];

    expect($lock['ok'])->toBeFalse()
        ->and($lock['severity'])->toBe('fail')
        ->and($lock['type'])->toBe('XeroConfigurationException')
        ->and($lock['message'])->toContain('XERO_LOCK_STORE names the cache store [nope]')
        ->toContain('is not defined');

    // Resolving it again for the warnings must not throw either.
    expect(diagnostics()->warnings([]))->toBeArray();
});

it('judges how far a store locks by its class, not its name', function (string $setup, ?string $warning, ?string $note) {
    match ($setup) {
        'the default store, a file store named local' => (function (): void {
            diagLocalFileStore();
            config()->set('cache.default', 'local');
            config()->set('xero-bridge.tokens.lock_store', null);
        })(),
        'an array store, named explicitly' => config()->set('xero-bridge.tokens.lock_store', 'array'),
        'a file store, named explicitly' => (function (): void {
            diagLocalFileStore();
            config()->set('xero-bridge.tokens.lock_store', 'local');
        })(),
        'a database store' => healthyLockStore(),
    };

    $warnings = implode(' ', diagnostics()->warnings([]));
    $notes = implode(' ', diagnostics()->notes());

    $warning === null
        ? expect($warnings)->not->toContain('XERO_LOCK_STORE')
        : expect($warnings)->toContain($warning)->toContain('XERO_LOCK_STORE');

    $note === null
        ? expect($notes)->toBe('')
        : expect($notes)->toContain($note);
})->with([
    // Missed before: the old check matched the store's NAME against
    // 'array' and 'file', so a file store under any other name passed.
    'the default store, a file store named local' => [
        'the default store, a file store named local',
        'The default cache store [local] holds the token-refresh and webhook-retry locks',
        null,
    ],
    // Missed before too: the old check only looked at the default store,
    // never at one XERO_LOCK_STORE names.
    'an array store, named explicitly' => [
        'an array store, named explicitly',
        'only locks inside one process',
        null,
    ],
    // A file store does lock across processes on one server. Chosen
    // explicitly, that is a decision: a note, which --strict ignores.
    'a file store, named explicitly' => [
        'a file store, named explicitly',
        null,
        'XERO_LOCK_STORE names the [local] cache store, which locks within one server only',
    ],
    'a database store' => ['a database store', null, null],
]);

it('says each lock problem exactly once across pre-flight, warnings and notes', function (string $setup, int $times) {
    match ($setup) {
        'a store with no locks' => (function (): void {
            diagNoLockStore();
            config()->set('xero-bridge.tokens.lock_store', 'nolock');
        })(),
        'the null store' => (function (): void {
            config()->set('cache.stores.none', ['driver' => 'null']);
            config()->set('xero-bridge.tokens.lock_store', 'none');
        })(),
        'a store that does not exist' => config()->set('xero-bridge.tokens.lock_store', 'nope'),
        'an array store' => config()->set('xero-bridge.tokens.lock_store', 'array'),
        'the default file store' => (function (): void {
            // Its probe takes a real flock(); kept out of the shared skeleton.
            config()->set('cache.stores.file.path', sys_get_temp_dir().'/xero-bridge-diagnostics-cache');
            config()->set('cache.stores.file.lock_path', sys_get_temp_dir().'/xero-bridge-diagnostics-cache');
            config()->set('cache.default', 'file');
        })(),
        'a file store, named explicitly' => (function (): void {
            diagLocalFileStore();
            config()->set('xero-bridge.tokens.lock_store', 'local');
        })(),
        'a database store' => healthyLockStore(),
    };

    $lines = diagReportedLines();

    // The console renders pre-flight and warnings in ONE panel, so a problem
    // reported by both would be shown twice there.
    expect(collect($lines)->filter(fn (string $line): bool => str_contains($line, 'XERO_LOCK_STORE')))
        ->toHaveCount($times)
        ->and($lines)->toBe(array_values(array_unique($lines)));
})->with([
    'a store with no locks' => ['a store with no locks', 1],
    'the null store' => ['the null store', 1],
    'a store that does not exist' => ['a store that does not exist', 1],
    'an array store' => ['an array store', 1],
    'the default file store' => ['the default file store', 1],
    'a file store, named explicitly' => ['a file store, named explicitly', 1],
    'a database store' => ['a database store', 0],
]);

/*
|--------------------------------------------------------------------------
| Environment and URLs
|--------------------------------------------------------------------------
*/

it('reports credentials as booleans and never as values', function () {
    $environment = diagnostics()->environment();

    expect($environment['client_id_set'])->toBeTrue()
        ->and($environment['client_secret_set'])->toBeTrue()
        ->and($environment)->not->toHaveKey('client_secret')
        ->and($environment)->not->toHaveKey('client_id')
        ->and($environment['has_offline_access'])->toBeTrue();

    expect(json_encode($environment))->not->toContain('test-client-secret');
});

it('reports the table as the database will see it', function () {
    // The package stores a bare name and lets the host connection apply its
    // own prefix, so the configured value alone would be misleading. The
    // Prefix suite covers the prefixed case.
    expect(diagnostics()->environment()['table'])->toBe('xero_connections');
});

it('appends the lock store\'s scope to the environment, after every existing key', function () {
    // The console's boot payload, and a published copy of its view reads
    // these keys by name: new ones are only ever appended.
    expect(array_keys(diagnostics()->environment()))->toBe([
        'client_id_set',
        'client_secret_set',
        'webhook_key_set',
        'redirect_uri',
        'scopes',
        'has_offline_access',
        'default_connection',
        'table',
        'routes_enabled',
        'webhooks_enabled',
        'lock_store',
        'idempotency',
        'lock_scope',
        'lock_store_set',
    ]);
});

it('reports how far the lock store locks, by its class as status judges it', function (string $setup, ?string $scope, bool $set) {
    diagLockSetup($setup);

    expect(diagnostics()->environment())->toMatchArray([
        'lock_scope' => $scope,
        'lock_store_set' => $set,
    ]);
})->with([
    'the suite\'s default array store' => ['the default array store', 'one process', false],
    'an array store, named explicitly' => ['an array store, named explicitly', 'one process', true],
    // Missed by the console's old pill, which matched the NAME.
    'the default store, a file store named local' => ['the default store, a file store named local', 'one server', false],
    'a file store, named explicitly' => ['a file store, named explicitly', 'one server', true],
    'the null store' => ['the null store', 'none', true],
    'a store with no locks' => ['a store with no locks', 'none', true],
    'a database store' => ['a database store', 'shared', true],
    // Pre-flight reports it, as the failure it is; there is no scope to show.
    'a store that does not exist' => ['a store that does not exist', null, true],
]);

it('gives the console what it needs to flag the lock store exactly where status warns', function (string $setup) {
    diagLockSetup($setup);

    $environment = diagnostics()->environment();

    // The console's rule (renderEnv() in resources/views/console.blade.php):
    // a pill for any scope short of shared, in the warning colour unless it
    // is a file store XERO_LOCK_STORE chose -- which status only notes.
    $flagged = in_array($environment['lock_scope'], ['one process', 'one server', 'none'], true)
        && ! ($environment['lock_scope'] === 'one server' && $environment['lock_store_set']);

    $checks = collect(diagnostics()->preflight())
        ->reject(fn (array $check): bool => $check['ok'])
        ->map(fn (array $check): string => $check['message']);

    $warned = $checks->merge(diagnostics()->warnings([]))
        ->contains(fn (string $line): bool => str_contains($line, 'XERO_LOCK_STORE'));

    expect($flagged)->toBe($warned);
})->with([
    'the default array store',
    'an array store, named explicitly',
    'the default store, a file store named local',
    'a file store, named explicitly',
    'the null store',
    'a store with no locks',
    'a database store',
]);

it('never puts a token in the console snapshot', function () {
    connection();

    expect(json_encode(diagnostics()->snapshot()))
        ->not->toContain('access-token-1')
        ->not->toContain('refresh-token-1')
        ->not->toContain('test-client-secret');
});

it('returns null for a URL whose route is not registered', function () {
    // A host may set routes.enabled=false or webhooks.enabled=false. Without
    // the Route::has() guards, route() would throw and take the page with it.
    config()->set('xero-bridge.routes.name_prefix', 'nope.');

    $urls = diagnostics()->urls();

    expect($urls['callback'])->toBeNull()
        ->and($urls['webhook'])->toBeNull()
        // connectUrl() falls back to a plain path, so guidance stays useful.
        ->and($urls['connect'])->toBe('/xero/connect/default');
});

/*
|--------------------------------------------------------------------------
| MyInvois must stay invisible here
|--------------------------------------------------------------------------
|
| Diagnostics feeds both the console status panel and xero-bridge:status, and
| the command is what consumers wire to monitoring. A Malaysia-only module that
| most installations will never enable must not appear in either -- not as a
| missing-config entry, not as a warning, and above all not as a non-zero exit
| code that pages somebody.
*/

it('never reports MyInvois to a consumer who has not enabled it', function () {
    config()->set('myinvois.enabled', false);
    config()->set('myinvois.client_id', null);
    config()->set('myinvois.client_secret', null);

    $diagnostics = diagnostics();

    expect(json_encode($diagnostics->missingConfig()))->not->toContain('MYINVOIS')
        ->and(json_encode($diagnostics->warnings([])))->not->toContain('MYINVOIS')
        ->and(json_encode($diagnostics->environment()))->not->toContain('myinvois');
});

it('keeps xero-bridge:status silent and green about MyInvois', function () {
    config()->set('myinvois.enabled', false);
    config()->set('myinvois.client_id', null);
    config()->set('myinvois.client_secret', null);

    connection();

    Artisan::call('xero-bridge:status');

    expect(Artisan::output())->not->toContain('MyInvois')
        ->not->toContain('MYINVOIS');

    expect(Artisan::call('xero-bridge:status'))->toBe(0);
});

it('stays silent even when MyInvois is enabled but unconfigured', function () {
    // The Xero command reports on Xero. A broken LHDN configuration is the
    // console panel's business, not something that should turn a Xero health
    // check red.
    config()->set('myinvois.enabled', true);
    config()->set('myinvois.client_id', null);

    connection();

    expect(Artisan::call('xero-bridge:status'))->toBe(0);
    expect(Artisan::output())->not->toContain('MYINVOIS');
});

it('never reports a MyInvois table, even a foreign one with auditing on', function () {
    // Its own migration refuses a stranger's table; status reports on Xero.
    config()->set('myinvois.enabled', true);
    config()->set('myinvois.audit.enabled', true);
    diagForeignTable('myinvois_validations');
    connection();

    expect(diagnostics()->tableProblems())->toBe([])
        ->and(Artisan::call('xero-bridge:status'))->toBe(0)
        ->and(Artisan::output())->not->toContain('myinvois');
});

/*
|--------------------------------------------------------------------------
| The package's tables
|--------------------------------------------------------------------------
|
| Checked quietly, straight through the schema -- never through TableGuard,
| which logs every absent table it meets and remembers the answer for the
| life of the process. Status runs as a fresh process every minute.
|
*/

it('finds nothing wrong with the tables the migrations created', function () {
    expect(diagnostics()->tableProblems())->toBe([]);
});

it('accepts a package table a host added columns to', function () {
    Schema::table('xero_connections', function (Blueprint $t) {
        $t->string('host_note')->nullable();
    });

    expect(diagnostics()->tableProblems())->toBe([]);
});

it('reports a connections table that is not the package\'s', function () {
    diagForeignTable('xero_connections');

    expect(diagnostics()->tableProblems())->toHaveKey(Diagnostics::CONNECTIONS_TABLE)
        ->and(diagnostics()->tableProblems()[Diagnostics::CONNECTIONS_TABLE])
        ->toContain('The table [xero_connections] is not the package\'s connections table')
        ->toContain('(missing columns: "key", "invalidated_at", "invalidated_reason" and 12 more)')
        ->toContain('so nothing can be connected or refreshed')
        ->toContain('Set XERO_DB_TABLE (config key xero-bridge.database.table) to an unused, bare table name')
        ->not->toContain('recorded as run');
});

it('reports a connections table that is missing, and how to create it', function () {
    Schema::drop('xero_connections');

    expect(diagnostics()->tableProblems()[Diagnostics::CONNECTIONS_TABLE])
        ->toBe('The connections table [xero_connections] does not exist, so nothing can be connected or '
            .'refreshed. Run php artisan migrate -- first php artisan vendor:publish '
            .'--tag=xero-bridge-migrations if the package\'s migrations are not published yet.');
});

it('reports a connections table it could not check', function () {
    config()->set('database.connections.unreachable', [
        'driver' => 'sqlite',
        'database' => sys_get_temp_dir().'/xero-bridge-no-such-dir/none.sqlite',
        'prefix' => '',
    ]);
    config()->set('xero-bridge.database.connection', 'unreachable');

    expect(diagnostics()->tableProblems()[Diagnostics::CONNECTIONS_TABLE])
        ->toContain('Could not check the connections table [xero_connections]:')
        ->toContain('the state of every connection is unknown');
});

it('names the table as the database sees it, prefix included', function () {
    config()->set('database.connections.prefixed', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => 'app_',
    ]);
    config()->set('xero-bridge.database.connection', 'prefixed');

    Schema::connection('prefixed')->create('xero_connections', function (Blueprint $t) {
        $t->id();
    });

    expect(diagnostics()->tableProblems()[Diagnostics::CONNECTIONS_TABLE])
        ->toContain('[app_xero_connections]');
});

it('reports a feature table that is not the package\'s only while the feature is on', function (string $key, string $table, string $flag, string $env) {
    diagForeignTable($table);

    expect(diagnostics()->tableProblems())->toBe([]);

    config()->set('xero-bridge.'.$flag, true);

    $problems = diagnostics()->tableProblems();

    expect($problems)->toHaveKey($key)
        ->and($problems[$key])->toContain("The table [{$table}] is not the package's")
        ->toContain("Set {$env} (config key xero-bridge.{$key})")
        ->and(diagnostics()->warnings([]))->toContain($problems[$key]);
})->with([
    'the write ledger' => ['writes.table', 'xero_write_records', 'writes.enabled', 'XERO_WRITES_TABLE'],
    'webhook replay' => ['webhooks.dedupe.table', 'xero_webhook_events', 'webhooks.dedupe.enabled', 'XERO_WEBHOOK_DEDUPE_TABLE'],
    'API capture' => ['capture.table', 'xero_api_calls', 'capture.enabled', 'XERO_CAPTURE_TABLE'],
]);

it('says a strict ledger refuses writes over a table that is not the package\'s', function () {
    config()->set('xero-bridge.writes.enabled', true);
    config()->set('xero-bridge.writes.strict', true);
    diagForeignTable('xero_write_records');

    expect(diagnostics()->tableProblems()['writes.table'])
        ->toContain('writes are NOT protected against duplicates')
        ->toContain('with XERO_WRITES_STRICT on, every write named with for() is refused');
});

it('stays silent about replay and capture tables that are missing', function () {
    // docs/09 promises a switched-on flag with no table is a no-op, and the
    // README's own sample block switches capture on.
    config()->set('xero-bridge.webhooks.dedupe.enabled', true);
    config()->set('xero-bridge.capture.enabled', true);
    Schema::drop('xero_webhook_events');
    Schema::drop('xero_api_calls');

    expect(diagnostics()->tableProblems())->toBe([])
        ->and(implode(' ', diagnostics()->warnings([])))
        ->not->toContain('xero_webhook_events')
        ->not->toContain('xero_api_calls');
});

it('checks the tables without logging a word', function () {
    // TableGuard would warn for each absent table it met; status, run every
    // minute, would fill the log with them.
    config()->set('xero-bridge.writes.enabled', true);
    config()->set('xero-bridge.webhooks.dedupe.enabled', true);
    config()->set('xero-bridge.capture.enabled', true);
    Schema::drop('xero_write_records');
    Schema::drop('xero_webhook_events');
    Schema::drop('xero_api_calls');

    Log::spy();
    // So a TableGuard built from here on would log through the spy.
    app()->forgetInstance(TableGuard::class);

    diagnostics()->snapshot();

    Log::shouldNotHaveReceived('warning');
});

it('holds the same columns as each migration it checks against', function (string $key) {
    // The stub's private COLUMNS is the authority; Diagnostics keeps a copy,
    // because a published migration must never depend on a package class.
    $tables = (new ReflectionClass(Diagnostics::class))->getConstant('TABLES');
    $migration = include __DIR__.'/../../database/migrations/'.$tables[$key]['migration'].'.php.stub';

    expect($tables[$key]['columns'])
        ->toEqualCanonicalizing((new ReflectionObject($migration))->getConstant('COLUMNS'));
})->with(['database.table', 'writes.table', 'webhooks.dedupe.table', 'capture.table']);

it('checks every Xero table the package ships a migration for', function () {
    $shipped = collect(File::files(__DIR__.'/../../database/migrations'))
        ->map(fn (SplFileInfo $file): string => Str::before($file->getFilename(), '.php.stub'))
        ->reject(fn (string $name): bool => str_contains($name, 'myinvois'))
        ->sort()
        ->values()
        ->all();

    $checked = collect((new ReflectionClass(Diagnostics::class))->getConstant('TABLES'))
        ->pluck('migration')
        ->sort()
        ->values()
        ->all();

    expect($checked)->toBe($shipped);
});

/*
|--------------------------------------------------------------------------
| The migrations table
|--------------------------------------------------------------------------
*/

it('warns when a package table is recorded as created by two migrations', function () {
    // The 1.4.2 incident: the same migration published twice under two
    // timestamps, the second recorded as run over the first's table.
    diagRecordMigrations([
        '2026_01_15_090000_create_xero_connections_table' => 1,
        '2026_01_15_090001_create_xero_write_records_table' => 1,
        '2026_03_02_101500_create_xero_connections_table' => 3,
    ]);

    $duplicates = collect(diagnostics()->warnings([]))
        ->filter(fn (string $warning): bool => str_contains($warning, 'more than once'))
        ->values();

    // Neither file is in database/migrations, so neither can be told from a
    // host's own migration of that name: said, after the advice it qualifies.
    expect($duplicates)->toHaveCount(1)
        ->and($duplicates[0])->toBe(
            'The migrations table records the package\'s [xero_connections] table as created more than once: '
            .'2026_01_15_090000_create_xero_connections_table (batch 1), '
            .'2026_03_02_101500_create_xero_connections_table (batch 3). Rolling back the later batch would '
            .'drop the live table. Delete the LATER file and its row in the migrations table -- keep the '
            .'first -- and never roll back a batch that still contains it. '
            .'2026_01_15_090000_create_xero_connections_table and 2026_03_02_101500_create_xero_connections_table '
            .'have no file in database/migrations, so whether each is the migration published from this package '
            .'cannot be checked from here: never delete a migration of your own, or its row.'
        );
});

it('warns about two copies of the package\'s migration without conditions when both files are there', function () {
    // The 1.4.2 incident as it happens: both copies committed, both run.
    $files = [
        diagMigrationFile('2026_01_15_090000_create_xero_connections_table', package: true),
        diagMigrationFile('2026_03_02_101500_create_xero_connections_table', package: true),
    ];

    try {
        diagRecordMigrations([
            '2026_01_15_090000_create_xero_connections_table' => 1,
            '2026_03_02_101500_create_xero_connections_table' => 3,
        ]);

        expect(diagnostics()->warnings([]))->toContain(
            'The migrations table records the package\'s [xero_connections] table as created more than once: '
            .'2026_01_15_090000_create_xero_connections_table (batch 1), '
            .'2026_03_02_101500_create_xero_connections_table (batch 3). Rolling back the later batch would '
            .'drop the live table. Delete the LATER file and its row in the migrations table -- keep the '
            .'first -- and never roll back a batch that still contains it.'
        );
    } finally {
        File::delete($files);
    }
});

it('never counts a host\'s own migration of the same name as a second copy', function () {
    // `php artisan make:migration create_xero_connections_table` gives a
    // host's own migration the package's name exactly.
    $file = diagMigrationFile('2023_05_01_000000_create_xero_connections_table', package: false);

    try {
        diagRecordMigrations([
            '2023_05_01_000000_create_xero_connections_table' => 1,
            '2026_01_15_090000_create_xero_connections_table' => 2,
        ]);

        expect(implode(' ', diagnostics()->warnings([])))
            ->not->toContain('more than once')
            ->not->toContain('2023_05_01_000000_create_xero_connections_table');
    } finally {
        File::delete($file);
    }
});

it('never tells anyone to delete the row of a host\'s own migration of the same name', function () {
    // A host that made its own xero_connections table the usual way has a
    // migration named exactly like the package's. Told to delete its row,
    // the next migrate would run its Schema::create over the table it
    // already made: error 1050, and the deployment halts.
    $file = diagMigrationFile('2023_05_01_000000_create_xero_connections_table', package: false);

    try {
        diagRecordMigrations(['2023_05_01_000000_create_xero_connections_table' => 1]);
        diagForeignTable('xero_connections');

        $foreign = diagnostics()->tableProblems()[Diagnostics::CONNECTIONS_TABLE];

        // Then XERO_DB_TABLE is moved off the host's table, as that advises.
        config()->set('xero-bridge.database.table', 'xero_bridge_connections');

        $missing = diagnostics()->tableProblems()[Diagnostics::CONNECTIONS_TABLE];

        expect($foreign)->toContain('Set XERO_DB_TABLE (config key xero-bridge.database.table) to an unused, bare table name')
            ->and($missing)->toBe(
                'The connections table [xero_bridge_connections] does not exist, so nothing can be connected or '
                .'refreshed. Run php artisan migrate -- first php artisan vendor:publish '
                .'--tag=xero-bridge-migrations if the package\'s migrations are not published yet.'
            );

        foreach ([$foreign, $missing] as $advice) {
            expect($advice)->not->toContain('2023_05_01_000000_create_xero_connections_table')
                ->not->toContain('delete its row')
                ->not->toContain('recorded as run');
        }
    } finally {
        File::delete($file);
    }
});

it('advises without conditions when the recorded migration\'s file is the package\'s', function () {
    $file = diagMigrationFile('2026_01_15_090000_create_xero_connections_table', package: true);

    try {
        diagRecordMigrations(['2026_01_15_090000_create_xero_connections_table' => 1]);
        Schema::drop('xero_connections');

        expect(diagnostics()->tableProblems()[Diagnostics::CONNECTIONS_TABLE])->toBe(
            'The connections table [xero_connections] does not exist, so nothing can be connected or refreshed. '
            .'If you changed XERO_DB_TABLE or XERO_DB_CONNECTION after migrating, change it back instead: the '
            .'table is where its migration, 2026_01_15_090000_create_xero_connections_table, created it. '
            .'Otherwise that migration is recorded as run, so php artisan migrate will not recreate it: delete '
            .'its row from the migrations table, then run php artisan migrate.'
        );
    } finally {
        File::delete($file);
    }
});

it('recognises each migration it publishes by a string every copy of its stub carries', function () {
    // isPackageMigration() looks for it, so a stub that stopped reading its
    // config key would make status and install take every copy of it for a
    // host's own migration.
    $shipped = collect(File::files(__DIR__.'/../../database/migrations'))
        ->mapWithKeys(fn (SplFileInfo $file): array => [
            Str::before($file->getFilename(), '.php.stub') => File::get($file->getPathname()),
        ]);

    expect(array_keys(Diagnostics::MIGRATIONS))->toEqualCanonicalizing($shipped->keys()->all());

    foreach (Diagnostics::MIGRATIONS as $migration => $fingerprint) {
        expect($shipped[$migration])->toContain($fingerprint);
    }

    // Each Xero table's is the config key that names that table.
    foreach ((new ReflectionClass(Diagnostics::class))->getConstant('TABLES') as $key => $table) {
        expect(Diagnostics::MIGRATIONS[$table['migration']])->toBe('xero-bridge.'.$key);
    }
});

it('says nothing about migrations recorded once each, or with no migrations table at all', function () {
    expect(implode(' ', diagnostics()->warnings([])))->not->toContain('more than once');

    diagRecordMigrations([
        '2026_01_15_090000_create_xero_connections_table' => 1,
        '2026_01_15_090001_create_xero_write_records_table' => 1,
    ]);

    expect(implode(' ', diagnostics()->warnings([])))->not->toContain('more than once');
});

it('does not send someone to migrate a table whose migration is already recorded', function () {
    // Dropped by hand, or by rolling back a later duplicate: migrate would
    // skip the recorded migration and create nothing.
    diagRecordMigrations(['2026_01_15_090000_create_xero_connections_table' => 1]);
    Schema::drop('xero_connections');

    expect(diagnostics()->tableProblems()[Diagnostics::CONNECTIONS_TABLE])
        // Its file is not there to check, so it might be a host's own
        // migration of the same name: the advice says it rests on that.
        ->toContain('2026_01_15_090000_create_xero_connections_table is recorded as run, but its file is not in '
            .'database/migrations, so whether it is the migration published from this package cannot be checked '
            .'from here. If it is a migration of your own, leave its row alone and run php artisan migrate -- '
            .'first php artisan vendor:publish --tag=xero-bridge-migrations if the package\'s migrations are not '
            .'published yet; what follows applies only if it is the package\'s.')
        // A setting changed after migrating looks the same from here, and
        // recreating the table would strand the real one -- so that comes first.
        ->toContain('If you changed XERO_DB_TABLE or XERO_DB_CONNECTION after migrating, change it back instead')
        ->toContain('where its migration, 2026_01_15_090000_create_xero_connections_table, created it')
        ->toContain('so php artisan migrate will not recreate it')
        ->toContain('delete its row from the migrations table, then run php artisan migrate');
});

it('warns against rolling back a migration recorded over a stranger\'s table', function () {
    // What 1.4.3's guard recorded: the 1.4.3 down() drops whatever table has
    // the name.
    diagRecordMigrations(['2026_01_15_090000_create_xero_connections_table' => 1]);
    diagForeignTable('xero_connections');

    expect(diagnostics()->tableProblems()[Diagnostics::CONNECTIONS_TABLE])
        ->toContain('2026_01_15_090000_create_xero_connections_table is recorded as run, but its file is not in '
            .'database/migrations, so whether it is the migration published from this package cannot be checked '
            .'from here. If it is a migration of your own, leave its row alone; what follows applies only if it '
            .'is the package\'s. If you changed')
        ->toContain('If you changed XERO_DB_TABLE or XERO_DB_CONNECTION after migrating, change it back instead')
        ->toContain('its migration, 2026_01_15_090000_create_xero_connections_table, created it')
        ->toContain('recorded as run over this table, so delete its row from the migrations table before migrating')
        ->toContain('never roll it back');
});

/*
|--------------------------------------------------------------------------
| The write ledger
|--------------------------------------------------------------------------
*/

it('reports the write ledger as off, and asks the database nothing, by default', function () {
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    expect(diagnostics()->writeLedger())->toBe([
        'enabled' => false,
        'strict' => false,
        'table' => 'xero_write_records',
        'table_present' => null,
        'stuck' => null,
    ])->and($queries)->toBe(0);

    expect(implode(' ', diagnostics()->warnings([])))->not->toContain('ledger');
});

it('reads a writes block without the flags as the ledger off', function () {
    // A published config that lost the lines, or a cache built before the
    // package could fill them in: the recorder reads both as false.
    config()->set('xero-bridge.writes', ['table' => 'xero_write_records']);

    expect(diagnostics()->writeLedger())
        ->toMatchArray(['enabled' => false, 'strict' => false, 'table_present' => null]);
});

it('warns that writes are unprotected when the ledger is on and its table is missing', function (bool $strict) {
    config()->set('xero-bridge.writes.enabled', true);
    config()->set('xero-bridge.writes.strict', $strict);
    Schema::drop('xero_write_records');

    expect(diagnostics()->writeLedger())->toMatchArray(['table_present' => false, 'stuck' => null]);

    $warning = collect(diagnostics()->warnings([]))
        ->first(fn (string $line): bool => str_contains($line, 'XERO_WRITES_LEDGER'));

    expect($warning)->toContain('The write ledger is on (XERO_WRITES_LEDGER) but its table [xero_write_records] does not exist')
        ->toContain('writes are NOT protected against duplicates')
        ->toContain('Run php artisan migrate -- first php artisan vendor:publish --tag=xero-bridge-migrations');

    $strict
        ? expect($warning)->toContain('with XERO_WRITES_STRICT on, every write named with for() is refused until it does')
        : expect($warning)->not->toContain('refused');
})->with([
    'strict off' => [false],
    'strict on' => [true],
]);

it('counts claims stuck for over an hour, as xero-bridge:prune does', function (string $status, int $minutesAgo, int $stuck) {
    config()->set('xero-bridge.writes.enabled', true);
    diagLedgerRow($status, $minutesAgo);

    expect(diagnostics()->writeLedger())->toMatchArray(['table_present' => true, 'stuck' => $stuck]);

    $warnings = implode(' ', diagnostics()->warnings([]));

    $stuck > 0
        ? expect($warnings)->toContain(Diagnostics::stuckClaims($stuck))
        : expect($warnings)->not->toContain('pending for over an hour');
})->with([
    'a claim pending for two hours' => ['pending', 120, 1],
    'a claim pending for two minutes' => ['pending', 2, 0],
    'an old claim that succeeded' => ['succeeded', 120, 0],
]);

it('says nothing about stuck claims while the ledger is off', function () {
    diagLedgerRow('pending', 120);

    expect(diagnostics()->writeLedger()['stuck'])->toBeNull()
        ->and(implode(' ', diagnostics()->warnings([])))->not->toContain('pending for over an hour');
});

it('does not count the rows of a ledger table that is not the package\'s', function () {
    // tableProblems() says what is wrong with it, once.
    config()->set('xero-bridge.writes.enabled', true);
    diagForeignTable('xero_write_records');

    expect(diagnostics()->writeLedger())->toMatchArray(['table_present' => true, 'stuck' => null]);

    expect(collect(diagnostics()->warnings([]))
        ->filter(fn (string $line): bool => str_contains($line, 'xero_write_records')))->toHaveCount(1);
});

it('says when the ledger table could not be checked', function () {
    config()->set('database.connections.unreachable', [
        'driver' => 'sqlite',
        'database' => sys_get_temp_dir().'/xero-bridge-no-such-dir/none.sqlite',
        'prefix' => '',
    ]);
    config()->set('xero-bridge.database.connection', 'unreachable');
    config()->set('xero-bridge.writes.enabled', true);

    expect(diagnostics()->writeLedger()['table_present'])->toBeNull()
        ->and(diagnostics()->warnings([]))->toContain(
            'Could not check the write ledger table [xero_write_records], so whether writes are protected '
            .'against duplicates is unknown.'
        );
});

/*
|--------------------------------------------------------------------------
| The console snapshot
|--------------------------------------------------------------------------
*/

it('appends the new checks to the console snapshot, after every existing key', function () {
    expect(array_keys(diagnostics()->snapshot()))->toBe([
        'config',
        'preflight',
        'urls',
        'connections',
        'warnings',
        'table_problems',
        'write_ledger',
        'notes',
    ]);
});

it('builds a snapshot over a connections table it cannot read, instead of throwing', function (string $state) {
    // Otherwise the console page would be a 500 rather than the problem.
    // (SQLite reads an unknown double-quoted column as a string, so only the
    // missing table makes the connections query itself throw here; MySQL
    // throws on both.)
    $state === 'missing' ? Schema::drop('xero_connections') : diagForeignTable('xero_connections');

    $snapshot = diagnostics()->snapshot();

    expect($snapshot['connections'])->toBe([])
        ->and($snapshot['table_problems'])->toHaveKey(Diagnostics::CONNECTIONS_TABLE)
        ->and($snapshot['warnings'])->toContain($snapshot['table_problems'][Diagnostics::CONNECTIONS_TABLE]);
})->with(['missing', 'foreign']);

it('builds a snapshot over a ConnectionRepository of the host\'s own that cannot be read', function () {
    // A host that bound its own repository is never judged by the package's
    // table; what reading through the repository throws is the problem.
    $this->mock(ConnectionRepository::class)
        ->shouldReceive('all')
        ->andThrow(new RuntimeException('The tenant database is unreachable.'));
    app()->forgetInstance(TokenManager::class);
    app()->forgetInstance(Diagnostics::class);

    Schema::drop('xero_connections');

    $snapshot = diagnostics()->snapshot();

    expect($snapshot['connections'])->toBe([])
        ->and($snapshot['table_problems'][Diagnostics::CONNECTIONS_TABLE])->toBe(
            'Could not read the stored connections: The tenant database is unreachable. Until they can be '
            .'read, the state of every connection is unknown.'
        )
        ->and($snapshot['warnings'])->toContain($snapshot['table_problems'][Diagnostics::CONNECTIONS_TABLE]);
});
