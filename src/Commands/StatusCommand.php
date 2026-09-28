<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Commands;

use Illuminate\Console\Command;
use Peoplelogy\XeroBridge\Models\XeroConnection;
use Peoplelogy\XeroBridge\OAuth\TokenManager;
use Peoplelogy\XeroBridge\Support\Scopes;
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

    public function handle(TokenManager $tokens, XeroConfig $config): int
    {
        $missing = $this->missingConfig($config);
        $connections = $tokens->all();

        $rows = $connections->map(fn (XeroConnection $c) => $this->describe($c, $config))->all();

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

        if ($this->option('strict') && $this->warnings($config, $rows) !== []) {
            return self::EXIT_CONFIG_INCOMPLETE;
        }

        return self::SUCCESS;
    }

    /** @return array<string, string> */
    private function missingConfig(XeroConfig $config): array
    {
        $missing = [];

        if (($config->get('client_id') ?? '') === '') {
            $missing['client_id'] = 'XERO_CLIENT_ID';
        }

        if (($config->get('client_secret') ?? '') === '') {
            $missing['client_secret'] = 'XERO_CLIENT_SECRET';
        }

        if (! Scopes::has($config->get('scopes'), Scopes::OFFLINE_ACCESS)) {
            $missing['scopes'] = 'XERO_SCOPES (must include offline_access)';
        }

        return $missing;
    }

    /** @return array<string, mixed> */
    private function describe(XeroConnection $connection, XeroConfig $config): array
    {
        return [
            'key' => (string) $connection->key,
            'organisation' => $connection->displayName(),
            'tenant_id' => (string) $connection->tenant_id,
            'expires_at' => $connection->expires_at?->toIso8601String(),
            'expires_in' => $connection->expires_at
                ? (int) now()->diffInSeconds($connection->expires_at, false)
                : null,
            'expired' => $connection->isExpired(),
            'needs_reauthorisation' => $connection->isInvalidated(),
            'invalidated_reason' => $connection->invalidated_reason,
            'failure_count' => $connection->failure_count,
            'last_refreshed_at' => $connection->last_refreshed_at?->toIso8601String(),
            'scopes' => $connection->scopeList(),
            'connect_url' => $config->connectUrl((string) $connection->key),
        ];
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

        foreach ($this->warnings($config, $rows) as $warning) {
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

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function warnings(XeroConfig $config, array $rows): array
    {
        $warnings = [];

        if ($config->webhookKey() === null && $config->get('webhooks.enabled', true)) {
            $warnings[] = 'No XERO_WEBHOOK_KEY is set, so every webhook will be rejected with a 401.';
        }

        if (($config->get('tokens.lock_store') ?? '') === ''
            && in_array((string) config('cache.default'), ['array', 'file'], true)
        ) {
            $warnings[] = 'The cache store does not lock across processes, so two concurrent token '
                .'refreshes would invalidate each other. Set XERO_LOCK_STORE.';
        }

        foreach ($rows as $row) {
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
}
