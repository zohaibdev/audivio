<?php

namespace App\Services\Schedule;

use App\Enums\AuditStatus;
use App\Enums\PlanFeature;
use App\Enums\ScheduleFrequency;
use App\Jobs\Crawl\CrawlWebsiteJob;
use App\Models\Audit;
use App\Models\AuditSchedule;
use App\Models\UsageLog;
use App\Models\User;
use App\Models\Website;
use App\Services\Audit\ActiveAuditLimiter;
use App\Services\Plan\EntitlementResolver;
use Illuminate\Support\Facades\Log;

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
    public function __construct(
        private readonly ActiveAuditLimiter $activeAudits,
        private readonly EntitlementResolver $entitlements,
    ) {}

    public function canCreateSchedule(User $user): bool
    {
        $entitlements = $this->entitlements->for($user);

        if ($entitlements === null || ! $entitlements->allows(PlanFeature::ScheduledAudits)) {
            return false;
        }

        $plan = $entitlements->plan;

        return $user->auditSchedules()->where('is_active', true)->count() < (int) ($plan?->full_recrawls ?? 0);
    }

    /**
     * Whether this user's plan permits a schedule this frequent.
     *
     * Separate from canCreateSchedule() because the two fail for different
     * reasons and deserve different messages: "your plan has no scheduled
     * audits" and "your plan allows weekly at most" are not the same
     * problem (section 40).
     */
    public function allowsFrequency(User $user, ScheduleFrequency $frequency): bool
    {
        return (bool) $this->entitlements->for($user)?->allowsFrequency($frequency);
    }

    public function maxFrequencyFor(User $user): ScheduleFrequency
    {
        return $this->entitlements->for($user)?->maxAuditFrequency ?? ScheduleFrequency::Monthly;
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

        // Through the limiter, like every other way an audit gets created
        // (§38). The concurrent-audit limit is a property of the account,
        // not of the endpoint that happens to create the audit — without
        // this, a daily schedule firing while yesterday's audit is still
        // running would stack audits past the plan's limit, and a user on
        // several schedules could bypass it entirely.
        $audit = $this->activeAudits->withSlot($user, null, function () use ($user, $website) {
            $audit = Audit::create([
                'website_id' => $website->id,
                'user_id' => $user->id,
                'status' => AuditStatus::Queued,
                // Through the resolver, not `$user->plan()`: the raw
                // relation ignores whether the subscription is still
                // active, so a lapsed subscriber's schedule would keep
                // crawling at the paid page limit forever — in a
                // background job nobody is watching.
                'max_pages' => $this->entitlements->for($user)?->maxPages
                    ?? config('entitlements.guest.max_pages'),
                'progress_at' => now(),
            ]);

            UsageLog::create([
                'user_id' => $user->id,
                'action' => 'recrawl',
                'website_id' => $website->id,
                'audit_id' => $audit->id,
            ]);

            return $audit;
        });

        if ($audit === null) {
            Log::channel('crawler')->info('Scheduled audit skipped: owner is at their concurrent-audit limit', [
                'schedule_id' => $schedule->id,
                'user_id' => $user->id,
            ]);
        } else {
            CrawlWebsiteJob::dispatch($audit);
        }

        // Advanced either way. A skipped run must still move on, or the
        // scheduler retries this schedule on every tick for as long as the
        // blocking audit runs. `last_run_at` records only runs that
        // actually started, so a skip stays visible as a gap.
        $schedule->update(array_merge(
            $audit === null ? [] : ['last_run_at' => now()],
            ['next_run_at' => $schedule->frequency->nextRunFrom(now())],
        ));
    }
}
