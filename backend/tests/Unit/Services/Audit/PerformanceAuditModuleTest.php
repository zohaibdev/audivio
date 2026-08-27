<?php

namespace Tests\Unit\Services\Audit;

use App\Models\Audit;
use App\Models\AuditPage;
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

    private function pageSpeedServiceReturning(?array $result): PageSpeedService
    {
        $mock = Mockery::mock(PageSpeedService::class);
        $mock->shouldReceive('analyze')->andReturn($result);

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

    public function test_pagespeed_only_called_for_configured_page_count(): void
    {
        config(['audit_scoring.performance.pagespeed_pages' => 1]);

        $audit = $this->makeAudit();

        AuditPage::create(['audit_id' => $audit->id, 'url' => 'https://example.com/', 'http_status' => 200, 'load_time_ms' => 500, 'html_size_bytes' => 1000, 'images' => []]);
        AuditPage::create(['audit_id' => $audit->id, 'url' => 'https://example.com/about', 'http_status' => 200, 'load_time_ms' => 500, 'html_size_bytes' => 1000, 'images' => []]);

        $mock = Mockery::mock(PageSpeedService::class);
        $mock->shouldReceive('analyze')->once()->andReturn(null); // only the first page

        (new PerformanceAuditModule($mock))->run($audit->fresh());

        // Mockery's ->once() expectation is verified automatically on
        // tearDown; reaching here without a Mockery exception is the assertion.
        $this->assertTrue(true);
    }
}
