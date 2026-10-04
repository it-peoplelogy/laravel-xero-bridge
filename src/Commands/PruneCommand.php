<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Peoplelogy\XeroBridge\Models\XeroApiCall;
use Peoplelogy\XeroBridge\Models\XeroWebhookEvent;
use Peoplelogy\XeroBridge\Models\XeroWriteRecord;
use Peoplelogy\XeroBridge\MyInvois\Models\MyInvoisValidation;
use Peoplelogy\XeroBridge\Support\Diagnostics;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Trims the package's own tables.
 *
 * One entry point for all four, so a host schedules a single command rather
 * than remembering which features it switched on.
 *
 * Exit codes are distinct so this can be wired to monitoring:
 *   0  pruned, nothing outstanding
 *   1  at least one write claim is stuck and needs a human
 */
class PruneCommand extends Command
{
    protected $signature = 'xero-bridge:prune
                            {--dry-run : Report what would be deleted, and delete nothing}';

    protected $description = 'Prune the package\'s webhook, write-ledger, capture and MyInvois tables';

    public const EXIT_STUCK_CLAIMS = 1;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->components->info('Dry run: nothing will be deleted.');
        }

        $this->prune(
            'webhook replay records',
            config('xero-bridge.database.connection'),
            (string) config('xero-bridge.webhooks.dedupe.table', 'xero_webhook_events'),
            'xero-bridge-migrations',
            new XeroWebhookEvent,
            $dryRun,
            ['dedupe_key', 'delivery_count', 'first_seen_at'],
            'XERO_WEBHOOK_DEDUPE_TABLE',
        );

        $ledger = $this->prune(
            'write ledger entries',
            config('xero-bridge.database.connection'),
            (string) config('xero-bridge.writes.table', 'xero_write_records'),
            'xero-bridge-migrations',
            new XeroWriteRecord,
            $dryRun,
            ['claim_key', 'connection_key', 'claimed_at'],
            'XERO_WRITES_TABLE',
        );

        $this->prune(
            'captured API calls',
            config('xero-bridge.database.connection'),
            (string) config('xero-bridge.capture.table', 'xero_api_calls'),
            'xero-bridge-migrations',
            new XeroApiCall,
            $dryRun,
            ['logical_call_id', 'channel', 'created_at'],
            'XERO_CAPTURE_TABLE',
        );

        $this->prune(
            'MyInvois verdicts',
            config('myinvois.database.connection'),
            (string) config('myinvois.audit.table', 'myinvois_validations'),
            'myinvois-migrations',
            new MyInvoisValidation,
            $dryRun,
            ['subject_hash', 'tin_last4', 'last_checked_at'],
            'MYINVOIS_AUDIT_TABLE',
        );

        return $ledger ? $this->reportStuckClaims() : self::SUCCESS;
    }

    /**
     * @param  string  $publishTag  the tag whose migration creates this table
     * @param  list<string>  $ownColumns  columns only the package's own table has,
     *                                    including the one prunable() filters on
     * @param  string  $envKey  the setting that names the table
     * @return bool whether the table is there to prune
     */
    private function prune(
        string $label,
        ?string $connection,
        string $table,
        string $publishTag,
        object $model,
        bool $dryRun,
        array $ownColumns,
        string $envKey,
    ): bool {
        // Absent tables are skipped in silence: a host that never switched a
        // feature on should not be told off by a scheduled command. Not on
        // screen, and not in the log either -- which is why this asks the
        // schema itself rather than TableGuard, which logs every absent table
        // it meets and would write one line per table on every run. Only -v
        // names them, with the tag that creates each.
        try {
            $present = Schema::connection($connection)->hasTable($table);
        } catch (Throwable $e) {
            // Not a feature left off, so not silent.
            $this->components->error("Could not check for the {$label} table [{$table}]: ".$e->getMessage());

            return false;
        }

        if (! $present) {
            $this->components->twoColumnDetail(
                $label,
                "not migrated (--tag={$publishTag})",
                OutputInterface::VERBOSITY_VERBOSE,
            );

            return false;
        }

        // A table by this name that is not the package's -- a host's own
        // xero_api_calls, say -- must never be pruned: prunable() filters on
        // nothing but age, so it would delete the host's old rows every night.
        // Up to 1.4.x it did, whether or not the feature was even on.
        try {
            $ours = Schema::connection($connection)->hasColumns($table, $ownColumns);
        } catch (Throwable $e) {
            $this->components->error("Could not check the {$label} table [{$table}]: ".$e->getMessage());

            return false;
        }

        if (! $ours) {
            $this->components->error(
                "The table [{$table}] is not the package's {$label} table (it has no ".implode(', ', $ownColumns)
                ." columns), so nothing in it was pruned. If it is yours, set {$envKey} to an unused name."
            );

            return false;
        }

        try {
            /** @var Builder $prunable */
            $prunable = $model->prunable();

            $count = (clone $prunable)->count();

            if ($count === 0) {
                $this->components->twoColumnDetail($label, 'nothing to prune');

                return true;
            }

            if ($dryRun) {
                $this->components->twoColumnDetail($label, "would delete {$count}");

                return true;
            }

            // Chunked, because a first prune over a year of rows in one
            // statement is how a scheduled task locks a production table.
            $deleted = 0;

            do {
                $batch = (clone $prunable)->limit(1000)->delete();
                $deleted += $batch;
            } while ($batch > 0);

            $this->components->twoColumnDetail($label, "deleted {$deleted}");
        } catch (Throwable $e) {
            $this->components->error("Could not prune {$label}: ".$e->getMessage());
        }

        return true;
    }

    /**
     * A pending write claim is never pruned, so it accumulates until somebody
     * looks. That is deliberate -- it means "we sent something to Xero and
     * never learned the outcome", which is exactly the thing worth a human.
     *
     * Asked only once prune() has found the ledger table, so a host without
     * one is not told anything twice -- or at all.
     */
    private function reportStuckClaims(): int
    {
        try {
            $stuck = XeroWriteRecord::query()->stuck()->count();
        } catch (Throwable) {
            return self::SUCCESS;
        }

        if ($stuck === 0) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->warn(Diagnostics::stuckClaims($stuck));

        return self::EXIT_STUCK_CLAIMS;
    }
}
