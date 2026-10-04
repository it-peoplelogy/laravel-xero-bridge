<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Commands;

use Illuminate\Console\Command;
use Peoplelogy\XeroBridge\Http\Middleware\EnsureConsoleEnabled;
use Peoplelogy\XeroBridge\Support\Diagnostics;
use Peoplelogy\XeroBridge\Support\XeroConfig;

/**
 * Reports connection health without touching the Xero API.
 *
 * Exit codes are distinct so this can be wired to monitoring:
 *   0  everything usable
 *   1  configuration is incomplete; the connections table is missing,
 *      unreadable or not the package's own; or a connection's stored tokens
 *      cannot be decrypted with this APP_KEY. Under --strict, also any
 *      warning or a lock pre-flight that did not pass
 *   2  at least one connection needs re-authorising
 *
 * Checked in that order, so a run that is both misconfigured and holding a
 * dead connection returns 1, not 2.
 */
class StatusCommand extends Command
{
    protected $signature = 'xero-bridge:status
                            {--json : Output machine-readable JSON}
                            {--strict : Treat warnings and a failed lock pre-flight as failures}';

    protected $description = 'Show Xero connections, token expiry and any missing configuration';

    public const EXIT_CONFIG_INCOMPLETE = 1;

    public const EXIT_NEEDS_REAUTH = 2;

