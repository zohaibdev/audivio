<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditCategory;
use App\Enums\AuditStatus;
use App\Jobs\Audit\CalculateScoreJob;
use App\Jobs\Audit\RunAuditModuleJob;
use App\Jobs\Crawl\CrawlWebsiteJob;
use App\Jobs\Report\GenerateReportJob;
use App\Models\Audit;
use App\Models\AuditScore;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;
use App\Services\Audit\ActiveAuditLimiter;
use App\Services\Audit\AuditLifecycle;
use App\Services\Audit\SeoAuditModule;
use App\Services\Crawler\CrawlerException;
use App\Services\Crawler\CrawlOrchestrator;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Failure handling: nothing may leave an audit stranded in an active
 * state, because an active audit holds one of its owner's concurrent
 * slots and the owner can never start another.
 */
class AuditReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function makeUser(): User
    {
        $plan = Plan::create([
            'name' => 'Free', 'slug' => 'free', 'price_cents' => 0,
            'max_pages' => 25, 'daily_audit_limit' => 100,
            'concurrent_audit_limit' => 1, 'is_default' => true,
        ]);
        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
        Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'started_at' => now()]);

        return $user;
    }

    private function makeAudit(?User $user, AuditStatus $status = AuditStatus::Queued, string $domain = 'example.com'): Audit
    {
        // The domain is a parameter because `websites` is unique on
        // (user_id, normalized_domain) since Phase 2 — a test that needs
        // two audits for one user needs two websites.
        $website = Website::create([
            'user_id' => $user?->id,
            'name' => $domain,
            'primary_url' => "https://{$domain}/",
            'domain' => $domain,
            'normalized_domain' => $domain,
        ]);

        return Audit::create([
            'website_id' => $website->id,
            'user_id' => $user?->id,
            'status' => $status,
            'max_pages' => 5,
            'progress_at' => now(),
        ]);
    }

    // --- Crawler failures ---

    public function test_a_crawler_failure_fails_the_audit_and_records_the_stage(): void
    {
        $audit = $this->makeAudit($this->makeUser());

        $this->mock(CrawlOrchestrator::class, function ($mock) {
            $mock->shouldReceive('startCrawl')->andThrow(new CrawlerException('Playwright could not start'));
        });

        CrawlWebsiteJob::dispatchSync($audit);

        $audit->refresh();
        $this->assertSame(AuditStatus::Failed, $audit->status);
        $this->assertSame('crawling', $audit->failed_stage);
        $this->assertStringContainsString('Playwright', $audit->error_message);
        $this->assertNotNull($audit->terminated_at);
    }

    public function test_a_crawler_failure_releases_the_owners_slot(): void
    {
        $user = $this->makeUser();
        $audit = $this->makeAudit($user);

        $this->mock(CrawlOrchestrator::class, function ($mock) {
            $mock->shouldReceive('startCrawl')->andThrow(new CrawlerException('boom'));
        });

        $this->assertFalse(app(ActiveAuditLimiter::class)->hasCapacity($user, null));

        CrawlWebsiteJob::dispatchSync($audit);

        $this->assertTrue(app(ActiveAuditLimiter::class)->hasCapacity($user, null));
    }

    public function test_a_job_the_queue_gives_up_on_still_fails_the_audit(): void
    {
        $user = $this->makeUser();
        $audit = $this->makeAudit($user, AuditStatus::Crawling);

        // The queue calls failed() when `tries` is exhausted or the worker
        // dies. Without this handler the audit would stay `crawling`
        // forever, holding a slot nothing could release.
        (new CrawlWebsiteJob($audit))->failed(new \RuntimeException('worker died'));

        $audit->refresh();
        $this->assertSame(AuditStatus::Failed, $audit->status);
        $this->assertStringContainsString('worker died', $audit->error_message);
        $this->assertTrue(app(ActiveAuditLimiter::class)->hasCapacity($user, null));
    }

    public function test_every_pipeline_job_marks_the_audit_failed_when_the_queue_gives_up(): void
    {
        $user = $this->makeUser();

        foreach ([
            CalculateScoreJob::class => 'analyzing',
            GenerateReportJob::class => 'generating_report',
        ] as $jobClass => $expectedStage) {
            $audit = $this->makeAudit($user, AuditStatus::Analyzing, class_basename($jobClass).'.example');

            (new $jobClass($audit))->failed(new \RuntimeException('gave up'));

            $audit->refresh();
            $this->assertSame(AuditStatus::Failed, $audit->status, "{$jobClass} left the audit active");
            $this->assertSame($expectedStage, $audit->failed_stage);
        }
    }

    // --- Partial failures ---

    public function test_one_failing_module_does_not_fail_the_whole_audit(): void
    {
        $audit = $this->makeAudit($this->makeUser(), AuditStatus::Analyzing);

        // A module that throws must not strand the audit: the category is
        // recorded as unassessable so the pipeline can still finish, and
        // the other categories' real findings survive.
        $this->mock(SeoAuditModule::class, function ($mock) {
            $mock->shouldReceive('category')->andReturn(AuditCategory::Seo);
            $mock->shouldReceive('run')->andThrow(new \RuntimeException('module exploded'));
        });

        RunAuditModuleJob::dispatchSync($audit, SeoAuditModule::class);

        $score = AuditScore::where('audit_id', $audit->id)->where('category', 'seo')->first();

        $this->assertNotNull($score, 'A failed module must still leave a score row or the pipeline stalls');
        $this->assertNull($score->score);
        $this->assertFalse((bool) $score->is_applicable);
        // The marker that separates a crashed module from a clean
        // Not Applicable result.
        $this->assertStringContainsString('module exploded', $score->breakdown['error']);
    }

    public function test_a_module_failure_is_retried_before_the_category_is_written_off(): void
    {
        $audit = $this->makeAudit($this->makeUser(), AuditStatus::Analyzing);

        $this->mock(SeoAuditModule::class, function ($mock) {
            $mock->shouldReceive('category')->andReturn(AuditCategory::Seo);
            $mock->shouldReceive('run')->andThrow(new \RuntimeException('transient blip'));
        });

        // Simulate the queue's first attempt. Before this, the first throw
        // was swallowed and the category written off immediately, which
        // made `$tries = 2` dead code.
        $job = new RunAuditModuleJob($audit, SeoAuditModule::class);
        $queueJob = \Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        // Not 'sync' — the sync driver never retries, so the job treats it
        // as a final attempt regardless of the count.
        $queueJob->shouldReceive('getConnectionName')->andReturn('redis');
        $job->setJob($queueJob);

        $this->expectException(\RuntimeException::class);

        try {
            $job->handle(app(AuditLifecycle::class));
        } finally {
            // Nothing written yet — the retry gets a clean shot.
            $this->assertNull(
                AuditScore::where('audit_id', $audit->id)->where('category', 'seo')->first(),
            );
        }
    }

    public function test_a_module_failure_on_the_final_attempt_writes_off_the_category(): void
    {
        $audit = $this->makeAudit($this->makeUser(), AuditStatus::Analyzing);

        $this->mock(SeoAuditModule::class, function ($mock) {
            $mock->shouldReceive('category')->andReturn(AuditCategory::Seo);
            $mock->shouldReceive('run')->andThrow(new \RuntimeException('still broken'));
        });

        $job = new RunAuditModuleJob($audit, SeoAuditModule::class);
        $queueJob = \Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn(2);
        $queueJob->shouldReceive('getConnectionName')->andReturn('redis');
        $job->setJob($queueJob);

        $job->handle(app(AuditLifecycle::class));

        $score = AuditScore::where('audit_id', $audit->id)->where('category', 'seo')->first();
        $this->assertNotNull($score);
        $this->assertNull($score->score);
    }

    public function test_a_module_the_queue_gives_up_on_also_records_an_unassessable_score(): void
    {
        $audit = $this->makeAudit($this->makeUser(), AuditStatus::Analyzing);

        (new RunAuditModuleJob($audit, SeoAuditModule::class))->failed(new \RuntimeException('worker died'));

        $score = AuditScore::where('audit_id', $audit->id)->where('category', 'seo')->first();
        $this->assertNotNull($score);
        $this->assertNull($score->score);
    }

    // --- Cancellation stops work ---

    public function test_a_cancelled_audit_stops_the_crawl_job_before_it_does_anything(): void
    {
        $audit = $this->makeAudit($this->makeUser(), AuditStatus::Cancelled);

        $orchestrator = $this->mock(CrawlOrchestrator::class);
        $orchestrator->shouldNotReceive('startCrawl');

        CrawlWebsiteJob::dispatchSync($audit);

        $this->assertSame(AuditStatus::Cancelled, $audit->fresh()->status);
    }

    public function test_a_cancelled_audit_stops_the_report_job(): void
    {
        $audit = $this->makeAudit($this->makeUser(), AuditStatus::Cancelled);

        GenerateReportJob::dispatchSync($audit);

        $this->assertSame(AuditStatus::Cancelled, $audit->fresh()->status);
        $this->assertNull($audit->fresh()->report);
        $this->assertNull($audit->fresh()->snapshot);
    }

    // --- Stuck-audit recovery ---

    public function test_recovery_fails_an_audit_that_stopped_making_progress(): void
    {
        config(['audit_limits.stuck_after_minutes' => 30]);
        $user = $this->makeUser();
        $audit = $this->makeAudit($user, AuditStatus::Crawling);
        $audit->update(['progress_at' => now()->subMinutes(45)]);

        $this->artisan('audits:recover-stuck')
            ->expectsOutputToContain('Recovered 1 stuck audit(s).')
            ->assertSuccessful();

        $audit->refresh();
        $this->assertSame(AuditStatus::Failed, $audit->status);
        $this->assertSame('crawling', $audit->failed_stage);
        // The point of recovery: the owner can start audits again.
        $this->assertTrue(app(ActiveAuditLimiter::class)->hasCapacity($user, null));
    }

    public function test_recovery_leaves_an_audit_that_is_merely_slow_alone(): void
    {
        config(['audit_limits.stuck_after_minutes' => 30]);
        $audit = $this->makeAudit($this->makeUser(), AuditStatus::Crawling);

        // `progress_at` is bumped by every job that does real work, so a
        // long-but-healthy crawl keeps refreshing it.
        $audit->update(['progress_at' => now()->subMinutes(5)]);

        $this->artisan('audits:recover-stuck')
            ->expectsOutputToContain('No stuck audits found.')
            ->assertSuccessful();

        $this->assertSame(AuditStatus::Crawling, $audit->fresh()->status);
    }

    public function test_recovery_never_touches_a_finished_audit(): void
    {
        $audit = $this->makeAudit($this->makeUser(), AuditStatus::Completed);
        $audit->update(['progress_at' => now()->subDays(30)]);

        $this->artisan('audits:recover-stuck')->assertSuccessful();

        $this->assertSame(AuditStatus::Completed, $audit->fresh()->status);
    }

    public function test_recovery_falls_back_to_updated_at_for_rows_predating_progress_tracking(): void
    {
        config(['audit_limits.stuck_after_minutes' => 30]);
        $audit = $this->makeAudit($this->makeUser(), AuditStatus::Analyzing);

        // Audits created before `progress_at` existed have none.
        Audit::whereKey($audit->id)->update([
            'progress_at' => null,
            'updated_at' => now()->subHours(3),
        ]);

        $this->artisan('audits:recover-stuck')->assertSuccessful();

        $this->assertSame(AuditStatus::Failed, $audit->fresh()->status);
    }

    public function test_a_dry_run_reports_without_changing_anything(): void
    {
        config(['audit_limits.stuck_after_minutes' => 30]);
        $audit = $this->makeAudit($this->makeUser(), AuditStatus::Crawling);
        $audit->update(['progress_at' => now()->subHours(2)]);

        $this->artisan('audits:recover-stuck --dry-run')
            ->expectsOutputToContain('Would recover 1 stuck audit(s).')
            ->assertSuccessful();

        $this->assertSame(AuditStatus::Crawling, $audit->fresh()->status);
    }

    public function test_the_threshold_can_be_overridden_per_run(): void
    {
        config(['audit_limits.stuck_after_minutes' => 600]);
        $audit = $this->makeAudit($this->makeUser(), AuditStatus::Crawling);
        $audit->update(['progress_at' => now()->subMinutes(20)]);

        // Not stuck by the configured threshold...
        $this->artisan('audits:recover-stuck')->assertSuccessful();
        $this->assertSame(AuditStatus::Crawling, $audit->fresh()->status);

        // ...but stuck by a tighter one passed on the command line.
        $this->artisan('audits:recover-stuck --minutes=10')->assertSuccessful();
        $this->assertSame(AuditStatus::Failed, $audit->fresh()->status);
    }

    public function test_progress_updates_keep_an_audit_out_of_recovery(): void
    {
        config(['audit_limits.stuck_after_minutes' => 30]);
        $audit = $this->makeAudit($this->makeUser(), AuditStatus::Crawling);
        $audit->update(['progress_at' => now()->subMinutes(45)]);

        app(AuditLifecycle::class)->touch($audit);

        $this->artisan('audits:recover-stuck')
            ->expectsOutputToContain('No stuck audits found.')
            ->assertSuccessful();
    }
}
