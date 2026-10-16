<?php

namespace Tests\Feature\Audit;

use App\DTOs\CrawledPageData;
use App\Enums\AuditStatus;
use App\Enums\CrawlJobStatus;
use App\Enums\CrawlUrlStatus;
use App\Jobs\Audit\RunAuditModuleJob;
use App\Jobs\Crawl\CrawlPageJob;
use App\Jobs\Crawl\CrawlWebsiteJob;
use App\Models\Audit;
use App\Models\AuditSchedule;
use App\Models\CrawlJob;
use App\Models\CrawlUrl;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageLog;
use App\Models\User;
use App\Models\Website;
use App\Services\Audit\AuditLifecycle;
use App\Services\Crawler\PlaywrightCrawlerClient;
use App\Services\Schedule\AuditSchedulerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Defects found reviewing Phases 1-3 together, each pinned so it cannot
 * come back. Several are cross-phase: the lifecycle rework (§38) changed
 * assumptions that Phase 5's report builder and Phase 8's scheduler were
 * silently relying on.
 */
class AuditPipelineIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::fake(['*' => Http::response('', 404)]);
    }

    private function fakeCrawler(): void
    {
        $this->mock(PlaywrightCrawlerClient::class, function ($mock) {
            $mock->shouldReceive('crawl')->andReturn(new CrawledPageData(
                url: 'https://example.com/',
                httpStatus: 200,
                redirectChain: [],
                loadTimeMs: 500,
                screenshotDesktopPath: null,
                screenshotMobilePath: null,
                title: 'A Perfectly Fine Page Title',
                metaDescription: str_repeat('a', 100),
                canonicalUrl: 'https://example.com/',
                headings: [['level' => 1, 'text' => 'Welcome']],
                links: [],
                images: [],
                forms: [],
                structuredData: [],
                ctaElements: [],
                htmlSizeBytes: 5000,
                accessibilityViolations: [],
            ));
        });
    }

    private function makeUser(int $concurrent = 1, int $daily = 100): User
    {
        $plan = Plan::create([
            'name' => 'Test', 'slug' => 'test', 'price_cents' => 0,
            'max_pages' => 25, 'daily_audit_limit' => $daily,
            'concurrent_audit_limit' => $concurrent, 'is_default' => true,
        ]);
        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
        Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'started_at' => now()]);

        return $user;
    }

    private function makeWebsite(?User $user = null, string $domain = 'example.com'): Website
    {
        return Website::create([
            'user_id' => $user?->id,
            'name' => $domain,
            'primary_url' => "https://{$domain}/",
            'domain' => $domain,
            'normalized_domain' => $domain,
        ]);
    }

    // --- 1. The report must carry the date the audit finished ---

    public function test_a_generated_report_records_the_audit_date(): void
    {
        $this->fakeCrawler();
        $website = $this->makeWebsite();
        $audit = Audit::create(['website_id' => $website->id, 'status' => AuditStatus::Queued, 'max_pages' => 5]);

        CrawlWebsiteJob::dispatch($audit->fresh());

        $report = $audit->fresh()->report;

        $this->assertNotNull($report);
        // Regression: the §38 lifecycle rework moved the completion stamp
        // to *after* report generation, so the report was being built
        // while `completed_at` was still null and shipped
        // `audit_date: null` to every reader.
        $this->assertNotNull(
            $report->content['overview']['audit_date'],
            'The report has no audit date',
        );
    }

    // --- 2. One crawl must produce exactly one round of analysis ---

    public function test_two_page_jobs_finishing_together_dispatch_analysis_only_once(): void
    {
        Queue::fake();

        $website = $this->makeWebsite();
        $audit = Audit::create(['website_id' => $website->id, 'status' => AuditStatus::Crawling, 'max_pages' => 5]);
        $crawlJob = CrawlJob::create([
            'audit_id' => $audit->id,
            'status' => CrawlJobStatus::Running,
            'started_at' => now(),
        ]);

        // Two pages, both already finished — the state two workers see
        // when they complete the last two URLs at the same moment.
        $urls = collect(['a', 'b'])->map(fn ($p) => CrawlUrl::create([
            'crawl_job_id' => $crawlJob->id,
            'url' => "https://example.com/{$p}",
            'status' => CrawlUrlStatus::Completed,
            'depth' => 1,
            'discovered_via' => 'link',
        ]));

        // Each job's failed() path re-checks completion, which is the
        // simplest way to drive finalizeIfComplete twice.
        foreach ($urls as $url) {
            (new CrawlPageJob($url))->failed(new \RuntimeException('x'));
        }

        // Without a conditional claim on the crawl job, both callers see
        // "nothing pending" and each dispatches a full round of module
        // jobs — every audit module runs twice, writing its issues twice.
        Queue::assertPushed(RunAuditModuleJob::class, 8);
    }

    // --- 3. Scheduled audits respect the concurrency limit ---

    public function test_a_scheduled_audit_does_not_exceed_the_plans_concurrent_limit(): void
    {
        Queue::fake();

        $user = $this->makeUser(concurrent: 1);
        $website = $this->makeWebsite($user);

        // The user already has an audit running.
        Audit::create([
            'website_id' => $website->id,
            'user_id' => $user->id,
            'status' => AuditStatus::Crawling,
            'max_pages' => 25,
            'progress_at' => now(),
        ]);

        AuditSchedule::create([
            'user_id' => $user->id,
            'website_id' => $website->id,
            'frequency' => 'daily',
            'is_active' => true,
            'next_run_at' => now()->subHour(),
        ]);

        app(AuditSchedulerService::class)->runDue();

        // A schedule firing while an audit is already running must not
        // stack a second one — the limit is a property of the account,
        // not of the endpoint that happens to create the audit.
        $this->assertSame(1, Audit::query()->active()->count());
    }

    public function test_a_skipped_schedule_still_moves_its_next_run_forward(): void
    {
        Queue::fake();

        $user = $this->makeUser(concurrent: 1);
        $website = $this->makeWebsite($user);

        Audit::create([
            'website_id' => $website->id, 'user_id' => $user->id,
            'status' => AuditStatus::Crawling, 'max_pages' => 25, 'progress_at' => now(),
        ]);

        $schedule = AuditSchedule::create([
            'user_id' => $user->id, 'website_id' => $website->id,
            'frequency' => 'daily', 'is_active' => true,
            'next_run_at' => now()->subHour(),
        ]);

        app(AuditSchedulerService::class)->runDue();

        // Otherwise the scheduler retries this schedule every hour
        // forever, hammering the limiter for as long as the audit runs.
        $this->assertTrue($schedule->fresh()->next_run_at->isFuture());
    }

    // --- 4. A terminal audit must not leak the global crawl slot ---

    public function test_cancelling_an_audit_closes_its_running_crawl_job(): void
    {
        $website = $this->makeWebsite();
        $audit = Audit::create([
            'website_id' => $website->id, 'status' => AuditStatus::Crawling,
            'max_pages' => 5, 'progress_at' => now(),
        ]);
        $crawlJob = CrawlJob::create([
            'audit_id' => $audit->id, 'status' => CrawlJobStatus::Running, 'started_at' => now(),
        ]);

        app(AuditLifecycle::class)->markCancelled($audit);

        // `LimitConcurrentCrawls` caps crawling globally by counting
        // crawl_jobs still marked Running. A cancelled audit that left its
        // crawl job Running would hold one of those slots forever — and
        // after `max_concurrent_crawls` such audits, crawling stops
        // system-wide with nothing to explain why.
        $this->assertSame(CrawlJobStatus::Failed, $crawlJob->fresh()->status);
        $this->assertNotNull($crawlJob->fresh()->completed_at);
    }

    public function test_failing_an_audit_closes_its_running_crawl_job(): void
    {
        $website = $this->makeWebsite();
        $audit = Audit::create([
            'website_id' => $website->id, 'status' => AuditStatus::Crawling,
            'max_pages' => 5, 'progress_at' => now(),
        ]);
        $crawlJob = CrawlJob::create([
            'audit_id' => $audit->id, 'status' => CrawlJobStatus::Running, 'started_at' => now(),
        ]);

        app(AuditLifecycle::class)->markFailed($audit, 'boom', 'crawling');

        $this->assertSame(CrawlJobStatus::Failed, $crawlJob->fresh()->status);
    }

    public function test_stuck_audit_recovery_also_frees_the_global_crawl_slot(): void
    {
        config(['audit_limits.stuck_after_minutes' => 30]);

        $website = $this->makeWebsite();
        $audit = Audit::create([
            'website_id' => $website->id, 'status' => AuditStatus::Crawling,
            'max_pages' => 5, 'progress_at' => now()->subHours(2),
        ]);
        CrawlJob::create([
            'audit_id' => $audit->id, 'status' => CrawlJobStatus::Running, 'started_at' => now()->subHours(2),
        ]);

        $this->artisan('audits:recover-stuck')->assertSuccessful();

        $this->assertSame(0, CrawlJob::where('status', CrawlJobStatus::Running)->count());
    }

    public function test_completing_an_audit_leaves_its_already_completed_crawl_job_alone(): void
    {
        $website = $this->makeWebsite();
        $audit = Audit::create([
            'website_id' => $website->id, 'status' => AuditStatus::GeneratingReport,
            'max_pages' => 5, 'progress_at' => now(),
        ]);
        $finishedAt = now()->subMinutes(5);
        $crawlJob = CrawlJob::create([
            'audit_id' => $audit->id, 'status' => CrawlJobStatus::Completed,
            'started_at' => now()->subMinutes(10), 'completed_at' => $finishedAt,
        ]);

        app(AuditLifecycle::class)->markCompleted($audit);

        // Only *running* crawl jobs are closed out. A successful crawl
        // already recorded when it finished, and overwriting that would
        // corrupt the crawl duration every report reads.
        $this->assertSame(CrawlJobStatus::Completed, $crawlJob->fresh()->status);
        $this->assertSame(
            $finishedAt->toDateTimeString(),
            $crawlJob->fresh()->completed_at->toDateTimeString(),
        );
    }

    // --- 5. Cancelling an anonymous audit ---

    public function test_a_stranger_cannot_cancel_an_anonymous_audit_from_another_ip(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $website = $this->makeWebsite();
        $audit = Audit::create([
            'website_id' => $website->id,
            'user_id' => null,
            'status' => AuditStatus::Crawling,
            'max_pages' => 5,
            'progress_at' => now(),
        ]);
        UsageLog::create([
            'user_id' => null,
            'ip_address' => '203.0.113.9',
            'action' => 'audit_created',
            'website_id' => $website->id,
            'audit_id' => $audit->id,
        ]);

        // Reading an anonymous audit by ID is an accepted gap (§28).
        // *Destroying* one is not: anyone enumerating IDs could cancel
        // every guest's audit.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->postJson("/api/audits/{$audit->id}/cancel")
            ->assertStatus(404);

        $this->assertSame(AuditStatus::Crawling, $audit->fresh()->status);
    }

    public function test_the_guest_who_started_an_anonymous_audit_can_still_cancel_it(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $website = $this->makeWebsite();
        $audit = Audit::create([
            'website_id' => $website->id, 'user_id' => null,
            'status' => AuditStatus::Crawling, 'max_pages' => 5, 'progress_at' => now(),
        ]);
        UsageLog::create([
            'user_id' => null, 'ip_address' => '203.0.113.9', 'action' => 'audit_created',
            'website_id' => $website->id, 'audit_id' => $audit->id,
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson("/api/audits/{$audit->id}/cancel")
            ->assertStatus(200);

        $this->assertSame(AuditStatus::Cancelled, $audit->fresh()->status);
    }
}
