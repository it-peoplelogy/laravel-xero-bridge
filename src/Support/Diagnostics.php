<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\NullStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Peoplelogy\XeroBridge\Contracts\ConnectionRepository;
use Peoplelogy\XeroBridge\Exceptions\XeroConfigurationException;
use Peoplelogy\XeroBridge\Models\XeroConnection;
use Peoplelogy\XeroBridge\Models\XeroWriteRecord;
use Peoplelogy\XeroBridge\OAuth\TokenManager;
use Peoplelogy\XeroBridge\Repositories\EloquentConnectionRepository;
use Throwable;

/**
 * Everything that can be said about the state of the integration WITHOUT
 * calling Xero.
 *
 * Shared by `xero-bridge:status` and the test console so the two can never
 * disagree about what "healthy" means. Nothing here performs an API request:
 * the most it does is read the package's tables, the migrations table and
 * the files of the package migrations it records, count the write ledger's
 * stuck claims, and take one cache lock.
 *
 * No method on this class may return an access or refresh token. The model
 * hides them, and a status dump is the obvious place for one to leak.
 *
 * Its table checks never go through TableGuard. That class logs a warning for
 * every absent table it meets and remembers what it learns for the life of
 * the process -- right for a recorder on the hot path, wrong for a command
 * that monitoring runs as a fresh process every minute: it would write a log
 * line per absent table per run, and say nothing the log does not.
 */
final class Diagnostics
{
    /**
     * Where tableProblems() files the connections table. Its problem is the
     * one that stops a report: connections() would throw on that table.
     */
    public const CONNECTIONS_TABLE = 'database.table';

    /**
     * The package's Xero tables, keyed by the config key that names each.
     *
     * `columns` is the private COLUMNS of the stub `migration` names: every
     * column that migration first creates, the set it tells its own table
     * from a stranger's by. A published migration must never reference a
     * package class -- it outlives `composer remove` -- so the stub cannot
     * read this list and this does not read the stub; a test holds the two
     * equal instead.
     *
     * `flag` is the switch that puts a table to use; null means always.
     *
     * MyInvois's table is missing on purpose. This reports on Xero, and a
     * module most installs never enable must never turn its report red.
     */
    private const TABLES = [
        'database.table' => [
            'default' => 'xero_connections',
            'env' => 'XERO_DB_TABLE',
            'flag' => null,
            'what' => 'connections',
            'migration' => 'create_xero_connections_table',
            'consequence' => 'nothing can be connected or refreshed',
            'columns' => [
                'key', 'invalidated_at', 'invalidated_reason', 'last_failure_at', 'failure_count', 'auth_event_id',
                'id', 'tenant_id', 'connection_id', 'tenant_name', 'tenant_type', 'access_token', 'refresh_token',
                'expires_at', 'scopes', 'last_refreshed_at', 'created_at', 'updated_at',
            ],
        ],
        'writes.table' => [
            'default' => 'xero_write_records',
            'env' => 'XERO_WRITES_TABLE',
            'flag' => 'writes.enabled',
            'what' => 'write ledger',
            'migration' => 'create_xero_write_records_table',
            'consequence' => 'writes are NOT protected against duplicates',
            'columns' => [
                'claim_key', 'connection_key', 'xero_number', 'xero_status', 'claimed_at', 'succeeded_at',
                'id', 'operation', 'owner_type', 'owner_id', 'reference', 'status', 'xero_id', 'idempotency_key',
            ],
        ],
        'webhooks.dedupe.table' => [
            'default' => 'xero_webhook_events',
            'env' => 'XERO_WEBHOOK_DEDUPE_TABLE',
            'flag' => 'webhooks.dedupe.enabled',
            'what' => 'webhook replay',
            'migration' => 'create_xero_webhook_events_table',
            'consequence' => 'a replayed webhook delivery is dispatched again, and every event logs a warning',
            'columns' => [
                'dedupe_key', 'event_date_utc', 'first_seen_at', 'last_seen_at', 'delivery_count',
                'id', 'tenant_id', 'resource_id', 'event_type', 'event_category',
            ],
        ],
        'capture.table' => [
            'default' => 'xero_api_calls',
            'env' => 'XERO_CAPTURE_TABLE',
            'flag' => 'capture.enabled',
            'what' => 'API capture',
            'migration' => 'create_xero_api_calls_table',
            'consequence' => 'no API call is recorded',
            'columns' => [
                'logical_call_id', 'redacted_keys', 'connection_key', 'channel', 'attempt', 'correlation_id',
                'id', 'tenant_id', 'method', 'url', 'request_headers', 'request_body', 'status', 'response_headers',
                'response_body', 'content_type', 'response_bytes', 'duration_ms', 'error', 'idempotency_key',
                'owner_type', 'owner_id', 'created_at',
            ],
        ],
    ];

