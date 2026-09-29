<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Support;

use Illuminate\Support\Facades\Schema;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * "Is that table actually there?", answered once per process.
 *
 * This is the whole reason a consumer can upgrade, run `composer update`, and
 * have nothing break. The persistence tables ship as migration stubs that are
 * published and run deliberately -- so until somebody does that, the recorders
 * must be a silent no-op rather than a torrent of "table not found".
 *
 * Three properties matter, and each is the answer to a way this could go wrong:
 *
 *   MEMOISED PER PROCESS. A `hasTable()` is a real query against the
 *   information schema. Doing it on every write would add a round trip to the
 *   hot path forever, to answer a question whose answer changes about once in
 *   the life of an application.
 *
 *   WARNS ONCE, NOT PER CALL. A queue worker handling ten thousand webhooks
 *   would otherwise write ten thousand identical lines. A log people silence is
 *   a log that silences everything else with it.
 *
 *   NEVER THROWS. If the database is unreachable the answer is "no" and the
 *   caller carries on. Recording is a side benefit; it must not be able to take
 *   down the Xero call it was recording.
 */
final class TableGuard
{
    /** @var array<string, bool> */
    private array $present = [];

    /** @var array<string, true> */
    private array $warned = [];

    public function __construct(private readonly LoggerInterface $logger) {}

    /**
     * @param  string|null  $connection  null => the application's default
     * @param  string  $publishTag  named in the warning, so the fix is in the message
     */
    public function has(?string $connection, string $table, string $publishTag): bool
    {
        $cacheKey = ($connection ?? '@default').'|'.$table;

        if (isset($this->present[$cacheKey])) {
            return $this->present[$cacheKey];
        }

        try {
            // Through the Schema facade, exactly as the migration stubs do,
            // so the host connection's prefix is applied identically in both.
            $exists = Schema::connection($connection)->hasTable($table);
        } catch (Throwable $e) {
            // An unreachable database is not this class's problem to solve, and
            // it is certainly not a reason to fail the API call in progress.
            $this->warnOnce(
                $cacheKey,
                'xero-bridge: could not check for the table [{table}], so recording is off for this process.',
                ['table' => $table, 'exception' => $e->getMessage()],
            );

            return $this->present[$cacheKey] = false;
        }

        if (! $exists) {
            $this->warnOnce(
                $cacheKey,
                'xero-bridge: the table [{table}] does not exist, so nothing is being recorded. '
                .'Run `php artisan vendor:publish --tag={tag}` and `php artisan migrate` to switch it on, '
                .'or set the matching config key to false to stop this message.',
                ['table' => $table, 'tag' => $publishTag],
            );
        }

        return $this->present[$cacheKey] = $exists;
    }

    /**
     * Forget everything learned so far.
     *
     * For tests, and for the rare long-running process that migrates beneath
     * itself. Not called anywhere in normal operation.
     */
    public function flush(): void
    {
        $this->present = [];
        $this->warned = [];
    }

    /** @param array<string, mixed> $context */
    private function warnOnce(string $cacheKey, string $message, array $context): void
    {
        if (isset($this->warned[$cacheKey])) {
            return;
        }

        $this->warned[$cacheKey] = true;

        $this->logger->warning(strtr($message, [
            '{table}' => (string) ($context['table'] ?? ''),
            '{tag}' => (string) ($context['tag'] ?? ''),
        ]), $context);
    }
}
