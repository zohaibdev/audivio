<?php

namespace App\Services\Schedule;

use App\Enums\AuditStatus;
use App\Enums\ScheduleFrequency;
use App\Jobs\Crawl\CrawlWebsiteJob;
use App\Models\Audit;
use App\Models\AuditSchedule;
use App\Models\UsageLog;
use App\Models\User;
use App\Models\Website;

/**
 * Recurring audits, gated by the plan's `scheduled_audits` feature flag
 * and capped by `plans.full_recrawls` — the number of websites a user can
 * have on a recurring schedule at once. Both fields sat unused on `plans`
 * until this phase (see CONTEXT.md §28); wiring them together here means
 * "how many recrawls" and "am I allowed to schedule at all" stay two
 * separate, independently plan-configurable knobs.
 */
class AuditSchedulerService
{
    public function canCreateSchedule(User $user): bool
    {
        $plan = $user->plan();

        if ($plan === null || ! $plan->hasFeature('scheduled_audits')) {
            return false;
        }

        return $user->auditSchedules()->where('is_active', true)->count() < $plan->full_recrawls;
    }

    public function create(User $user, Website $website, ScheduleFrequency $frequency): AuditSchedule
    {
        return AuditSchedule::create([
            'user_id' => $user->id,
            'website_id' => $website->id,
            'frequency' => $frequency,
            'is_active' => true,
            'next_run_at' => $frequency->nextRunFrom(now()),
        ]);
    }

    /**
     * Runs every due schedule, dispatching a fresh audit for each and
     * advancing it to its next run. Intended to be called from the
     * scheduler (bootstrap/app.php `withSchedule`), not directly from a
     * request — there is no caller-supplied IP, so usage is logged under
     * the 'recrawl' action rather than 'audit_created' (see the
     * usage_logs migration, which already anticipated this action name).
     */
    public function runDue(): int
    {
        $count = 0;

        AuditSchedule::query()
            ->where('is_active', true)
            ->where('next_run_at', '<=', now())
            ->with(['website', 'user.subscription.plan'])
            ->chunkById(50, function ($schedules) use (&$count) {
                foreach ($schedules as $schedule) {
                    $this->runOne($schedule);
                    $count++;
                }
            });

        return $count;
    }

    private function runOne(AuditSchedule $schedule): void
    {
        $website = $schedule->website;
        $user = $schedule->user;

        $audit = Audit::create([
            'website_id' => $website->id,
            'user_id' => $user->id,
            'status' => AuditStatus::Pending,
            'max_pages' => $user->plan()?->max_pages ?? config('audit_limits.anonymous_max_pages'),
        ]);

        UsageLog::create([
            'user_id' => $user->id,
            'action' => 'recrawl',
            'website_id' => $website->id,
            'audit_id' => $audit->id,
        ]);

        CrawlWebsiteJob::dispatch($audit);

        $schedule->update([
            'last_run_at' => now(),
            'next_run_at' => $schedule->frequency->nextRunFrom(now()),
        ]);
    }
}
