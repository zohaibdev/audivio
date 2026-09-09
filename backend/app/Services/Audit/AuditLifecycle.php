<?php

namespace App\Services\Audit;

use App\Enums\AuditStatus;
use App\Enums\CrawlJobStatus;
use App\Models\Audit;
use App\Models\CrawlJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The one place an audit's status changes.
 *
 * Centralised because two rules have to hold on *every* path, and both
 * were previously the caller's job to remember:
 *
 * 1. A terminal transition must stamp `terminated_at`, or the audit keeps
 *    its concurrent slot forever in every report that reads it.
 * 2. A terminal audit must never be moved again. Without that, a late
 *    worker finishing after a cancellation would quietly resurrect the
 *    audit and overwrite a real user decision.
 */
class AuditLifecycle
{
    /**
     * How much of a failure message is worth keeping for a human. The
     * column is TEXT, so this is an editorial limit, not a structural
     * one — and it must never again be set above what the column holds.
     */
    public const MAX_ERROR_MESSAGE = 1000;

    /**
     * Move an audit forward, refusing to touch one that has already
     * finished.
     *
     * @return bool false when the audit was already terminal (the caller
     *              should stop what it is doing — see `shouldStop()`)
     */
    public function transition(Audit $audit, AuditStatus $to, array $extra = []): bool
    {
        // Re-read under a conditional update rather than trusting the
        // in-memory model: several workers touch one audit, and the copy
        // this process serialized into the job may be minutes stale.
        $affected = Audit::whereKey($audit->id)
            ->whereIn('status', AuditStatus::activeValues())
            ->update(array_merge([
                'status' => $to->value,
                'progress_at' => now(),
            ], $to->isTerminal() ? ['terminated_at' => now()] : [], $extra));

        if ($affected === 0) {
            return false;
        }

        if ($to->isTerminal()) {
            $this->closeRunningCrawlJob($audit);
        }

        $audit->refresh();

        return true;
    }

    /**
     * Close out a crawl that is still marked Running when its audit ends.
     *
     * `LimitConcurrentCrawls` caps crawling system-wide by counting
     * `crawl_jobs` rows in Running. Nothing used to close that row when an
     * audit failed, was cancelled, or was recovered as stuck — so each one
     * held a global slot forever, and after `max_concurrent_crawls` of
     * them crawling stopped for every user with nothing to explain why.
     *
     * Only Running rows are touched. A crawl that finished normally
     * already recorded when it did, and overwriting `completed_at` would
     * corrupt the crawl duration every report reads.
     */
    private function closeRunningCrawlJob(Audit $audit): void
    {
        CrawlJob::where('audit_id', $audit->id)
            ->where('status', CrawlJobStatus::Running)
            ->update([
                'status' => CrawlJobStatus::Failed,
                'completed_at' => now(),
            ]);
    }

    /**
     * Record progress without changing state, so stuck-audit recovery can
     * tell a slow crawl from an abandoned one.
     */
    public function touch(Audit $audit): void
    {
        Audit::whereKey($audit->id)
            ->whereIn('status', AuditStatus::activeValues())
            ->update(['progress_at' => now()]);
    }

    public function markCrawling(Audit $audit): bool
    {
        return $this->transition($audit, AuditStatus::Crawling, ['started_at' => now()]);
    }

    public function markValidating(Audit $audit): bool
    {
        return $this->transition($audit, AuditStatus::Validating, ['started_at' => now()]);
    }

    public function markAnalyzing(Audit $audit): bool
    {
        return $this->transition($audit, AuditStatus::Analyzing);
    }

    public function markGeneratingReport(Audit $audit): bool
    {
        return $this->transition($audit, AuditStatus::GeneratingReport);
    }

    public function markCompleted(Audit $audit): bool
    {
        return $this->transition($audit, AuditStatus::Completed, ['completed_at' => now()]);
    }

    /**
     * Fail an audit, recording which stage it died in. `$stage` is
     * structured (`crawling`, `analyzing`, …) so failures can be counted
     * per stage; `error_message` is the human detail.
     */
    public function markFailed(Audit $audit, string $message, ?string $stage = null): bool
    {
        $moved = $this->transition($audit, AuditStatus::Failed, [
            // Bounded because a stack trace helps nobody reading a
            // report, not because the column demands it — it is TEXT
            // since §49. The old limit was 250 against a varchar(191),
            // so any message over the width made *this write* throw,
            // killing the job that was recording the failure and
            // stranding the audit in an active state forever.
            'error_message' => Str::limit($message, self::MAX_ERROR_MESSAGE),
            'failed_stage' => $stage ?? $audit->status->value,
        ]);

        if ($moved) {
            Log::channel('crawler')->warning('Audit failed', [
                'audit_id' => $audit->id,
                'stage' => $stage ?? $audit->status->value,
                'error' => $message,
            ]);
        }

        return $moved;
    }

    public function markCancelled(Audit $audit): bool
    {
        return $this->transition($audit, AuditStatus::Cancelled, [
            'failed_stage' => $audit->status->value,
        ]);
    }

    /**
     * Whether a worker holding this audit should abandon its work.
     *
     * Called at the top of every queued job. A cancellation cannot reach
     * into a running job and stop it, so the job has to ask — which means
     * cancellation takes effect at the next stage boundary, not instantly.
     */
    public function shouldStop(Audit $audit): bool
    {
        $status = Audit::whereKey($audit->id)->value('status');

        if ($status === null) {
            // The audit row is gone (owner deleted, cascade). Nothing to
            // work on and nothing to fail.
            return true;
        }

        // `value()` applies the model's casts, so this is already an
        // AuditStatus — but it is a plain string if the cast is ever
        // dropped, so both are accepted rather than assuming one.
        $status = $status instanceof AuditStatus ? $status : AuditStatus::from($status);

        return $status->isTerminal();
    }
}
