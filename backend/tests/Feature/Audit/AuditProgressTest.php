<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditStage;
use App\Enums\AuditStatus;
use App\Enums\ScreenshotViewport;
use App\Models\Audit;
use App\Models\AuditPage;
use App\Models\AuditScore;
use App\Models\CrawlJob;
use App\Models\Screenshot;
use App\Models\Website;
use App\Services\Audit\AuditProgressCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Audit progress and estimated duration (§51).
 *
 * The rule under test throughout: **never show fake progress.** Most of
 * these check that a figure is *absent* when nothing has been measured,
 * because an invented estimate is still believed at the five-minute mark.
 */
class AuditProgressTest extends TestCase
{
    use RefreshDatabase;

    private function makeAudit(AuditStatus $status = AuditStatus::Queued, array $attributes = []): Audit
    {
        $website = Website::create([
            'name' => 'example.com', 'primary_url' => 'https://example.com/',
            'domain' => 'example.com', 'normalized_domain' => 'example.com', 'platform' => 'unknown',
        ]);

        return Audit::create(array_merge([
            'website_id' => $website->id, 'status' => $status->value,
            'max_pages' => 5, 'started_at' => now()->subSeconds(30),
        ], $attributes));
    }

    private function progress(Audit $audit): array
    {
        return app(AuditProgressCalculator::class)->forAudit($audit->fresh());
    }

    // --- Stages ---

    public function test_each_status_reports_the_stage_a_person_would_recognise(): void
    {
        foreach ([
            [AuditStatus::Queued, AuditStage::Preparing],
            [AuditStatus::Validating, AuditStage::Validating],
            [AuditStatus::GeneratingReport, AuditStage::BuildingReport],
        ] as [$status, $expected]) {
            $audit = $this->makeAudit($status);

            $this->assertSame($expected->value, $this->progress($audit)['stage']);
        }
    }

    public function test_a_crawl_with_nothing_discovered_yet_reads_as_discovering(): void
    {
        $audit = $this->makeAudit(AuditStatus::Crawling);
        CrawlJob::create(['audit_id' => $audit->id, 'status' => 'running', 'total_urls' => 1, 'started_at' => now()]);

        // One URL is the seed. Calling that "crawling 1 of 1" would
        // promise a finish line the crawler has not found yet.
        $this->assertSame(AuditStage::Discovering->value, $this->progress($audit)['stage']);
    }

    public function test_screenshots_are_reported_once_one_actually_exists(): void
    {
        Storage::fake(config('crawler.screenshot_disk'));

        $audit = $this->makeAudit(AuditStatus::Crawling, ['pages_crawled' => 2]);
        CrawlJob::create(['audit_id' => $audit->id, 'status' => 'running', 'total_urls' => 5, 'started_at' => now()]);

        $this->assertSame(AuditStage::Crawling->value, $this->progress($audit)['stage']);

        $page = AuditPage::create(['audit_id' => $audit->id, 'url' => 'https://example.com/', 'http_status' => 200]);
        Screenshot::create([
            'audit_id' => $audit->id, 'audit_page_id' => $page->id,
            'viewport' => ScreenshotViewport::Desktop, 'width' => 1, 'height' => 1,
            'storage_path' => 'screenshots/a.png',
        ]);

        // The stage advances because a screenshot row exists, not because
        // time passed.
        $this->assertSame(AuditStage::Screenshots->value, $this->progress($audit)['stage']);
    }

    public function test_the_analysis_stage_follows_which_scores_have_been_written(): void
    {
        $audit = $this->makeAudit(AuditStatus::Analyzing, ['pages_crawled' => 3]);

        $this->assertSame(AuditStage::Performance->value, $this->progress($audit)['stage']);

        AuditScore::create(['audit_id' => $audit->id, 'category' => 'performance', 'score' => 70]);

        // Moved on because performance produced a result, not because a
        // timer elapsed.
        $this->assertSame(AuditStage::Seo->value, $this->progress($audit)['stage']);
    }

    public function test_a_terminal_audit_reports_no_current_stage(): void
    {
        foreach ([AuditStatus::Completed, AuditStatus::Failed, AuditStatus::Cancelled] as $status) {
            $audit = $this->makeAudit($status);

            // Naming a stage would suggest something is still happening.
            $this->assertNull($this->progress($audit)['stage'], $status->value);
        }
    }

    public function test_every_named_stage_is_listed_with_its_state(): void
    {
        $audit = $this->makeAudit(AuditStatus::Crawling, ['pages_crawled' => 1]);
        CrawlJob::create(['audit_id' => $audit->id, 'status' => 'running', 'total_urls' => 4, 'started_at' => now()]);

        $stages = $this->progress($audit)['stages'];

        $this->assertCount(12, $stages, 'all twelve stages are shown, so the reader sees the whole journey');
        $this->assertSame(['preparing', 'validating', 'discovering'], array_column(array_slice($stages, 0, 3), 'stage'));
        $this->assertSame('done', $stages[0]['state']);
        $this->assertSame('current', $stages[3]['state']);
        $this->assertSame('pending', $stages[11]['state']);
    }

    public function test_a_completed_audit_shows_every_stage_done(): void
    {
        $stages = $this->progress($this->makeAudit(AuditStatus::Completed))['stages'];

        $this->assertSame(['done'], array_unique(array_column($stages, 'state')));
    }

    // --- Counts ---

