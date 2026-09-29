<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Commands;

use Illuminate\Console\Command;
use Peoplelogy\XeroBridge\Support\Diagnostics;
use Peoplelogy\XeroBridge\Support\XeroConfig;

/**
 * Reports connection health without touching the Xero API.
 *
 * Exit codes are distinct so this can be wired to monitoring:
 *   0  everything usable
 *   1  configuration is incomplete
 *   2  at least one connection needs re-authorising
 */
class StatusCommand extends Command
{
    protected $signature = 'xero-bridge:status
                            {--json : Output machine-readable JSON}
                            {--strict : Treat warnings as failures}';

    protected $description = 'Show Xero connections, token expiry and any missing configuration';

    public const EXIT_CONFIG_INCOMPLETE = 1;

    public const EXIT_NEEDS_REAUTH = 2;

    /** Set once in handle(), so render() does not have to be threaded with it. */
    private Diagnostics $diagnostics;

    public function handle(Diagnostics $diagnostics, XeroConfig $config): int
    {
        $this->diagnostics = $diagnostics;

        $missing = $diagnostics->missingConfig();
        $rows = $diagnostics->connections();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'missing_config' => $missing,
                'connections' => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->render($missing, $rows, $config);
        }

        if ($missing !== []) {
            return self::EXIT_CONFIG_INCOMPLETE;
        }

        foreach ($rows as $row) {
            if ($row['needs_reauthorisation']) {
                return self::EXIT_NEEDS_REAUTH;
            }
        }

        if ($this->option('strict') && $this->diagnostics->warnings($rows) !== []) {
            return self::EXIT_CONFIG_INCOMPLETE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $missing
     * @param  list<array<string, mixed>>  $rows
     */
    private function render(array $missing, array $rows, XeroConfig $config): void
    {
        if ($missing !== []) {
            $this->components->error('Configuration is incomplete:');

            foreach ($missing as $key => $envKey) {
                $this->line("  <fg=red>missing</> {$key} ({$envKey})");
            }

            $this->newLine();
        }

        if ($rows === []) {
            $this->components->warn('No Xero organisations are connected.');
            $this->line('  Connect one at '.$config->connectUrl($config->defaultConnection()));

            return;
        }

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

        foreach ($this->diagnostics->warnings($rows) as $warning) {
            $this->components->warn($warning);
        }
    }

    /** @param array<string, mixed> $row */
    private function tokenState(array $row): string
    {
        if ($row['needs_reauthorisation']) {
            return '<fg=red>reconnect</>';
        }

        if ($row['expired']) {
            // Not a problem in itself: the next call refreshes it.
            return '<fg=yellow>expired</>';
        }

        return '<fg=green>valid</>';
    }
}
