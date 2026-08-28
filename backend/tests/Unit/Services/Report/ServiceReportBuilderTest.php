<?php

namespace Tests\Unit\Services\Report;

use App\Enums\AuditCategory;
use App\Enums\IssueSeverity;
use App\Enums\ScreenshotViewport;
use App\Models\AiAnalysis;
use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\AuditPage;
use App\Models\AuditScore;
use App\Models\Screenshot;
use App\Models\Website;
use App\Services\Report\ServiceReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceReportBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function makeAudit(): Audit
    {
        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com']);

        return Audit::create([
            'website_id' => $website->id,
            'status' => 'completed',
            'max_pages' => 5,
            'pages_crawled' => 2,
            'completed_at' => now(),
        ]);
    }

    private function builder(): ServiceReportBuilder
    {
        return app(ServiceReportBuilder::class);
    }

    public function test_a_service_with_no_score_row_is_omitted_entirely(): void
    {
        $audit = $this->makeAudit();
        AuditScore::create(['audit_id' => $audit->id, 'category' => 'seo', 'score' => 90]);

        $reports = $this->builder()->buildAll($audit);

        $this->assertArrayHasKey('seo', $reports);
        $this->assertArrayNotHasKey('performance', $reports, 'a module that never ran must not appear as an empty report');
        $this->assertArrayNotHasKey('accessibility', $reports);
    }

    public function test_a_not_applicable_service_keeps_that_state_instead_of_a_number(): void
    {
        $audit = $this->makeAudit();
        AuditScore::create([
            'audit_id' => $audit->id, 'category' => 'ecommerce',
            'score' => null, 'is_applicable' => false,
        ]);

        $report = $this->builder()->buildAll($audit)['ecommerce'];

        $this->assertNull($report['score']);
        $this->assertFalse($report['is_applicable']);
        $this->assertSame('not_applicable', $report['status']);
        $this->assertNotNull($report['not_applicable_reason']);
    }

    public function test_issues_are_grouped_into_categories_within_their_service(): void
    {
        $audit = $this->makeAudit();
        AuditScore::create(['audit_id' => $audit->id, 'category' => 'seo', 'score' => 70]);

        AuditIssue::create([
            'audit_id' => $audit->id, 'category' => AuditCategory::Seo, 'severity' => IssueSeverity::High,
            'code' => 'seo.missing_meta_description', 'message' => 'Missing meta description',
        ]);
        AuditIssue::create([
            'audit_id' => $audit->id, 'category' => AuditCategory::Seo, 'severity' => IssueSeverity::Critical,
            'code' => 'seo.missing_title', 'message' => 'Missing title',
        ]);
        AuditIssue::create([
            'audit_id' => $audit->id, 'category' => AuditCategory::Seo, 'severity' => IssueSeverity::Medium,
            'code' => 'seo.missing_h1', 'message' => 'Missing H1',
        ]);

        $report = $this->builder()->buildAll($audit)['seo'];
        $categories = collect($report['categories'])->keyBy('name');

        $this->assertTrue($categories->has('Metadata'));
        $this->assertTrue($categories->has('Headings'));
        $this->assertSame(2, $categories['Metadata']['issue_count'], 'title + meta description both belong to Metadata');
        $this->assertSame(1, $categories['Headings']['issue_count']);

        // Within a category, worst severity first.
        $this->assertSame('critical', $categories['Metadata']['issues'][0]['severity']);
    }

    public function test_severity_summary_counts_every_level(): void
    {
        $audit = $this->makeAudit();
        AuditScore::create(['audit_id' => $audit->id, 'category' => 'seo', 'score' => 70]);

        foreach ([IssueSeverity::Critical, IssueSeverity::High, IssueSeverity::High, IssueSeverity::Low] as $i => $severity) {
            AuditIssue::create([
                'audit_id' => $audit->id, 'category' => AuditCategory::Seo, 'severity' => $severity,
                'code' => "seo.code_{$i}", 'message' => 'x',
            ]);
        }

        $summary = $this->builder()->buildAll($audit)['seo']['severity_summary'];

        $this->assertSame(1, $summary['critical']);
        $this->assertSame(2, $summary['high']);
        $this->assertSame(1, $summary['low']);
        $this->assertSame(4, $summary['total']);
    }

    public function test_cro_merges_the_two_internal_categories_into_one_service_score(): void
    {
        $audit = $this->makeAudit();
        AuditScore::create(['audit_id' => $audit->id, 'category' => 'conversion', 'score' => 80]);
        AuditScore::create(['audit_id' => $audit->id, 'category' => 'landing_page', 'score' => 60]);

        AuditIssue::create([
            'audit_id' => $audit->id, 'category' => AuditCategory::LandingPage, 'severity' => IssueSeverity::High,
            'code' => 'landing_page.no_cta', 'message' => 'No CTA',
        ]);
        AuditIssue::create([
            'audit_id' => $audit->id, 'category' => AuditCategory::Conversion, 'severity' => IssueSeverity::Medium,
            'code' => 'conversion.form_friction', 'message' => 'Too many fields',
        ]);

        $report = $this->builder()->buildAll($audit)['cro'];

        $this->assertSame(70, $report['score'], 'CRO score is the mean of conversion and landing_page, not one of them arbitrarily');
        $this->assertSame(2, $report['severity_summary']['total'], 'both internal categories feed the one CRO report');

        $categories = collect($report['categories'])->pluck('name');
        $this->assertTrue($categories->contains('CTA'));
        $this->assertTrue($categories->contains('Forms'));
    }

    public function test_page_findings_include_both_screenshots_and_only_this_services_issues(): void
    {
        $audit = $this->makeAudit();
        AuditScore::create(['audit_id' => $audit->id, 'category' => 'seo', 'score' => 70]);
        AuditScore::create(['audit_id' => $audit->id, 'category' => 'performance', 'score' => 80]);

        $page = AuditPage::create([
            'audit_id' => $audit->id, 'url' => 'https://example.com/a',
            'http_status' => 200, 'title' => 'Page A',
        ]);

        AuditIssue::create([
            'audit_id' => $audit->id, 'audit_page_id' => $page->id, 'category' => AuditCategory::Seo,
            'severity' => IssueSeverity::High, 'code' => 'seo.missing_meta_description', 'message' => 'x',
        ]);
        AuditIssue::create([
            'audit_id' => $audit->id, 'audit_page_id' => $page->id, 'category' => AuditCategory::Performance,
            'severity' => IssueSeverity::Medium, 'code' => 'performance.slow_load_time', 'message' => 'y',
        ]);

        Screenshot::create([
            'audit_id' => $audit->id, 'audit_page_id' => $page->id,
            'viewport' => ScreenshotViewport::Desktop, 'width' => 1366, 'height' => 900,
            'storage_path' => 'screenshots/a-desktop.png',
        ]);
        Screenshot::create([
            'audit_id' => $audit->id, 'audit_page_id' => $page->id,
            'viewport' => ScreenshotViewport::Mobile, 'width' => 390, 'height' => 844,
            'storage_path' => 'screenshots/a-mobile.png',
        ]);

        $seoPages = $this->builder()->buildAll($audit)['seo']['pages'];

        $this->assertCount(1, $seoPages);
        $this->assertSame('https://example.com/a', $seoPages[0]['url']);
        $this->assertSame(1, $seoPages[0]['issue_count'], 'the performance issue must not leak into the SEO report');
        $this->assertSame('screenshots/a-desktop.png', $seoPages[0]['screenshots']['desktop']);
        $this->assertSame('screenshots/a-mobile.png', $seoPages[0]['screenshots']['mobile']);
    }

    public function test_a_failed_ai_provider_reads_as_unavailable_not_as_an_absent_finding(): void
    {
        $audit = $this->makeAudit();
        AuditScore::create(['audit_id' => $audit->id, 'category' => 'aio', 'score' => 85]);

        AiAnalysis::create([
            'audit_id' => $audit->id, 'category' => 'aio', 'provider' => 'unavailable',
            'context_hash' => str_repeat('a', 64), 'prompt' => 'x',
            'error' => 'No AI model configured/enabled is allowed by the current plan.',
        ]);

        $ai = $this->builder()->buildAll($audit)['aio']['ai'];

        $this->assertTrue($ai['used']);
        $this->assertFalse($ai['available']);
        $this->assertNull($ai['analysis']);
        $this->assertNotNull($ai['error']);
    }

    /**
     * Found against a real audit: CRO reported "100 — good" with zero
     * issues while its AI provider was unavailable, on the strength of a
     * single form-friction check. A high score from an assessment that
     * mostly did not run is a manufactured score, so partial coverage must
     * be stated rather than presented as a clean result.
     */
    public function test_an_ai_dependent_service_whose_ai_failed_is_marked_partial_not_good(): void
    {
        $audit = $this->makeAudit();
        AuditScore::create(['audit_id' => $audit->id, 'category' => 'conversion', 'score' => 100]);

        AiAnalysis::create([
            'audit_id' => $audit->id, 'category' => 'conversion', 'provider' => 'unavailable',
            'context_hash' => str_repeat('b', 64), 'prompt' => 'x',
            'error' => 'No AI model configured/enabled is allowed by the current plan.',
        ]);

        $report = $this->builder()->buildAll($audit)['cro'];

        $this->assertSame(100, $report['score'], 'the real deterministic result is kept, not discarded');
        $this->assertSame('partial', $report['confidence']);
        $this->assertSame('partial', $report['status'], 'must not read as "good" when most of the assessment never ran');
        $this->assertNotNull($report['coverage_note']);
        $this->assertStringContainsString('value proposition', $report['coverage_note'], 'the note names what was actually skipped');
    }

    public function test_a_service_with_working_ai_is_marked_complete(): void
    {
        $audit = $this->makeAudit();
        AuditScore::create(['audit_id' => $audit->id, 'category' => 'aio', 'score' => 95]);

        AiAnalysis::create([
            'audit_id' => $audit->id, 'category' => 'aio', 'provider' => 'claude',
            'context_hash' => str_repeat('c', 64), 'prompt' => 'x', 'response' => '[]',
        ]);

        $report = $this->builder()->buildAll($audit)['aio'];

        $this->assertSame('complete', $report['confidence']);
        $this->assertSame('good', $report['status']);
        $this->assertNull($report['coverage_note']);
    }

    public function test_a_service_with_no_ai_dependency_is_complete_without_an_ai_record(): void
    {
        $audit = $this->makeAudit();
        AuditScore::create(['audit_id' => $audit->id, 'category' => 'seo', 'score' => 95]);

        $report = $this->builder()->buildAll($audit)['seo'];

        $this->assertSame('complete', $report['confidence'], 'SEO is fully deterministic — no AI means no coverage gap');
        $this->assertSame('good', $report['status']);
    }

    public function test_ai_generated_issues_are_labelled_as_ai_detected(): void
    {
        $audit = $this->makeAudit();
        AuditScore::create(['audit_id' => $audit->id, 'category' => 'aio', 'score' => 85]);

        AuditIssue::create([
            'audit_id' => $audit->id, 'category' => AuditCategory::Aio, 'severity' => IssueSeverity::Medium,
            'code' => 'ai.aio.unclear_entity', 'message' => 'Entity is unclear',
        ]);
        AuditIssue::create([
            'audit_id' => $audit->id, 'category' => AuditCategory::Aio, 'severity' => IssueSeverity::Medium,
            'code' => 'aio.no_structured_data', 'message' => 'No structured data',
        ]);

        $issues = collect($this->builder()->buildAll($audit)['aio']['issues'])->keyBy('code');

        $this->assertSame('ai', $issues['ai.aio.unclear_entity']['detected_by']);
        $this->assertSame('rule', $issues['aio.no_structured_data']['detected_by']);
    }

    public function test_recommendations_only_come_from_issues_that_carry_one(): void
    {
        $audit = $this->makeAudit();
        AuditScore::create(['audit_id' => $audit->id, 'category' => 'seo', 'score' => 70]);

        AuditIssue::create([
            'audit_id' => $audit->id, 'category' => AuditCategory::Seo, 'severity' => IssueSeverity::High,
            'code' => 'seo.missing_meta_description', 'message' => 'x', 'recommendation' => 'Add one.',
        ]);
        AuditIssue::create([
            'audit_id' => $audit->id, 'category' => AuditCategory::Seo, 'severity' => IssueSeverity::Low,
            'code' => 'seo.title_length', 'message' => 'y', 'recommendation' => null,
        ]);

        $recommendations = $this->builder()->buildAll($audit)['seo']['recommendations'];

        $this->assertCount(1, $recommendations, 'an issue with no recommendation must not get a fabricated one');
        $this->assertSame('Add one.', $recommendations[0]['recommended_action']);
        $this->assertSame('High', $recommendations[0]['priority']);
        $this->assertSame('Low', $recommendations[0]['effort']);
    }
}