    public function handle(Diagnostics $diagnostics, XeroConfig $config): int
    {
        // Each asked once, so the JSON, the report and the exit code all
        // describe the same moment.
        $missing = $diagnostics->missingConfig();

        // The one table problem that stops the report. connections() would
        // throw on a missing or foreign table -- which is how this command
        // used to die on an uncaught QueryException -- so it is not asked
        // then; and what it throws otherwise becomes that problem.
        [$rows, $problems] = $diagnostics->readConnections($diagnostics->tableProblems());
        $tableProblem = $problems[Diagnostics::CONNECTIONS_TABLE] ?? null;

        $ledger = $diagnostics->writeLedger();
        $warnings = $diagnostics->warnings($rows, $ledger, $problems);
        $notes = $diagnostics->notes();
        $preflight = $diagnostics->preflight();
        $console = $this->console($diagnostics);

        if ($this->option('json')) {
            // missing_config and connections first, exactly as before; every
            // newer key is appended after them, so a parser written against
            // an older release still finds what it reads.
            $this->line((string) json_encode([
                'missing_config' => $missing,
                'connections' => $rows,
                'warnings' => $warnings,
                'notes' => $notes,
                'preflight' => $preflight,
                'table_problems' => $problems,
                'write_ledger' => $ledger,
                'console' => $console,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        } else {
            $this->render($missing, $tableProblem, $rows, $warnings, $notes, $preflight, $console, $config);
        }

        // A connection this APP_KEY cannot decrypt fails every call with the
        // XeroConfigurationException a missing setting raises, and the cure
        // -- APP_PREVIOUS_KEYS -- is the deploy owner's, so it ranks with
        // them. It is also the code 1.4.x exited with, dying on the
        // DecryptException.
        $unreadable = array_filter($rows, fn (array $row): bool => $this->tokensUnreadable($row));

        if ($tableProblem !== null || $missing !== [] || $unreadable !== []) {
            return self::EXIT_CONFIG_INCOMPLETE;
        }

        foreach ($rows as $row) {
            if ($row['needs_reauthorisation']) {
                return self::EXIT_NEEDS_REAUTH;
            }
        }

        // The lock pre-flight counts and the consent-flow one does not. With
        // no XERO_REDIRECT_URI this process derives the callback URL from
        // APP_URL, where the browser's request derives it from the host it
        // was sent to -- so that check can fail here and pass where it
        // matters. It is printed, never counted.
        if ($this->option('strict') && ($warnings !== [] || ! ($preflight['lock']['ok'] ?? false))) {
            return self::EXIT_CONFIG_INCOMPLETE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $missing
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $warnings
     * @param  list<string>  $notes
     * @param  array<string, array<string, mixed>>  $preflight
     * @param  array{enabled: bool, reason: string, middleware: list<string>, url: string|null}  $console
     */
    private function render(
        array $missing,
        ?string $tableProblem,
        array $rows,
        array $warnings,
        array $notes,
        array $preflight,
        array $console,
        XeroConfig $config,
    ): void {
        if ($missing !== []) {
            $this->components->error('Configuration is incomplete:');

            foreach ($missing as $key => $envKey) {
                $this->line("  <fg=red>missing</> {$key} ({$envKey})");
            }

            $this->newLine();
        }

        if ($tableProblem !== null) {
            // In place of the connections it stops us reading -- "nothing is
            // connected" would be a guess.
            $this->components->error($tableProblem);
        } elseif ($rows === []) {
            $this->components->warn('No Xero organisations are connected.');
            $this->line('  Connect one at '.$config->connectUrl($config->defaultConnection()));
        } else {
            $this->table(
                ['Key', 'Organisation', 'Token', 'Expires', 'Failures'],
                array_map(fn (array $row) => [
                    $row['key'],
                    $row['organisation'],
                    $this->tokenState($row),
                    $row['expires_at'] ?? 'unknown',
                    (string) $row['failure_count'],
                ], $rows),
            );

            foreach ($rows as $row) {
                if ($row['needs_reauthorisation']) {
                    $this->components->error(sprintf(
                        'Connection [%s] must be authorised again (%s). Go to %s',
                        $row['key'],
                        $row['invalidated_reason'] ?? 'unknown reason',
                        $row['connect_url'],
                    ));
                }
            }
        }

        // Everything from here prints whatever the state of the connections:
        // it is what --strict counts, and a run that fails on it has to say
        // why.
        foreach ($preflight as $name => $check) {
            // A consent flow that cannot start on missing configuration says
            // again what the block above has just said.
            if ($check['ok'] || ($name === 'connect' && $missing !== [])) {
                continue;
            }

            if (($check['severity'] ?? null) === 'warn') {
                $this->components->warn("{$check['label']}: {$check['message']}");
            } else {
                $this->components->error(sprintf(
                    '%s: %s - %s',
                    $check['label'],
                    $check['type'] ?? 'Error',
                    $check['message'] ?? '',
                ));
            }
        }

        foreach ($warnings as $warning) {
            // Already printed above, as the error it is.
            if ($warning !== $tableProblem) {
                $this->components->warn($warning);
            }
        }

        foreach ($notes as $note) {
            $this->components->info($note);
        }

        $this->components->info($this->consoleLine($console));
    }

    /**
     * Whether the test console is reachable, and behind what. Information,
     * not a warning: an install that switched it on did so on purpose, and
     * the gate it is judged by is the one EnsureConsoleEnabled applies.
     *
     * @return array{enabled: bool, reason: string, middleware: list<string>, url: string|null}
     */
    private function console(Diagnostics $diagnostics): array
    {
        return [
            'enabled' => EnsureConsoleEnabled::enabled($this->laravel),
            'reason' => EnsureConsoleEnabled::reason($this->laravel),
            // Read the way routes/console.php reads it, so this names the
            // stack the route was actually given.
            'middleware' => array_values(array_filter(
                (array) config('xero-bridge.console.middleware', ['web', 'auth']),
                'is_string',
            )),
            'url' => $diagnostics->urls()['console'],
        ];
    }

    /**
     * The state and its reason in xero-bridge:install's own words -- "Test
     * console: ON (...)" or "Test console: off (...)" -- so the two commands
     * never describe one gate two ways. Status adds where the console is
     * served and what it sits behind.
     *
     * @param  array{enabled: bool, reason: string, middleware: list<string>, url: string|null}  $console
     */
    private function consoleLine(array $console): string
    {
        if (! $console['enabled']) {
            return "Test console: off ({$console['reason']}).";
        }

        return sprintf(
            'Test console: ON (%s)%s, %s.',
            $console['reason'],
            $console['url'] !== null ? ' at '.$console['url'] : '',
            $console['middleware'] === []
                ? 'with no middleware in front of it'
                : 'behind '.implode(', ', $console['middleware']),
        );
    }

    /** @param array<string, mixed> $row */
    private function tokenState(array $row): string
    {
        if ($row['needs_reauthorisation']) {
            return '<fg=red>reconnect</>';
        }

        if ($this->tokensUnreadable($row)) {
            // In date, perhaps, but no call can use it.
            return '<fg=red>unreadable</>';
        }

        if ($row['expired']) {
            // Not a problem in itself: the next call refreshes it.
            return '<fg=yellow>expired</>';
        }

        return '<fg=green>valid</>';
    }

    /**
     * Stored tokens this APP_KEY cannot decrypt -- an APP_KEY rotated without
     * APP_PREVIOUS_KEYS -- on a connection not already waiting to be
     * authorised again, which outranks it: reconnecting replaces the tokens.
     *
     * @param  array<string, mixed>  $row
     */
    private function tokensUnreadable(array $row): bool
    {
        // `?? true`: a row described before this key existed is readable as
        // far as anyone knows.
        return ($row['tokens_readable'] ?? true) === false && ! $row['needs_reauthorisation'];
    }
}
