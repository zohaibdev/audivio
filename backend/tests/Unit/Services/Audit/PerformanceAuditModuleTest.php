<?php

namespace Tests\Unit\Services\Audit;

use App\Enums\MetricSource;
use App\Enums\MetricStrategy;
use App\Models\Audit;
use App\Models\AuditPage;
use App\Models\PagePerformanceMetric;
use App\Models\Website;
use App\Services\Audit\PageSpeedService;
use App\Services\Audit\PerformanceAuditModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PerformanceAuditModuleTest extends TestCase
{
    use RefreshDatabase;

    private function makeAudit(): Audit
    {
        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com']);

        return Audit::create(['website_id' => $website->id, 'status' => 'analyzing', 'max_pages' => 5]);
    }

    /**
     * `analyzeBoth()` returns results keyed by strategy so mobile and
     * desktop stay separate measurements. Passing null here means "no
     * strategy produced a result" (provider down/unconfigured); passing an
     * array means it came back as the mobile result, which is what drives
     * issues (mobile is the primary priority).
     */
    private function pageSpeedServiceReturning(?array $result, ?array $desktop = null): PageSpeedService
    {
        $results = [];
        if ($result !== null) {
            $results['mobile'] = $result + ['strategy' => 'mobile'];
        }
        if ($desktop !== null) {
            $results['desktop'] = $desktop + ['strategy' => 'desktop'];
        }

        $mock = Mockery::mock(PageSpeedService::class);
        $mock->shouldReceive('analyzeBoth')->andReturn($results);

        return $mock;
    }

    public function test_fast_lightweight_page_scores_100(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'load_time_ms' => 500,
            'html_size_bytes' => 10_000,
            'images' => [['src' => 'a.png', 'width' => 100, 'height' => 100]],
        ]);

        $result = (new PerformanceAuditModule($this->pageSpeedServiceReturning(null)))->run($audit->fresh());

        $this->assertSame(100, $result->score);
        $this->assertCount(0, $result->issues);
    }

    public function test_slow_load_time_flagged(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'load_time_ms' => 5000, // over the 3000ms default budget
            'html_size_bytes' => 10_000,
            'images' => [],
        ]);

        $result = (new PerformanceAuditModule($this->pageSpeedServiceReturning(null)))->run($audit->fresh());

        $this->assertTrue(collect($result->issues)->pluck('code')->contains('performance.slow_load_time'));
    }

    public function test_oversized_page_weight_flagged(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'load_time_ms' => 500,
            'html_size_bytes' => 5 * 1024 * 1024, // over the 2MB default budget
            'images' => [],
        ]);

        $result = (new PerformanceAuditModule($this->pageSpeedServiceReturning(null)))->run($audit->fresh());

        $this->assertTrue(collect($result->issues)->pluck('code')->contains('performance.large_html'));
    }

    public function test_images_missing_dimensions_flagged(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'load_time_ms' => 500,
            'html_size_bytes' => 10_000,
            'images' => [
                ['src' => 'a.png', 'width' => null, 'height' => null],
                ['src' => 'b.png', 'width' => 100, 'height' => 100],
            ],
        ]);

        $result = (new PerformanceAuditModule($this->pageSpeedServiceReturning(null)))->run($audit->fresh());

        $issue = collect($result->issues)->firstWhere('code', 'performance.missing_image_dimensions');
        $this->assertNotNull($issue);
        $this->assertSame(1, $issue->meta['count']);
    }

    public function test_low_pagespeed_score_is_critical(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'load_time_ms' => 500,
            'html_size_bytes' => 10_000,
            'images' => [],
        ]);

        $result = (new PerformanceAuditModule($this->pageSpeedServiceReturning([
            'score' => 30, 'lcpMs' => null, 'cls' => null, 'tbtMs' => null, 'fcpMs' => null,
        ])))->run($audit->fresh());

        $issue = collect($result->issues)->firstWhere('code', 'performance.pagespeed_score');
        $this->assertNotNull($issue);
        $this->assertSame('critical', $issue->severity->value);
    }

    public function test_moderate_pagespeed_score_is_medium(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'load_time_ms' => 500,
            'html_size_bytes' => 10_000,
            'images' => [],
        ]);

        $result = (new PerformanceAuditModule($this->pageSpeedServiceReturning([
            'score' => 75, 'lcpMs' => null, 'cls' => null, 'tbtMs' => null, 'fcpMs' => null,
        ])))->run($audit->fresh());

        $issue = collect($result->issues)->firstWhere('code', 'performance.pagespeed_score');
        $this->assertNotNull($issue);
        $this->assertSame('medium', $issue->severity->value);
    }

    public function test_high_pagespeed_score_is_not_flagged(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'load_time_ms' => 500,
            'html_size_bytes' => 10_000,
            'images' => [],
        ]);

        $result = (new PerformanceAuditModule($this->pageSpeedServiceReturning([
            'score' => 98, 'lcpMs' => null, 'cls' => null, 'tbtMs' => null, 'fcpMs' => null,
        ])))->run($audit->fresh());

        $this->assertFalse(collect($result->issues)->pluck('code')->contains('performance.pagespeed_score'));
    }

    public function test_poor_lcp_is_flagged_high(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'load_time_ms' => 500,
            'html_size_bytes' => 10_000,
            'images' => [],
        ]);

        $result = (new PerformanceAuditModule($this->pageSpeedServiceReturning([
            'score' => 95, 'lcpMs' => 4000.0, 'cls' => null, 'tbtMs' => null, 'fcpMs' => null,
        ])))->run($audit->fresh());

        $issue = collect($result->issues)->firstWhere('code', 'performance.lcp');
        $this->assertNotNull($issue);
        $this->assertSame('high', $issue->severity->value);
    }

    public function test_poor_cls_is_flagged_medium(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'load_time_ms' => 500,
            'html_size_bytes' => 10_000,
            'images' => [],
        ]);

        $result = (new PerformanceAuditModule($this->pageSpeedServiceReturning([
            'score' => 95, 'lcpMs' => null, 'cls' => 0.35, 'tbtMs' => null, 'fcpMs' => null,
        ])))->run($audit->fresh());

        $issue = collect($result->issues)->firstWhere('code', 'performance.cls');
        $this->assertNotNull($issue);
        $this->assertSame('medium', $issue->severity->value);
    }

    public function test_the_same_issue_on_every_page_is_aggregated_into_one_and_does_not_zero_the_score(): void
    {
        $audit = $this->makeAudit();

        // 25 pages, every single one slow (Medium, 5pts) — before
        // aggregation this was 25 separate deductions (125pts), flooring
        // the score at 0 regardless of anything else about the site.
        for ($i = 0; $i < 25; $i++) {
            AuditPage::create([
                'audit_id' => $audit->id,
                'url' => "https://example.com/page-{$i}",
                'http_status' => 200,
                'load_time_ms' => 5000,
                'html_size_bytes' => 10_000,
                'images' => [],
            ]);
        }

        $result = (new PerformanceAuditModule($this->pageSpeedServiceReturning(null)))->run($audit->fresh());

        $slowIssues = collect($result->issues)->where('code', 'performance.slow_load_time');
        $this->assertCount(1, $slowIssues, 'the 25 per-page findings must collapse into one site-wide issue');
        $this->assertSame(25, $slowIssues->first()->meta['affected_page_count']);
        $this->assertNull($slowIssues->first()->auditPageId, 'a multi-page issue must not point at a single arbitrary page');
        $this->assertSame(95, $result->score); // 100 - 5 (one Medium deduction, not 25)
    }

    private function pageWithMetrics(Audit $audit, array $metricAttributes): AuditPage
    {
        $page = AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'load_time_ms' => 500,
            'html_size_bytes' => 10_000,
            'images' => [],
        ]);

        PagePerformanceMetric::create(array_merge([
            'audit_id' => $audit->id,
            'audit_page_id' => $page->id,
            'source' => MetricSource::Crawler->value,
            'strategy' => MetricStrategy::Desktop->value,
        ], $metricAttributes));

        return $page;
    }

    public function test_resource_breakdown_flags_oversized_javascript_css_and_images(): void
    {
        $audit = $this->makeAudit();
        $this->pageWithMetrics($audit, [
            'by_type' => [
                'js' => ['requests' => 40, 'bytes' => 2 * 1024 * 1024],
                'css' => ['requests' => 10, 'bytes' => 400 * 1024],
                'image' => ['requests' => 30, 'bytes' => 3 * 1024 * 1024],
                'font' => ['requests' => 8, 'bytes' => 200 * 1024],
            ],
            'total_requests' => 120,
            'total_transfer_bytes' => 6 * 1024 * 1024,
        ]);

        $result = (new PerformanceAuditModule($this->pageSpeedServiceReturning(null)))->run($audit->fresh());
        $codes = collect($result->issues)->pluck('code');

        $this->assertTrue($codes->contains('performance.large_javascript'));
        $this->assertTrue($codes->contains('performance.large_css'));
        $this->assertTrue($codes->contains('performance.heavy_images'));
        $this->assertTrue($codes->contains('performance.too_many_fonts'));
        $this->assertTrue($codes->contains('performance.too_many_requests'));
        $this->assertTrue($codes->contains('performance.heavy_page_weight'));
    }

    public function test_resource_issues_carry_the_measured_value_and_the_budget_as_evidence(): void
    {
        $audit = $this->makeAudit();
        $this->pageWithMetrics($audit, [
            'by_type' => ['js' => ['requests' => 40, 'bytes' => 2 * 1024 * 1024]],
        ]);

        $result = (new PerformanceAuditModule($this->pageSpeedServiceReturning(null)))->run($audit->fresh());
        $issue = collect($result->issues)->firstWhere('code', 'performance.large_javascript');

        $this->assertSame(2 * 1024 * 1024, $issue->meta['bytes'], 'the real measured value must be the evidence');
        $this->assertSame(500 * 1024, $issue->meta['budget_bytes'], 'the threshold it was compared against is shown too');
    }

    public function test_slow_server_response_and_excessive_dom_are_flagged_from_measured_values(): void
    {
        $audit = $this->makeAudit();
        $this->pageWithMetrics($audit, ['ttfb_ms' => 2500, 'dom_nodes' => 5000]);

        $result = (new PerformanceAuditModule($this->pageSpeedServiceReturning(null)))->run($audit->fresh());
        $codes = collect($result->issues)->pluck('code');

        $this->assertTrue($codes->contains('performance.slow_server_response'));
        $this->assertTrue($codes->contains('performance.excessive_dom_size'));
    }

    public function test_a_page_with_no_crawler_metrics_raises_no_resource_issues(): void
    {
        $audit = $this->makeAudit();
        AuditPage::create([
            'audit_id' => $audit->id, 'url' => 'https://example.com/', 'http_status' => 200,
            'load_time_ms' => 500, 'html_size_bytes' => 10_000, 'images' => [],
        ]);

        $result = (new PerformanceAuditModule($this->pageSpeedServiceReturning(null)))->run($audit->fresh());

        // Absent measurements must produce no findings at all, rather than
        // being treated as zeros that trivially pass every budget.
        $this->assertCount(0, $result->issues);
    }

    /**
     * The core data-integrity guarantee: mobile and desktop are stored as
     * two separate rows, so one can never be displayed as the other.
     */
    public function test_mobile_and_desktop_pagespeed_results_are_stored_separately(): void
    {
        $audit = $this->makeAudit();
        $page = AuditPage::create([
            'audit_id' => $audit->id, 'url' => 'https://example.com/', 'http_status' => 200,
            'load_time_ms' => 500, 'html_size_bytes' => 10_000, 'images' => [],
        ]);

        $service = $this->pageSpeedServiceReturning(
            ['score' => 42, 'lcpMs' => 4200.0, 'cls' => 0.2, 'tbtMs' => 700.0, 'fcpMs' => 2100.0],
            ['score' => 91, 'lcpMs' => 1800.0, 'cls' => 0.02, 'tbtMs' => 120.0, 'fcpMs' => 900.0],
        );

        (new PerformanceAuditModule($service))->run($audit->fresh());

        $mobile = PagePerformanceMetric::where('audit_page_id', $page->id)
            ->where('source', MetricSource::PageSpeed->value)->where('strategy', 'mobile')->first();
        $desktop = PagePerformanceMetric::where('audit_page_id', $page->id)
            ->where('source', MetricSource::PageSpeed->value)->where('strategy', 'desktop')->first();

        $this->assertNotNull($mobile);
        $this->assertNotNull($desktop);
        $this->assertSame(42, $mobile->score);
        $this->assertSame(91, $desktop->score);
        $this->assertSame(4200, $mobile->lcp_ms);
        $this->assertSame(1800, $desktop->lcp_ms);
    }

    public function test_issues_are_driven_by_the_mobile_result_not_desktop(): void
    {
        $audit = $this->makeAudit();
        AuditPage::create([
            'audit_id' => $audit->id, 'url' => 'https://example.com/', 'http_status' => 200,
            'load_time_ms' => 500, 'html_size_bytes' => 10_000, 'images' => [],
        ]);

        // Mobile is poor, desktop is excellent. Mobile is the primary
        // priority, so the issue must reflect mobile's 30 — reporting
        // desktop's 95 here would hide a real problem.
        $service = $this->pageSpeedServiceReturning(
            ['score' => 30, 'lcpMs' => null, 'cls' => null, 'tbtMs' => null, 'fcpMs' => null],
            ['score' => 95, 'lcpMs' => null, 'cls' => null, 'tbtMs' => null, 'fcpMs' => null],
        );

        $result = (new PerformanceAuditModule($service))->run($audit->fresh());
        $issue = collect($result->issues)->firstWhere('code', 'performance.pagespeed_score');

        $this->assertNotNull($issue);
        $this->assertSame('critical', $issue->severity->value);
        $this->assertSame(30, $issue->meta['score']);
    }

    public function test_a_null_lighthouse_score_does_not_become_a_zero_issue(): void
    {
        $audit = $this->makeAudit();
        AuditPage::create([
            'audit_id' => $audit->id, 'url' => 'https://example.com/', 'http_status' => 200,
            'load_time_ms' => 500, 'html_size_bytes' => 10_000, 'images' => [],
        ]);

        $result = (new PerformanceAuditModule($this->pageSpeedServiceReturning([
            'score' => null, 'lcpMs' => null, 'cls' => null, 'tbtMs' => null, 'fcpMs' => null,
        ])))->run($audit->fresh());

        $this->assertFalse(
            collect($result->issues)->pluck('code')->contains('performance.pagespeed_score'),
            'a missing score is not a score of 0 and must not be flagged as critical',
        );
    }

    public function test_pagespeed_only_called_for_configured_page_count(): void
    {
        config(['audit_scoring.performance.pagespeed_pages' => 1]);

        $audit = $this->makeAudit();

        AuditPage::create(['audit_id' => $audit->id, 'url' => 'https://example.com/', 'http_status' => 200, 'load_time_ms' => 500, 'html_size_bytes' => 1000, 'images' => []]);
        AuditPage::create(['audit_id' => $audit->id, 'url' => 'https://example.com/about', 'http_status' => 200, 'load_time_ms' => 500, 'html_size_bytes' => 1000, 'images' => []]);

        $mock = Mockery::mock(PageSpeedService::class);
        $mock->shouldReceive('analyzeBoth')->once()->andReturn([]); // only the first page

        (new PerformanceAuditModule($mock))->run($audit->fresh());

        // Mockery's ->once() expectation is verified automatically on
        // tearDown; reaching here without a Mockery exception is the assertion.
        $this->assertTrue(true);
    }
}
