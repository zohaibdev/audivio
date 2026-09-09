<?php

namespace App\Console\Commands;

use App\Enums\AuditStatus;
use App\Models\Audit;
use App\Services\Audit\AuditSnapshotBuilder;
use Illuminate\Console\Command;

/**
 * Writes snapshots for audits that completed before `audit_snapshots`
 * existed (§38).
 *
 * A command rather than a migration, deliberately. A snapshot is evidence,
 * and manufacturing evidence is an operational decision someone should
 * make on purpose — not something that happens silently while deploying a
 * schema change. It is also re-runnable and has a dry run, which a
 * migration is not.
 *
 * **What a backfilled snapshot can and cannot claim.** The scores, issues,
 * pages, screenshots and performance data are real: they were stored when
 * the audit ran and are simply being collected. The *version* stamps are
 * inferred from current config, which is only honest while no version has
 * ever been bumped — every historical audit here ran under the same 1.0.0
 * the config still names. Each backfilled row is marked as such in its
 * content so nobody later mistakes it for a contemporaneous record, and
 * so a future version bump makes the distinction obvious rather than
 * silently rewriting history.
 */
class BackfillAuditSnapshots extends Command
{
    protected $signature = 'audits:backfill-snapshots
                            {--dry-run : Report what would be written without writing anything}';

    protected $description = 'Write immutable snapshots for audits that completed before snapshots existed';

    public function handle(AuditSnapshotBuilder $snapshots): int
    {
        $missing = Audit::query()
            ->where('status', AuditStatus::Completed)
            ->whereDoesntHave('snapshot')
            ->orderBy('id')
            ->get();

        if ($missing->isEmpty()) {
            $this->info('Every completed audit already has a snapshot.');

            return self::SUCCESS;
        }

        $this->line("{$missing->count()} completed audit(s) have no snapshot.");

        if ($this->option('dry-run')) {
            foreach ($missing as $audit) {
                $this->line("  would write snapshot for audit #{$audit->id} ({$audit->website?->domain})");
            }
            $this->info("Would write {$missing->count()} snapshot(s).");

            return self::SUCCESS;
        }

        $written = 0;
        $failed = 0;

        foreach ($missing as $audit) {
            try {
                $snapshots->build($audit, backfilled: true);
                $written++;
            } catch (\Throwable $e) {
                // One unbuildable audit must not stop the rest — a missing
                // website or a malformed row is exactly the kind of thing
                // that made an old audit snapshot-less to begin with.
                $failed++;
                $this->warn("  audit #{$audit->id}: {$e->getMessage()}");
            }
        }

        $this->info("Wrote {$written} snapshot(s).".($failed > 0 ? " {$failed} could not be built." : ''));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
