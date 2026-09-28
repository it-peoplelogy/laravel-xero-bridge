<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Commands;

use Illuminate\Console\Command;
use Peoplelogy\XeroBridge\Exceptions\XeroIdentityUnavailableException;
use Peoplelogy\XeroBridge\Exceptions\XeroReauthorizationRequiredException;
use Peoplelogy\XeroBridge\Models\XeroConnection;
use Peoplelogy\XeroBridge\OAuth\TokenManager;
use Throwable;

/**
 * Keeps every connection alive. Meant to be scheduled:
 *
 *     Schedule::command('xero-bridge:refresh-tokens')
 *         ->hourly()->withoutOverlapping()->onOneServer();
 *
 * withoutOverlapping() and onOneServer() are not optional. Xero rotates
 * refresh tokens, so two runs at once invalidate each other's token. The
 * per-connection cache lock inside TokenManager is the second line of
 * defence, not the first.
 *
 * Exit codes:
 *   0  all connections refreshed or already fresh
 *   1  at least one transient failure (retry later; nothing was changed)
 *   2  at least one connection needs a human to re-authorise
 */
class RefreshTokensCommand extends Command
{
    protected $signature = 'xero-bridge:refresh-tokens
                            {--connection= : Only this connection}
                            {--window=1800 : Refresh tokens expiring within this many seconds}
                            {--force : Refresh even when the token is still fresh}';

    protected $description = 'Refresh the Xero access token for every connection';

    public const EXIT_TRANSIENT_FAILURE = 1;

    public const EXIT_NEEDS_REAUTH = 2;

    public function handle(TokenManager $tokens): int
    {
        $connections = $tokens->all();

        if (($only = $this->option('connection')) !== null) {
            $connections = $connections->where('key', $only)->values();

            if ($connections->isEmpty()) {
                $this->components->error("No Xero connection is stored under [{$only}].");

                return self::FAILURE;
            }
        }

        if ($connections->isEmpty()) {
            $this->components->info('No Xero connections to refresh.');

            return self::SUCCESS;
        }

        $window = (int) $this->option('window');
        $exit = self::SUCCESS;

        foreach ($connections as $connection) {
            $exit = max($exit, $this->refreshOne($tokens, $connection, $window));
        }

        return $exit;
    }

    private function refreshOne(TokenManager $tokens, XeroConnection $connection, int $window): int
    {
        $key = (string) $connection->key;

        if ($connection->isInvalidated()) {
            $this->components->error(sprintf(
                '[%s] needs re-authorisation (%s). Go to %s',
                $key,
                $connection->invalidated_reason ?? 'unknown reason',
                $tokens->connectUrl($key),
            ));

            return self::EXIT_NEEDS_REAUTH;
        }

        if (! $this->option('force') && ! $connection->expiresWithin($window)) {
            $this->components->twoColumnDetail($key, '<fg=gray>still fresh</>');

            return self::SUCCESS;
        }

        try {
            // The window is passed through, so the re-check inside the lock
            // applies the SAME rule this command just applied -- otherwise
            // TokenManager's much shorter default leeway would decide there
            // was nothing to do and the scheduled job would never refresh.
            $refreshed = $tokens->refresh($connection, $window, (bool) $this->option('force'));

            $this->components->twoColumnDetail(
                $key.' <fg=gray>('.$refreshed->displayName().')</>',
                '<fg=green>refreshed until '.$refreshed->expires_at?->toTimeString().'</>',
            );

            return self::SUCCESS;
        } catch (XeroReauthorizationRequiredException $e) {
            // Terminal. The connection is marked, never deleted.
            $this->components->error("[{$key}] ".$e->getMessage());

            return self::EXIT_NEEDS_REAUTH;
        } catch (XeroIdentityUnavailableException $e) {
            // Transient. The stored tokens are untouched -- this is the case
            // that must never destroy a connection.
            $this->components->warn("[{$key}] ".$e->getMessage());

            return self::EXIT_TRANSIENT_FAILURE;
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'Timed out waiting')) {
                // Another process holds the lock, which means it is already
                // being refreshed. Not a failure.
                $this->components->twoColumnDetail($key, '<fg=gray>skipped (locked)</>');

                return self::SUCCESS;
            }

            $this->components->error("[{$key}] ".$e->getMessage());

            return self::EXIT_TRANSIENT_FAILURE;
        }
    }
}
