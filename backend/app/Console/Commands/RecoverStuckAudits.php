<?php

namespace App\Console\Commands;

use App\Enums\AuditStatus;
use App\Models\Audit;
use App\Services\Audit\AuditLifecycle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fails audits that stopped making progress, releasing their concurrent
 * slots.
 *
 * Every *known* failure path now marks the audit failed itself (each job
 * has a `failed()` handler). This exists for the ones that cannot: a
 * worker killed by the OOM killer or a deploy mid-job never runs
 * `failed()` at all, so without a sweeper those audits stay active
 * forever and permanently consume one of their owner's slots — the user
 * can never start another audit, and nothing in the app explains why.
 *
 * Deliberately conservative. `progress_at` is bumped by every job that
 * does real work, so "no progress for N minutes" means genuinely nothing
 * is happening, not merely "this crawl is slow".
 */
class RecoverStuckAudits extends Command
{
    protected $signature = 'audits:recover-stuck
                            {--minutes= : Override the configured stuck threshold}
                            {--dry-run : Report what would be failed without changing anything}';

    protected $description = 'Fail audits that have stopped making progress, releasing their concurrent slots';

    public function handle(AuditLifecycle $lifecycle): int
    {
        $minutes = (int) ($this->option('minutes') ?? config('audit_limits.stuck_after_minutes'));

        if ($minutes <= 0) {
            $this->error('The stuck threshold must be a positive number of minutes.');

            return self::FAILURE;
        }

        $cutoff = now()->subMinutes($minutes);

        $stuck = Audit::query()
            ->whereIn('status', AuditStatus::activeValues())
            // COALESCE order matters: `progress_at` is the real signal,
            // but audits created before this column existed have none, so
            // fall back to when the row was last written at all. Using
            // `updated_at` alone would be wrong the other way — an
            // unrelated write to the row would look like progress.
            ->where(function ($query) use ($cutoff) {
                $query->where('progress_at', '<', $cutoff)
                    ->orWhere(fn ($q) => $q->whereNull('progress_at')->where('updated_at', '<', $cutoff));
            })
            ->get();

        if ($stuck->isEmpty()) {
            $this->info('No stuck audits found.');

            return self::SUCCESS;
        }

        foreach ($stuck as $audit) {
            $this->line(sprintf(
                'Audit #%d (%s) has made no progress since %s',
                $audit->id,
                $audit->status->value,
                ($audit->progress_at ?? $audit->updated_at)?->diffForHumans() ?? 'unknown',
            ));

            if ($this->option('dry-run')) {
                continue;
            }

            $lifecycle->markFailed(
                $audit,
                "Audit stopped responding and was recovered after {$minutes} minutes without progress.",
                $audit->status->value,
            );
        }

        $verb = $this->option('dry-run') ? 'Would recover' : 'Recovered';
        $this->info("{$verb} {$stuck->count()} stuck audit(s).");

        if (! $this->option('dry-run')) {
            Log::channel('crawler')->warning('Recovered stuck audits', [
                'count' => $stuck->count(),
                'audit_ids' => $stuck->pluck('id')->all(),
                'threshold_minutes' => $minutes,
            ]);
        }

        return self::SUCCESS;
    }
}
