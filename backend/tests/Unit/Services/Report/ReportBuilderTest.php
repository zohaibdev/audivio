<?php

namespace Tests\Unit\Services\Report;

use App\Models\AiAnalysis;
use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\AuditPage;
use App\Models\AuditScore;
use App\Models\Website;
use App\Services\Report\ReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function makeAudit(): Audit
    {
        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com', 'platform' => 'wordpress']);

        return Audit::create([
            'website_id' => $website->id,
            'status' => 'completed',
            'max_pages' => 5,
            'pages_crawled' => 1,
            'completed_at' => now(),
        ]);
    }

    public function test_overview_includes_website_info_and_scores(): void
    {
        $audit = $this->makeAudit();
        AuditScore::create(['audit_id' => $audit->id, 'category' => 'seo', 'score' => 80]);
        AuditScore::create(['audit_id' => $audit->id, 'category' => 'overall', 'score' => 80]);

        $content = (new ReportBuilder)->build($audit->fresh());

        $this->assertSame('https://example.com', $content['overview']['website_url']);
        $this->assertSame('example.com', $content['overview']['domain']);
        $this->assertSame('wordpress', $content['overview']['platform']);
        $this->assertSame(80, $content['overview']['overall_score']);
        $this->assertSame(['seo' => 80], $content['overview']['category_scores']);
    }

    public function test_overall_score_is_excluded_from_category_scores(): void
    {
        $audit = $this->makeAudit();
        AuditScore::create(['audit_id' => $audit->id, 'category' => 'seo', 'score' => 80]);
        AuditScore::create(['audit_id' => $audit->id, 'category' => 'overall', 'score' => 80]);

        $content = (new ReportBuilder)->build($audit->fresh());

        $this->assertArrayNotHasKey('overall', $content['overview']['category_scores']);
    }

    public function test_issues_are_serialized_with_derived_priority(): void
    {
        $audit = $this->makeAudit();
        $page = AuditPage::create(['audit_id' => $audit->id, 'url' => 'https://example.com/', 'http_status' => 200]);
        AuditIssue::create(['audit_id' => $audit->id, 'audit_page_id' => $page->id, 'category' => 'seo', 'severity' => 'critical', 'code' => 'x', 'message' => 'Critical thing']);
        AuditIssue::create(['audit_id' => $audit->id, 'category' => 'seo', 'severity' => 'low', 'code' => 'y', 'message' => 'Minor thing']);

        $content = (new ReportBuilder)->build($audit->fresh());

        $critical = collect($content['issues'])->firstWhere('code', 'x');
        $this->assertSame('high', $critical['priority']);
        $this->assertSame('https://example.com/', $critical['page_url']);

        $low = collect($content['issues'])->firstWhere('code', 'y');
        $this->assertSame('low', $low['priority']);
        $this->assertNull($low['page_url']);
    }

    public function test_recommendations_only_include_issues_with_a_recommendation_worst_first(): void
    {
        $audit = $this->makeAudit();
        AuditIssue::create(['audit_id' => $audit->id, 'category' => 'seo', 'severity' => 'low', 'code' => 'a', 'message' => 'a', 'recommendation' => 'Fix a']);
        AuditIssue::create(['audit_id' => $audit->id, 'category' => 'seo', 'severity' => 'critical', 'code' => 'b', 'message' => 'b', 'recommendation' => 'Fix b']);
        AuditIssue::create(['audit_id' => $audit->id, 'category' => 'seo', 'severity' => 'high', 'code' => 'c', 'message' => 'c']); // no recommendation

        $content = (new ReportBuilder)->build($audit->fresh());

        $this->assertCount(2, $content['recommendations']);
        $this->assertSame('Fix b', $content['recommendations'][0]['recommendation']); // critical first
        $this->assertSame('Fix a', $content['recommendations'][1]['recommendation']);
    }

    public function test_ai_analysis_only_includes_successful_responses(): void
    {
        $audit = $this->makeAudit();
        AiAnalysis::create(['audit_id' => $audit->id, 'category' => 'aio', 'provider' => 'claude', 'context_hash' => 'a', 'prompt' => 'x', 'response' => 'AIO findings text']);
        AiAnalysis::create(['audit_id' => $audit->id, 'category' => 'conversion', 'provider' => 'claude', 'context_hash' => 'b', 'prompt' => 'x', 'error' => 'no key configured']);

        $content = (new ReportBuilder)->build($audit->fresh());

        $this->assertSame(['aio' => 'AIO findings text'], $content['ai_analysis']);
    }

    public function test_page_findings_include_per_page_issue_counts(): void
    {
        $audit = $this->makeAudit();
        $page = AuditPage::create(['audit_id' => $audit->id, 'url' => 'https://example.com/', 'http_status' => 200, 'title' => 'Home']);
        AuditIssue::create(['audit_id' => $audit->id, 'audit_page_id' => $page->id, 'category' => 'seo', 'severity' => 'medium', 'code' => 'x', 'message' => 'x']);
        AuditIssue::create(['audit_id' => $audit->id, 'audit_page_id' => $page->id, 'category' => 'seo', 'severity' => 'low', 'code' => 'y', 'message' => 'y']);

        $content = (new ReportBuilder)->build($audit->fresh());

        $this->assertCount(1, $content['pages']);
        $this->assertSame('https://example.com/', $content['pages'][0]['url']);
        $this->assertSame(2, $content['pages'][0]['issue_count']);
    }
}