    /**
     * Every migration the package publishes, by the name its published file
     * ends with, valued with the config key that names its table: the string
     * that tells a copy of the stub from a host's own migration of the same
     * name (isPackageMigration()). A test holds each stub to it.
     *
     * MyInvois's is here although its table is not reported on: a host's
     * migration can take its name as easily, and xero-bridge:install says so.
     */
    public const MIGRATIONS = [
        'create_xero_connections_table' => 'xero-bridge.database.table',
        'create_xero_webhook_events_table' => 'xero-bridge.webhooks.dedupe.table',
        'create_xero_write_records_table' => 'xero-bridge.writes.table',
        'create_xero_api_calls_table' => 'xero-bridge.capture.table',
        'create_myinvois_validations_table' => 'myinvois.audit.table',
    ];

    public function __construct(
        private readonly XeroConfig $config,
        private readonly TokenManager $tokens,
        private readonly CacheFactory $cache,
        // The binding TokenManager stores connections through, resolved the
        // same way: only the package's own keeps them in the package's table.
        private readonly ConnectionRepository $repository,
    ) {}

    /**
     * What xero-bridge:status and xero-bridge:prune both say about write
     * claims left pending, so the two cannot drift apart.
     */
    public static function stuckClaims(int $count): string
    {
        return sprintf(
            '%d write claim(s) have been pending for over an hour. Each means something was sent to '
            .'Xero and the outcome was never recorded, so further writes for those records are '
            .'BLOCKED. Check Xero, then resolve the rows by hand -- nothing will re-send on its own, '
            .'because re-sending could duplicate a record that already exists.',
            $count,
        );
    }

