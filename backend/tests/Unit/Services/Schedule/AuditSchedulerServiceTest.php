<?php

namespace Tests\Unit\Services\Schedule;

use App\Enums\ScheduleFrequency;
use App\Jobs\Crawl\CrawlWebsiteJob;
use App\Models\AuditSchedule;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageLog;
use App\Models\User;
use App\Models\Website;
use App\Services\Schedule\AuditSchedulerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AuditSchedulerServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserOnPlan(array $planOverrides = []): User
    {
        $plan = Plan::create(array_merge([
            'name' => 'Paid',
            'slug' => 'paid-'.uniqid(),
            'max_pages' => 100,
            'daily_audit_limit' => 5,
            'full_recrawls' => 1,
            'features' => ['scheduled_audits' => true],
        ], $planOverrides));

        $user = User::create(['name' => 'Ada', 'email' => 'ada-'.uniqid().'@example.com', 'password' => 'x']);
        Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'started_at' => now()]);

        return $user;
    }

    public function test_a_user_with_no_plan_cannot_create_a_schedule(): void
    {
        $user = User::create(['name' => 'No Plan', 'email' => 'noplan@example.com', 'password' => 'x']);

        $this->assertFalse(app(AuditSchedulerService::class)->canCreateSchedule($user));
    }

    public function test_a_plan_without_the_scheduled_audits_feature_cannot_create_a_schedule(): void
    {
        $user = $this->makeUserOnPlan(['features' => ['scheduled_audits' => false]]);

        $this->assertFalse(app(AuditSchedulerService::class)->canCreateSchedule($user));
    }

    public function test_a_plan_with_the_feature_can_create_a_schedule_up_to_its_full_recrawls_limit(): void
    {
        $user = $this->makeUserOnPlan(['full_recrawls' => 1]);
        $website = Website::create(['user_id' => $user->id, 'primary_url' => 'https://example.com', 'domain' => 'example.com', 'normalized_domain' => 'example.com']);

        $this->assertTrue(app(AuditSchedulerService::class)->canCreateSchedule($user));

        AuditSchedule::create([
            'user_id' => $user->id,
            'website_id' => $website->id,
            'frequency' => ScheduleFrequency::Weekly,
            'next_run_at' => now()->addWeek(),
        ]);

        $this->assertFalse(app(AuditSchedulerService::class)->canCreateSchedule($user));
    }

    public function test_inactive_schedules_do_not_count_against_the_limit(): void
    {
        $user = $this->makeUserOnPlan(['full_recrawls' => 1]);
        $website = Website::create(['user_id' => $user->id, 'primary_url' => 'https://example.com', 'domain' => 'example.com', 'normalized_domain' => 'example.com']);

        AuditSchedule::create([
            'user_id' => $user->id,
            'website_id' => $website->id,
            'frequency' => ScheduleFrequency::Weekly,
            'is_active' => false,
            'next_run_at' => now()->addWeek(),
        ]);

        $this->assertTrue(app(AuditSchedulerService::class)->canCreateSchedule($user));
    }

    public function test_create_sets_next_run_at_based_on_frequency(): void
    {
        $user = $this->makeUserOnPlan();
        $website = Website::create(['user_id' => $user->id, 'primary_url' => 'https://example.com', 'domain' => 'example.com', 'normalized_domain' => 'example.com']);

        $schedule = app(AuditSchedulerService::class)->create($user, $website, ScheduleFrequency::Daily);

        $this->assertTrue($schedule->is_active);
        $this->assertTrue($schedule->next_run_at->between(now()->addDay()->subMinute(), now()->addDay()->addMinute()));
    }

    public function test_run_due_dispatches_a_crawl_and_advances_the_schedule(): void
    {
        Queue::fake();

        $user = $this->makeUserOnPlan();
        $website = Website::create(['user_id' => $user->id, 'primary_url' => 'https://example.com', 'domain' => 'example.com', 'normalized_domain' => 'example.com']);
        $schedule = AuditSchedule::create([
            'user_id' => $user->id,
            'website_id' => $website->id,
            'frequency' => ScheduleFrequency::Daily,
            'next_run_at' => now()->subMinute(),
        ]);

        $ranCount = app(AuditSchedulerService::class)->runDue();

        $this->assertSame(1, $ranCount);
        Queue::assertPushed(CrawlWebsiteJob::class);
        $this->assertDatabaseHas('usage_logs', ['user_id' => $user->id, 'website_id' => $website->id, 'action' => 'recrawl']);

        $schedule->refresh();
        $this->assertNotNull($schedule->last_run_at);
        $this->assertTrue($schedule->next_run_at->isAfter(now()));
    }

    public function test_run_due_ignores_schedules_not_yet_due(): void
    {
        Queue::fake();

        $user = $this->makeUserOnPlan();
        $website = Website::create(['user_id' => $user->id, 'primary_url' => 'https://example.com', 'domain' => 'example.com', 'normalized_domain' => 'example.com']);
        AuditSchedule::create([
            'user_id' => $user->id,
            'website_id' => $website->id,
            'frequency' => ScheduleFrequency::Daily,
            'next_run_at' => now()->addDay(),
        ]);

        $ranCount = app(AuditSchedulerService::class)->runDue();

        $this->assertSame(0, $ranCount);
        Queue::assertNotPushed(CrawlWebsiteJob::class);
        $this->assertSame(0, UsageLog::count());
    }

    public function test_run_due_ignores_inactive_schedules(): void
    {
        Queue::fake();

        $user = $this->makeUserOnPlan();
        $website = Website::create(['user_id' => $user->id, 'primary_url' => 'https://example.com', 'domain' => 'example.com', 'normalized_domain' => 'example.com']);
        AuditSchedule::create([
            'user_id' => $user->id,
            'website_id' => $website->id,
            'frequency' => ScheduleFrequency::Daily,
            'is_active' => false,
            'next_run_at' => now()->subMinute(),
        ]);

        $ranCount = app(AuditSchedulerService::class)->runDue();

        $this->assertSame(0, $ranCount);
        Queue::assertNotPushed(CrawlWebsiteJob::class);
    }
}
