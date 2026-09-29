<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Peoplelogy\XeroBridge\Models\XeroApiCall;
use Peoplelogy\XeroBridge\Models\XeroWebhookEvent;
use Peoplelogy\XeroBridge\Models\XeroWriteRecord;
use Peoplelogy\XeroBridge\MyInvois\Models\MyInvoisValidation;
use Peoplelogy\XeroBridge\Support\TableGuard;
use Throwable;

/**
 * Trims the package's own tables.
 *
 * One entry point for all three, so a host schedules a single command rather
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

    public function handle(TableGuard $tables): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->components->info('Dry run: nothing will be deleted.');
        }

        $this->prune(
            $tables,
            'webhook replay records',
            config('xero-bridge.database.connection'),
            (string) config('xero-bridge.webhooks.dedupe.table', 'xero_webhook_events'),
            new XeroWebhookEvent,
            $dryRun,
        );

        $this->prune(
            $tables,
            'write ledger entries',
            config('xero-bridge.database.connection'),
            (string) config('xero-bridge.writes.table', 'xero_write_records'),
            new XeroWriteRecord,
            $dryRun,
        );

        $this->prune(
            $tables,
            'captured API calls',
            config('xero-bridge.database.connection'),
            (string) config('xero-bridge.capture.table', 'xero_api_calls'),
            new XeroApiCall,
            $dryRun,
        );

        $this->prune(
            $tables,
            'MyInvois verdicts',
            config('myinvois.database.connection'),
            (string) config('myinvois.audit.table', 'myinvois_validations'),
            new MyInvoisValidation,
            $dryRun,
        );

        return $this->reportStuckClaims($tables);
    }

    private function prune(
        TableGuard $tables,
        string $label,
        ?string $connection,
        string $table,
        object $model,
        bool $dryRun,
    ): void {
        // Absent tables are skipped in silence: a host that never switched a
        // feature on should not be told off by a scheduled command.
        if (! $tables->has($connection, $table, 'xero-bridge-migrations')) {
            return;
        }

        try {
            /** @var Builder $prunable */
            $prunable = $model->prunable();

            $count = (clone $prunable)->count();

            if ($count === 0) {
                $this->components->twoColumnDetail($label, 'nothing to prune');

                return;
            }

            if ($dryRun) {
                $this->components->twoColumnDetail($label, "would delete {$count}");

                return;
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
    }

    /**
     * A pending write claim is never pruned, so it accumulates until somebody
     * looks. That is deliberate -- it means "we sent something to Xero and
     * never learned the outcome", which is exactly the thing worth a human.
     */
    private function reportStuckClaims(TableGuard $tables): int
    {
        $table = (string) config('xero-bridge.writes.table', 'xero_write_records');

        if (! $tables->has(config('xero-bridge.database.connection'), $table, 'xero-bridge-migrations')) {
            return self::SUCCESS;
        }

        try {
            $stuck = XeroWriteRecord::query()->stuck()->count();
        } catch (Throwable) {
            return self::SUCCESS;
        }

        if ($stuck === 0) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->warn(sprintf(
            '%d write claim(s) have been pending for over an hour. Each means something was sent to '
            .'Xero and the outcome was never recorded, so further writes for those records are '
            .'BLOCKED. Check Xero, then resolve the rows by hand -- nothing will re-send on its own, '
            .'because re-sending could duplicate a record that already exists.',
            $stuck,
        ));

        return self::EXIT_STUCK_CLAIMS;
    }
}