    public function test_pages_discovered_is_what_the_crawler_found_not_the_plan_limit(): void
    {
        $audit = $this->makeAudit(AuditStatus::Crawling, ['max_pages' => 25, 'pages_crawled' => 2]);
        CrawlJob::create(['audit_id' => $audit->id, 'status' => 'running', 'total_urls' => 7, 'started_at' => now()]);

        $progress = $this->progress($audit);

        // Showing 25 would promise pages that may not exist on the site.
        $this->assertSame(7, $progress['pages_discovered']);
        $this->assertSame(2, $progress['pages_analyzed']);
    }

    public function test_services_are_done_because_a_result_exists(): void
    {
        $audit = $this->makeAudit(AuditStatus::Analyzing, ['pages_crawled' => 2]);
        AuditScore::create(['audit_id' => $audit->id, 'category' => 'seo', 'score' => 80]);

        $services = collect($this->progress($audit)['services'])->keyBy('category');

        $this->assertSame('done', $services['seo']['state']);
        $this->assertSame('running', $services['performance']['state']);
        $this->assertContains('SEO', $services->pluck('label')->all());
    }

    public function test_services_are_pending_before_analysis_begins(): void
    {
        $audit = $this->makeAudit(AuditStatus::Crawling);

        $states = collect($this->progress($audit)['services'])->pluck('state')->unique();

        // Nothing is "running" while the crawl is still going — claiming
        // otherwise is the cheapest kind of fake progress.
        $this->assertSame(['pending'], $states->all());
        $this->assertSame([], $this->progress($audit)['services_running']);
    }

    // --- The estimate ---

    public function test_no_estimate_is_offered_before_the_first_page_completes(): void
    {
        $audit = $this->makeAudit(AuditStatus::Crawling, ['pages_crawled' => 0]);
        CrawlJob::create(['audit_id' => $audit->id, 'status' => 'running', 'total_urls' => 10, 'started_at' => now()]);

        $estimate = $this->progress($audit)['estimate'];

        // There is no rate yet, so there is no projection. A number here
        // would be a guess dressed as a measurement.
        $this->assertFalse($estimate['is_available']);
        $this->assertNull($estimate['remaining_seconds']);
        $this->assertStringContainsString('measured from this audit', $estimate['reason']);
    }

    public function test_the_estimate_is_projected_from_the_measured_page_rate(): void
    {
        $audit = $this->makeAudit(AuditStatus::Crawling, [
            'pages_crawled' => 4,
            'started_at' => now()->subSeconds(40),
        ]);
        CrawlJob::create(['audit_id' => $audit->id, 'status' => 'running', 'total_urls' => 10, 'started_at' => now()]);

        $estimate = $this->progress($audit)['estimate'];

        // 40s for 4 pages is 10s a page; 6 pages remain, so ~60s of crawl
        // plus the outstanding services.
        $this->assertTrue($estimate['is_available']);
        $this->assertGreaterThanOrEqual(60, $estimate['remaining_seconds']);
        $this->assertStringContainsString('4 page(s) analyzed', $estimate['basis']);
    }

    public function test_a_slower_crawl_produces_a_longer_estimate(): void
    {
        $fast = $this->makeAudit(AuditStatus::Crawling, ['pages_crawled' => 5, 'started_at' => now()->subSeconds(10)]);
        CrawlJob::create(['audit_id' => $fast->id, 'status' => 'running', 'total_urls' => 10, 'started_at' => now()]);

        $slow = $this->makeAudit(AuditStatus::Crawling, ['pages_crawled' => 5, 'started_at' => now()->subSeconds(200)]);
        CrawlJob::create(['audit_id' => $slow->id, 'status' => 'running', 'total_urls' => 10, 'started_at' => now()]);

        // The estimate tracks the site actually being crawled, which is
        // the whole point of measuring rather than assuming.
        $this->assertGreaterThan(
            $this->progress($fast)['estimate']['remaining_seconds'],
            $this->progress($slow)['estimate']['remaining_seconds'],
        );
    }

    public function test_a_finished_audit_offers_no_estimate(): void
    {
        $audit = $this->makeAudit(AuditStatus::Completed, ['pages_crawled' => 5]);

        $this->assertFalse($this->progress($audit)['estimate']['is_available']);
    }

    // --- Large sites ---

    public function test_a_large_audit_says_plainly_that_it_will_take_longer(): void
    {
        $audit = $this->makeAudit(AuditStatus::Crawling, ['max_pages' => 25]);

        $note = $this->progress($audit)['scale_note'];

        // Told, rather than left to be inferred from a bar that is not
        // moving.
        $this->assertNotNull($note);
        $this->assertStringContainsString('25 pages', $note);
        $this->assertStringContainsString('longer', $note);
    }

    public function test_a_small_audit_is_not_given_a_warning_it_does_not_need(): void
    {
        $audit = $this->makeAudit(AuditStatus::Crawling, ['max_pages' => 3]);

        $this->assertNull($this->progress($audit)['scale_note']);
    }

    // --- Through the API ---

    public function test_the_status_endpoint_carries_the_progress_payload(): void
    {
        $audit = $this->makeAudit(AuditStatus::Crawling, ['pages_crawled' => 2]);
        CrawlJob::create(['audit_id' => $audit->id, 'status' => 'running', 'total_urls' => 6, 'started_at' => now()]);

        $progress = $this->getJson("/api/audits/{$audit->id}")
            ->assertStatus(200)
            ->json('data.progress');

        $this->assertSame(6, $progress['pages_discovered']);
        $this->assertSame(2, $progress['pages_analyzed']);
        $this->assertNotEmpty($progress['stage_label']);
        $this->assertCount(12, $progress['stages']);
    }
}