    /**
     * Configuration that is absent or wrong, keyed by config key, valued with
     * the environment variable that sets it.
     *
     * @return array<string, string>
     */
    public function missingConfig(): array
    {
        $missing = [];

        if (($this->config->get('client_id') ?? '') === '') {
            $missing['client_id'] = 'XERO_CLIENT_ID';
        }

        if (($this->config->get('client_secret') ?? '') === '') {
            $missing['client_secret'] = 'XERO_CLIENT_SECRET';
        }

        if (! Scopes::has($this->config->get('scopes'), Scopes::OFFLINE_ACCESS)) {
            $missing['scopes'] = 'XERO_SCOPES (must include offline_access)';
        }

        return $missing;
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(XeroConnection $connection): array
    {
        return [
            'key' => (string) $connection->key,
            'organisation' => $connection->displayName(),
            'tenant_id' => (string) $connection->tenant_id,
            'tenant_type' => $connection->tenant_type,
            'expires_at' => $connection->expires_at?->toIso8601String(),
            'expires_in' => $connection->expires_at
                ? (int) now()->diffInSeconds($connection->expires_at, false)
                : null,
            'expired' => $connection->isExpired(),
            'usable' => $connection->isUsable(),
            'needs_reauthorisation' => $connection->isInvalidated(),
            'invalidated_reason' => $connection->invalidated_reason,
            'failure_count' => $connection->failure_count,
            'last_refreshed_at' => $connection->last_refreshed_at?->toIso8601String(),
            'last_failure_at' => $connection->last_failure_at?->toIso8601String(),
            'scopes' => $connection->scopeList(),
            'connect_url' => $this->config->connectUrl((string) $connection->key),
            // Appended, like every key added since 1.2.0. False after an
            // APP_KEY rotation without APP_PREVIOUS_KEYS -- which used to kill
            // this whole report with an uncaught DecryptException.
            'tokens_readable' => $this->tokensReadable($connection),
        ];
    }

    /**
     * Whether this APP_KEY can decrypt the connection's stored tokens. Both
     * are read, because a rotation can leave either one unreadable.
     */
    private function tokensReadable(XeroConnection $connection): bool
    {
        try {
            // Each read runs the encrypted cast, which is what can throw.
            $connection->getAttribute('access_token');
            $connection->getAttribute('refresh_token');

            return true;
        } catch (DecryptException) {
            return false;
        }
    }

    /**
     * Every stored connection, described.
     *
     * Throws on a missing connections table, and on most databases on one
     * that is not the package's own; callers that must not fail go through
     * readConnections(), as status and snapshot() do.
     *
     * @return list<array<string, mixed>>
     */
    public function connections(): array
    {
        return $this->tokens->all()
            ->map(fn (XeroConnection $c): array => $this->describe($c))
            ->values()
            ->all();
    }

    /**
     * connections() for a report that must not fail: the connections, and
     * tableProblems() with the reason they could not be read filed under
     * CONNECTIONS_TABLE when it comes to that.
     *
     * Not asked at all while the connections table already has a problem:
     * the query behind it would throw on that table. Otherwise anything the
     * repository throws becomes the problem, where it used to end the report
     * with an uncaught exception. For a host that bound its own
     * ConnectionRepository this is the only check there is: its connections
     * need not live in the package's table at all.
     *
     * @param  array<string, string>  $problems  the output of tableProblems()
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
     */
    public function readConnections(array $problems): array
    {
        if (isset($problems[self::CONNECTIONS_TABLE])) {
            return [[], $problems];
        }

        try {
            return [$this->connections(), $problems];
        } catch (Throwable $e) {
            $problems[self::CONNECTIONS_TABLE] = sprintf(
                'Could not read the stored connections: %s Until they can be read, the state of every '
                .'connection is unknown.',
                rtrim($e->getMessage(), '.').'.',
            );

            return [[], $problems];
        }
    }

    /**
     * Package tables that stand in the way of the package working, keyed by
     * the config key that names the table, each valued with one line naming
     * the physical table and the way out.
     *
     * The connections table counts when it is missing, cannot be read, or is
     * not the package's own: nothing can be connected or refreshed, and the
     * query behind connections() would throw on it. A feature table counts
     * only while its feature is on AND the table under its name is a
     * stranger's -- the ledger then sends every write unprotected, the replay
     * table lets redeliveries through, capture records nothing. One that is
     * merely missing does not count: a flag with no table is a documented
     * no-op, and the README's own sample switches capture on. The write
     * ledger is the exception, and writeLedger() reports it, because there a
     * missing table means writes nobody is protecting.
     *
     * The connections table is the configured model's own, and is looked at
     * only while the package's EloquentConnectionRepository stores the
     * connections. A host that bound its own repository keeps them wherever
     * it likes, so the package's table is not where they are and its absence
     * says nothing; readConnections() asks that repository instead.
     *
     * @return array<string, string>
     */
    public function tableProblems(): array
    {
        $problems = [];

        // Read only when a message needs it, and then once: the advice
        // depends on whether the package's migration is already recorded.
        $recorded = null;
        $migrations = function () use (&$recorded): array {
            return $recorded ??= $this->recordedMigrations() ?? [];
        };

        foreach (self::TABLES as $key => $table) {
            if ($table['flag'] !== null && ! (bool) $this->config->get($table['flag'], false)) {
                continue;
            }

            if ($key === self::CONNECTIONS_TABLE && ! $this->packageStoresConnections()) {
                continue;
            }

            $state = $this->inspectTable($key);
            $physical = $this->tableName($key, $table['default']);

            if ($key === self::CONNECTIONS_TABLE && $state['present'] === null) {
                $problems[$key] = sprintf(
                    'Could not check the connections table [%s]: %s Until it can be read, the state of every '
                    .'connection is unknown.',
                    $physical,
                    rtrim((string) $state['error'], '.').'.',
                );
            } elseif ($key === self::CONNECTIONS_TABLE && $state['present'] === false) {
                $problems[$key] = sprintf(
                    'The connections table [%s] does not exist, so nothing can be connected or refreshed. %s',
                    $physical,
                    $this->createAdvice($key, $migrations()),
                );
            } elseif ($state['present'] === true && $state['missing'] !== []) {
                $problems[$key] = $this->foreignTable($key, $physical, $state['missing'], $migrations());
            }
        }

        return $problems;
    }

    /**
     * The write ledger's health, so a consumer can see that the duplicate
     * protection they switched on is actually in force.
     *
     * Both flags are reported as the recorder READS them, fallbacks included:
     * a published or cached config that lacks the strict line reads as off
     * here exactly as it does there, so this is where to check an opt-in took.
     *
     * Switched off, it runs no query at all and reports nulls -- the same
     * silence MyInvois gets, for a feature most installs never enable.
     * Switched on, table_present is true or false, or null when the check
     * itself failed; stuck counts the claims pending for over an hour, with
     * the scope and threshold xero-bridge:prune uses, and is null whenever it
     * was not counted.
     *
     * @return array{enabled: bool, strict: bool, table: string, table_present: bool|null, stuck: int|null}
     */
    public function writeLedger(): array
    {
        $ledger = [
            'enabled' => (bool) $this->config->get('writes.enabled', false),
            'strict' => (bool) $this->config->get('writes.strict', false),
            'table' => $this->tableName('writes.table', 'xero_write_records'),
            'table_present' => null,
            'stuck' => null,
        ];

        if (! $ledger['enabled']) {
            return $ledger;
        }

        $state = $this->inspectTable('writes.table');
        $ledger['table_present'] = $state['present'];

        // A stranger's table: tableProblems() says so, and counting its rows
        // would only fail on the columns it does not have.
        if ($state['present'] !== true || $state['missing'] !== []) {
            return $ledger;
        }

        try {
            $ledger['stuck'] = XeroWriteRecord::query()->stuck()->count();
        } catch (Throwable) {
            // Readable a moment ago and not now: as unknown as a failed check.
            $ledger['table_present'] = null;
        }

        return $ledger;
    }

    /**
     * Things that need a person, short of missing configuration and a
     * connection that must be authorised again.
     *
     * Every table problem is among them, so the console -- which renders this
     * list -- shows each one; xero-bridge:status prints the connections-table
     * one as the error it is instead. A lock store that cannot lock at all is
     * NOT here: preflight() reports it, and the console shows both lists in
     * one panel, so naming it twice would print it twice.
     *
     * @param  list<array<string, mixed>>  $rows  the output of connections()
     * @param  array<string, mixed>|null  $ledger  the output of writeLedger(), when the caller has it
     * @param  array<string, string>|null  $tableProblems  the output of tableProblems(), likewise
     * @return list<string>
     */
    public function warnings(array $rows, ?array $ledger = null, ?array $tableProblems = null): array
    {
        $ledger ??= $this->writeLedger();
        $warnings = array_values($tableProblems ?? $this->tableProblems());
        $recorded = $this->recordedMigrations() ?? [];

        array_push($warnings, ...$this->ledgerWarnings($ledger, $recorded));
        array_push($warnings, ...$this->duplicateMigrations($recorded));

        // Nothing about a missing XERO_WEBHOOK_KEY. Without one no webhook
        // route is served (XeroConfig::webhooksActive()), which is exactly
        // what an application that only calls Xero wants: webhooks off is a
        // state, not a fault, and --strict must not fail every such install.

        $scope = $this->lockScope();
        $store = $this->lockStoreName();

        if ($scope === 'one process') {
            $warnings[] = "The [{$store}] cache store holds the token-refresh and webhook-retry locks, but "
                .'only locks inside one process: two token refreshes in different processes would invalidate '
                .'each other, and a retried webhook can be queued twice. Set XERO_LOCK_STORE to a redis, '
                .'memcached or database store.';
        } elseif ($scope === 'one server' && ! $this->lockStoreChosen()) {
            // Only when nobody chose it. XERO_LOCK_STORE naming a file store
            // is a decision, and notes() reports it as one.
            $warnings[] = "The default cache store [{$store}] holds the token-refresh and webhook-retry locks "
                .'because XERO_LOCK_STORE is not set, and it only locks within one server: with the scheduler, '
                .'queue workers or web servers on more than one, two token refreshes would invalidate each '
                .'other and a retried webhook can be queued twice. Set XERO_LOCK_STORE to a redis, memcached or '
                ."database store -- or, if everything runs on this one server, to {$store}, to record that choice.";
        }

        foreach ($rows as $row) {
            // `?? true`: a row described before this key existed is readable
            // as far as anyone knows.
            if (! ($row['tokens_readable'] ?? true) && ! $row['needs_reauthorisation']) {
                $warnings[] = sprintf(
                    'The stored Xero tokens for connection [%s] cannot be decrypted, which normally means '
                    .'APP_KEY changed since they were saved. Put the old key in APP_PREVIOUS_KEYS, or '
                    .'re-authorise at %s.',
                    $row['key'],
                    $row['connect_url'],
                );
            }

            if ($row['failure_count'] > 0 && ! $row['needs_reauthorisation']) {
                $warnings[] = sprintf(
                    'Connection [%s] has %d recent transient failure(s).',
                    $row['key'],
                    $row['failure_count'],
                );
            }
        }

        return $warnings;
    }

    /**
     * Facts worth knowing that are not faults: a choice made explicitly,
     * which the package cannot tell from a mistake and so does not call one.
     * xero-bridge:status prints them and --strict never counts them.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        $notes = [];

        if ($this->lockStoreChosen() && $this->lockScope() === 'one server') {
            $store = $this->lockStoreName();

            // A file store does serialise processes -- through flock(), on
            // that server's disk -- so one server is genuinely covered.
            $notes[] = "XERO_LOCK_STORE names the [{$store}] cache store, which locks within one server only: "
                .'right while the scheduler, queue workers and web servers share that server, and not once they '
                .'do not. It was chosen explicitly, so xero-bridge:status --strict does not count it.';
        }

        return $notes;
    }

    /**
     * The two things that fail late and confusingly if they are wrong, so they
     * are proven up front instead: the consent flow refuses to start on a bad
     * redirect URI, and a token refresh dies inside the lock if the configured
     * cache store cannot be reached.
     *
     * @return array<string, array<string, mixed>>
     */
    public function preflight(): array
    {
        return [
            'connect' => $this->probe('Consent flow can start', function (): ?string {
                $this->config->assertReadyToConnect();

                return null;
            }),
            'lock' => $this->probe('Token-refresh lock is usable', fn (): ?string => $this->probeLock()),
        ];
    }

    /**
     * What the application is configured to do. The app keys are reported as
     * booleans only -- the client secret must never reach a browser or a log.
     *
     * The lock store comes with how far its locks reach and whether
     * XERO_LOCK_STORE chose it: the two facts warnings() and notes() judge it
     * by, so the console's pill can say what xero-bridge:status says. New keys
     * are only ever appended; a published copy of the console view reads the
     * others by name.
     *
     * @return array<string, mixed>
     */
    public function environment(): array
    {
        $scopes = (string) $this->config->get('scopes');

        return [
            'client_id_set' => ($this->config->get('client_id') ?? '') !== '',
            'client_secret_set' => ($this->config->get('client_secret') ?? '') !== '',
            'webhook_key_set' => $this->config->webhookKey() !== null,
            'redirect_uri' => $this->config->get('redirect_uri')
                ?: '(null - falls back to the callback route)',
            'scopes' => Scopes::parse($scopes),
            'has_offline_access' => Scopes::has($scopes, Scopes::OFFLINE_ACCESS),
            'default_connection' => $this->config->defaultConnection(),
            'table' => $this->tableName(self::CONNECTIONS_TABLE, 'xero_connections'),
            'routes_enabled' => (bool) $this->config->get('routes.enabled', true),
            // The flag as set, as it has always been reported. Whether a
            // webhook route is served also takes a key: webhook_key_set.
            'webhooks_enabled' => (bool) $this->config->get('webhooks.enabled', true),
            'lock_store' => $this->lockStoreName(),
            'idempotency' => (bool) $this->config->get('http.idempotency', true),
            'lock_scope' => $this->lockScope(),
            'lock_store_set' => $this->lockStoreChosen(),
        ];
    }

    /**
     * Every URL the integration exposes.
     *
     * Route::has() guarded throughout: a host that sets routes.enabled=false or
     * webhooks.enabled=false, or sets no XERO_WEBHOOK_KEY, has no such named
     * route, and an unguarded route() would turn a diagnostics page into a 500.
     *
     * The webhook's is judged by XeroConfig::webhooksActive() as well, the
     * rule the route was registered by: a route:cache built while a key was
     * set still carries the route once the key is gone, and a URL that can
     * only answer 401 is not one to register with Xero.
     *
     * @return array<string, string|null>
     */
    public function urls(): array
    {
        return [
            'connect' => $this->config->connectUrl($this->config->defaultConnection()),
            'callback' => $this->urlFor('callback'),
            'webhook' => $this->config->webhooksActive() ? $this->urlFor('webhook') : null,
            'console' => $this->urlFor('console'),
        ];
    }

    /**
     * The whole picture, as the console's boot payload.
     *
     * New keys are only ever appended. When the connections table is
     * unusable, connections() is not asked -- it would throw, and the page
     * would be a 500 instead of the problem, which is among the warnings --
     * and when reading them throws anyway, that is the problem instead.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        [$connections, $problems] = $this->readConnections($this->tableProblems());
        $ledger = $this->writeLedger();

        return [
            'config' => $this->environment(),
            'preflight' => $this->preflight(),
            'urls' => $this->urls(),
            'connections' => $connections,
            'warnings' => $this->warnings($connections, $ledger, $problems),
            'table_problems' => $problems,
            'write_ledger' => $ledger,
            'notes' => $this->notes(),
        ];
    }

    private function urlFor(string $suffix): ?string
    {
        $name = $this->config->routeName($suffix);

        return Route::has($name) ? route($name) : null;
    }

    /**
     * A table as the database will actually see it, prefix included. The
     * package stores a BARE name and lets the host connection apply its own
     * prefix, so the configured value alone is misleading.
     */
    private function tableName(string $configKey, string $default): string
    {
        try {
            [$connection, $table] = $this->locate($configKey);
        } catch (Throwable) {
            // A configured model that cannot be built: inspectTable() reports
            // that, under the name the configuration gives.
            [$connection, $table] = [$this->config->get('database.connection'), null];
        }

        $connection = $connection ?: config('database.default');

        $prefix = (string) config('database.connections.'.$connection.'.prefix');

        return $prefix.($table ?? (string) $this->config->get($configKey, $default));
    }

    /**
     * The connection and bare table name a package table is read through.
     *
     * The connections table is wherever the configured model keeps its rows:
     * a host's model -- xero-bridge.model -- may override getTable() or
     * getConnectionName(), and the repository queries through that model.
     * The other tables are where their config keys say, as their models read
     * them.
     *
     * @return array{0: string|null, 1: string}
     */
    private function locate(string $configKey): array
    {
        if ($configKey === self::CONNECTIONS_TABLE) {
            // Built as the repository builds it, so it names the same table.
            $class = (string) $this->config->get('model', XeroConnection::class);
            $model = new $class;

            return [$model->getConnectionName(), $model->getTable()];
        }

        return [
            $this->config->get('database.connection'),
            (string) $this->config->get($configKey, self::TABLES[$configKey]['default']),
        ];
    }

    /**
     * Whether the package's own repository stores the connections, so that
     * its table is where they live.
     */
    private function packageStoresConnections(): bool
    {
        return $this->repository instanceof EloquentConnectionRepository;
    }

    /**
     * Is the table there, and is it the package's? One quiet look.
     *
     * Through the Schema facade on the table's own connection, as the
     * migration stubs look, so the host's prefix is applied identically in
     * both. Columns are compared case-folded, the way Schema::hasColumns()
     * folds them, and columns a host added on top are ignored. `present` is
     * null when the check itself failed, with the reason in `error`.
     *
     * @return array{present: bool|null, missing: list<string>, error: string|null}
     */
    private function inspectTable(string $configKey): array
    {
        try {
            [$connection, $table] = $this->locate($configKey);

            $schema = Schema::connection($connection);

            if (! $schema->hasTable($table)) {
                return ['present' => false, 'missing' => [], 'error' => null];
            }

            $columns = array_map('strtolower', $schema->getColumnListing($table));

            return [
                'present' => true,
                'missing' => array_values(array_diff(self::TABLES[$configKey]['columns'], $columns)),
                'error' => null,
            ];
        } catch (Throwable $e) {
            return ['present' => null, 'missing' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * @param  list<string>  $missing  the columns the table lacks
     * @param  array<string, int>  $recorded  the output of recordedMigrations()
     */
    private function foreignTable(string $configKey, string $physical, array $missing, array $recorded): string
    {
        $table = self::TABLES[$configKey];
        $shown = array_slice($missing, 0, 3);
        $more = count($missing) - count($shown);

        $consequence = $table['consequence'];

        if ($configKey === 'writes.table' && (bool) $this->config->get('writes.strict', false)) {
            $consequence .= ' -- and with XERO_WRITES_STRICT on, every write named with for() is refused';
        }

        $names = $this->recordedFor($configKey, $recorded);
        $first = array_key_first($names);

        return sprintf(
            'The table [%s] is not the package\'s %s table (missing %s: "%s"%s), so %s. Set %s (config key '
            .'xero-bridge.%s) to an unused, bare table name -- the connection adds its own prefix -- and run '
            .'php artisan migrate.%s',
            $physical,
            $table['what'],
            count($missing) === 1 ? 'column' : 'columns',
            implode('", "', $shown),
            $more > 0 ? " and {$more} more" : '',
            $consequence,
            $table['env'],
            $configKey,
            // The recorded migration may have created the package's REAL table
            // somewhere else: a connection or table setting changed after
            // migrating looks exactly like this from here. Deleting the row and
            // migrating would then start an empty table and strand the real
            // one, so the cheap, safe explanation is offered first.
            $first === null ? '' : ' '.$this->caveat($first, $names[$first]).sprintf(
                'If you changed %s or XERO_DB_CONNECTION after migrating, change it back instead: the '
                .'package\'s table is still where its migration, %s, created it. Otherwise that migration is '
                .'recorded as run over this table, so delete its row from the migrations table before migrating '
                .'-- and never roll it back, which would drop the table that is there.',
                $table['env'],
                $first,
            ),
        );
    }

    /**
     * How to get a missing table created. Usually publish and migrate; but
     * when the migration that creates it is already recorded as run -- the
     * table was dropped by hand, or by rolling back a later duplicate of that
     * migration -- migrate skips it, and saying "migrate" would send someone
     * round in a circle.
     *
     * @param  array<string, int>  $recorded  the output of recordedMigrations()
     */
    private function createAdvice(string $configKey, array $recorded): string
    {
        $names = $this->recordedFor($configKey, $recorded);
        $first = array_key_first($names);

        if ($first === null) {
            // Publishing is a no-op when the migration is already published --
            // vendor:publish skips an existing file -- so it is offered as the
            // first step only for an install that never published it.
            return 'Run php artisan migrate -- first php artisan vendor:publish --tag=xero-bridge-migrations '
                .'if the package\'s migrations are not published yet.';
        }

        // As in foreignTable(): a setting changed after migrating looks the
        // same from here, and recreating the table would strand the real one.
        return $this->caveat(
            $first,
            $names[$first],
            ' and run php artisan migrate -- first php artisan vendor:publish --tag=xero-bridge-migrations if '
            .'the package\'s migrations are not published yet',
        ).sprintf(
            'If you changed %s or XERO_DB_CONNECTION after migrating, change it back instead: the table is '
            .'where its migration, %s, created it. Otherwise that migration is recorded as run, so php artisan '
            .'migrate will not recreate it: delete its row from the migrations table, then run php artisan migrate.',
            self::TABLES[$configKey]['env'],
            $first,
        );
    }

    /**
     * The condition advice about a recorded migration rests on when its file
     * could not be checked, as a sentence to put in front of that advice --
     * or nothing, when the file was found and is the package's.
     *
     * Nothing here can tell the package's migration from a host's own of the
     * same name without the file, and the advice ends in deleting the row:
     * for the host's own, that makes the next migrate create its table over
     * itself and halt the deployment.
     *
     * @param  string  $ownWayOut  appended to "leave its row alone": what to do instead
     */
    private function caveat(string $name, bool $checked, string $ownWayOut = ''): string
    {
        if ($checked) {
            return '';
        }

        return sprintf(
            '%s is recorded as run, but its file is not in database/migrations, so whether it is the migration '
            .'published from this package cannot be checked from here. If it is a migration of your own, leave '
            .'its row alone%s; what follows applies only if it is the package\'s. ',
            $name,
            $ownWayOut,
        );
    }

    /**
     * @param  array<string, mixed>  $ledger  the output of writeLedger()
     * @param  array<string, int>  $recorded  the output of recordedMigrations()
     * @return list<string>
     */
    private function ledgerWarnings(array $ledger, array $recorded): array
    {
        if (! ($ledger['enabled'] ?? false)) {
            return [];
        }

        $table = (string) ($ledger['table'] ?? '');

        if (($ledger['table_present'] ?? null) === false) {
            return [sprintf(
                'The write ledger is on (XERO_WRITES_LEDGER) but its table [%s] does not exist, so writes are '
                .'NOT protected against duplicates%s. %s',
                $table,
                ($ledger['strict'] ?? false)
                    ? ' -- and with XERO_WRITES_STRICT on, every write named with for() is refused until it does'
                    : '',
                $this->createAdvice('writes.table', $recorded),
            )];
        }

        if (($ledger['table_present'] ?? null) === null) {
            return [sprintf(
                'Could not check the write ledger table [%s], so whether writes are protected against duplicates '
                .'is unknown.',
                $table,
            )];
        }

        $stuck = (int) ($ledger['stuck'] ?? 0);

        return $stuck > 0 ? [self::stuckClaims($stuck)] : [];
    }

    /**
     * A package table recorded as created by more than one migration -- the
     * 1.4.2 incident, where two environments published the same migration
     * under two timestamps and 1.4.3's guard recorded the second copy as run
     * over the first's table.
     *
     * Harmless until somebody rolls back. The later copy's down() drops the
     * table the first one created, live data and all, and a rollback of "the
     * last deploy" is exactly what reaches it. Reported whatever the feature
     * flags say: a switched-off feature's table can still hold what it
     * recorded while it was on.
     *
     * @param  array<string, int>  $recorded  the output of recordedMigrations()
     * @return list<string>
     */
    private function duplicateMigrations(array $recorded): array
    {
        $warnings = [];

        foreach (self::TABLES as $key => $table) {
            // A host's own migration of the same name is never counted: see
            // recordedFor().
            $names = $this->recordedFor($key, $recorded);

            if (count($names) < 2) {
                continue;
            }

            $unchecked = array_keys(array_filter($names, static fn (bool $checked): bool => ! $checked));

            $warnings[] = sprintf(
                'The migrations table records the package\'s [%s] table as created more than once: %s. Rolling back '
                .'the later batch would drop the live table. Delete the LATER file and its row in the migrations '
                .'table -- keep the first -- and never roll back a batch that still contains it.%s',
                $this->tableName($key, $table['default']),
                implode(', ', array_map(
                    fn (string $name): string => "{$name} (batch {$recorded[$name]})",
                    array_keys($names),
                )),
                $unchecked === [] ? '' : sprintf(
                    ' %s %s no file in database/migrations, so whether %s the migration published from this '
                    .'package cannot be checked from here: never delete a migration of your own, or its row.',
                    implode(' and ', $unchecked),
                    count($unchecked) === 1 ? 'has' : 'have',
                    count($unchecked) === 1 ? 'it is' : 'each is',
                ),
            );
        }

        return $warnings;
    }

    /**
     * The migrations table as `migrate` reads it: migration name => batch, in
     * the order they ran. Null when there is nothing to read -- migrate has
     * never run, the binding was replaced, or the database cannot be reached,
     * which the table checks already report.
     *
     * Resolved by the container name Laravel 11, 12 and 13 all bind it under,
     * on the connection migrate itself uses by default.
     *
     * @return array<string, int>|null
     */
    private function recordedMigrations(): ?array
    {
        try {
            // A host that rebinds it to something without these methods
            // lands in the catch, like a database that cannot be reached.
            $repository = app('migration.repository');

            if (! $repository->repositoryExists()) {
                return null;
            }

            $recorded = [];

            foreach ($repository->getMigrationBatches() as $name => $batch) {
                $recorded[(string) $name] = (int) $batch;
            }

            return $recorded;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The recorded copies of the migration that creates a table, first run
     * first, each valued with whether its file was found and checked.
     *
     * Matched by the stub's name, which a published file keeps after its
     * timestamp whatever the table is configured to be called. That is also
     * the name Laravel gives a host's OWN migration for a table of that name
     * -- make:migration create_xero_connections_table -- so a name counts as
     * the package's only when its file agrees: see isPackageMigration(). A
     * file that is there and is not the package's is the host's, and is left
     * out; told to delete its row, someone would make the next migrate create
     * that table over itself and halt the deployment. A file that is not
     * there -- deleted after a schema dump, or kept on another path -- cannot
     * be checked: it stays, valued false, and caveat() words the advice about
     * it as the condition it is.
     *
     * @param  array<string, int>  $recorded
     * @return array<string, bool> migration name => its file was found and is the package's
     */
    private function recordedFor(string $configKey, array $recorded): array
    {
        $migration = self::TABLES[$configKey]['migration'];
        $names = [];

        foreach (array_keys($recorded) as $name) {
            $name = (string) $name;

            if ($name !== $migration && ! str_ends_with($name, '_'.$migration)) {
                continue;
            }

            $ours = self::isPackageMigration(database_path('migrations/'.$name.'.php'), $migration);

            if ($ours !== false) {
                $names[$name] = $ours === true;
            }
        }

        return $names;
    }

    /**
     * Whether a migration file is a copy of the package's stub of that name:
     * true when it reads the config key that names the stub's table, false
     * when it is there and does not, null when there is no file to read.
     *
     * Every copy ever published from each stub reads that key -- the
     * connections stub since 1.0.0, the others since they shipped in 1.4.0 --
     * and a host's own migration has no reason to. Public, so
     * xero-bridge:install judges the files it publishes over the same way.
     *
     * @param  string  $migration  a key of MIGRATIONS
     */
    public static function isPackageMigration(string $path, string $migration): ?bool
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        return str_contains($contents, self::MIGRATIONS[$migration]);
    }

    /**
     * Migrations of the host's own that carry the name of one the package
     * publishes: the package migration's name => the host's file name.
     *
     * vendor:publish maps each stub onto the FIRST file in database/migrations
     * whose name ends in the stub's name -- spatie's generateMigrationName()
     * -- and skips it as already published. When that file is not a copy of
     * the stub, the package's migration is never published, and --existing
     * or --force overwrites the host's file with it.
     *
     * @return array<string, string>
     */
    public static function shadowedMigrations(): array
    {
        $shadowed = [];

        foreach (array_keys(self::MIGRATIONS) as $migration) {
            // glob() returns them sorted, the order vendor:publish searches
            // them in, so the first match is the file it maps the stub onto.
            $first = (glob(database_path('migrations/*'.$migration.'.php')) ?: [])[0] ?? null;

            if ($first !== null && self::isPackageMigration($first, $migration) === false) {
                $shadowed[$migration] = basename($first);
            }
        }

        return $shadowed;
    }

    private function lockStoreName(): string
    {
        return (string) ($this->config->lockStore() ?? config('cache.default'));
    }

    /**
     * Whether XERO_LOCK_STORE names a store, rather than leaving the default
     * in place. A name, specifically: env() reads XERO_LOCK_STORE=false as
     * boolean false, and a bare XERO_LOCK_STORE= as '', and neither names
     * one -- XeroConfig::lockStore() turns both into the default store, on
     * every Laravel release, exactly as a token refresh resolves it.
     */
    private function lockStoreChosen(): bool
    {
        return $this->config->lockStore() !== null;
    }

    /**
     * How far the lock store's locks reach, judged by the CLASS of the store
     * behind it -- a store named "local" on the file driver locks no further
     * than one named "file" -- whether XERO_LOCK_STORE chose it or it is the
     * default:
     *
     *   'one process'  an array store
     *   'one server'   a file store, which locks through flock() on that
     *                  server's disk
     *   'none'         a store that supports no locks, or the null store,
     *                  which grants every lock at once and so excludes nothing
     *   'shared'       any other store that locks -- redis, memcached,
     *                  database, dynamodb -- which nothing here warns about
     *
     * Null when the store cannot be resolved at all. preflight() reports that
     * one, as a failure, so it is not said twice.
     *
     * The one judgement warnings(), notes() and the console's pill are all
     * made from, so none of them can call a store something the others do
     * not.
     */
    private function lockScope(): ?string
    {
        try {
            $driver = $this->cache->store($this->config->lockStore())->getStore();
        } catch (Throwable) {
            return null;
        }

        return match (true) {
            $driver instanceof ArrayStore => 'one process',
            $driver instanceof FileStore => 'one server',
            $driver instanceof NullStore, ! $driver instanceof LockProvider => 'none',
            default => 'shared',
        };
    }

    /**
     * Mirrors TokenManager::lockFor() exactly, so this proves the same thing a
     * real refresh would hit.
     *
     * A store that cannot lock is a warning, not a failure: the bridge logs
     * and carries on unlocked, which is fine in one process and loses rotated
     * refresh tokens across several. The null store counts as one. It does
     * hand out locks, but every one is granted at once and excludes nothing,
     * so taking one here would "prove" a lock that does not exist.
     */
    private function probeLock(): ?string
    {
        $name = $this->lockStoreName();

        try {
            $repository = $this->cache->store($this->config->lockStore());
        } catch (Throwable $e) {
            // "Cache store [x] is not defined" alone does not say which
            // setting named x. The default store failing is left as it is:
            // XERO_LOCK_STORE did not choose that one.
            if (! $this->lockStoreChosen()) {
                throw $e;
            }

            throw new XeroConfigurationException(
                "XERO_LOCK_STORE names the cache store [{$name}], which cannot be used: ".$e->getMessage(),
                0,
                $e,
            );
        }

        if (! $repository instanceof CacheRepository) {
            return "The [{$name}] cache store is not a standard cache repository, so its locking "
                .'behaviour cannot be verified from here.';
        }

        $driver = $repository->getStore();

        if (! $driver instanceof LockProvider) {
            return "The [{$name}] cache store supports no locks, so token refreshes run "
                .'unsynchronised -- fine in a single process; across several, two refreshes will '
                .'invalidate each other -- and webhook retries cannot lock in it either. Set '
                .'XERO_LOCK_STORE to a redis, memcached or database store.';
        }

        // Webhook retries are said not to lock IN it, rather than to be queued
        // twice: named by XERO_LOCK_STORE, the job's uniqueness lock goes to
        // the default store instead, as it does from a store with no locks.
        if ($driver instanceof NullStore) {
            return "The [{$name}] cache store uses the null driver, which grants every lock at once and "
                .'holds none: token refreshes run unsynchronised, and webhook retries cannot lock in it '
                .'either. Set XERO_LOCK_STORE to a redis, memcached or database store.';
        }

        $lock = $driver->lock('xero-bridge:preflight-probe', 2);

        if ($lock->get()) {
            $lock->release();
        }

        return null;
    }

    /**
     * Runs one check. The callable returns null when all is well, or a string
     * to report as a warning; anything thrown is a failure.
     *
     * @param  callable(): (string|null)  $check
     * @return array{label: string, ok: bool, severity?: string, type?: string, message?: string}
     */
    private function probe(string $label, callable $check): array
    {
        try {
            $warning = $check();

            if ($warning !== null) {
                return [
                    'label' => $label,
                    'ok' => false,
                    'severity' => 'warn',
                    'message' => $warning,
                ];
            }

            return ['label' => $label, 'ok' => true];
        } catch (Throwable $e) {
            return [
                'label' => $label,
                'ok' => false,
                'severity' => 'fail',
                'type' => class_basename($e),
                'message' => $e->getMessage(),
            ];
        }
    }
}
