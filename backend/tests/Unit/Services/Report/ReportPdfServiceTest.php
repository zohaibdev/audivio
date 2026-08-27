<?php

namespace Tests\Unit\Services\Report;

use App\Models\Audit;
use App\Models\Report;
use App\Models\Website;
use App\Services\Report\ReportPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportPdfServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_render_produces_a_valid_pdf_binary(): void
    {
        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'status' => 'completed', 'max_pages' => 5, 'pages_crawled' => 1]);
        $report = Report::create([
            'audit_id' => $audit->id,
            'generated_at' => now(),
            'content' => [
                'overview' => [
                    'website_url' => 'https://example.com',
                    'domain' => 'example.com',
                    'platform' => 'unknown',
                    'overall_score' => 82,
                    'category_scores' => ['seo' => 90, 'performance' => 74],
                    'pages_crawled' => 1,
                    'audit_date' => now()->toIso8601String(),
                ],
                'issues' => [
                    ['category' => 'seo', 'severity' => 'high', 'priority' => 'high', 'code' => 'missing_title', 'message' => 'Missing title tag', 'recommendation' => 'Add a title', 'page_url' => 'https://example.com/', 'meta' => null],
                ],
                'recommendations' => [
                    ['category' => 'seo', 'severity' => 'high', 'priority' => 'high', 'message' => 'Missing title tag', 'recommendation' => 'Add a title'],
                ],
                'ai_analysis' => ['aio' => 'The homepage lacks clear topical structure.'],
                'pages' => [
                    ['url' => 'https://example.com/', 'http_status' => 200, 'title' => 'Home', 'issue_count' => 1],
                ],
            ],
        ]);

        $pdf = (new ReportPdfService)->render($audit, $report);

        $this->assertIsString($pdf);
        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    public function test_render_handles_a_report_with_no_issues_or_ai_analysis(): void
    {
        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'status' => 'completed', 'max_pages' => 5, 'pages_crawled' => 1]);
        $report = Report::create([
            'audit_id' => $audit->id,
            'generated_at' => now(),
            'content' => [
                'overview' => [
                    'website_url' => 'https://example.com',
                    'domain' => 'example.com',
                    'platform' => 'unknown',
                    'overall_score' => 100,
                    'category_scores' => [],
                    'pages_crawled' => 1,
                    'audit_date' => null,
                ],
                'issues' => [],
                'recommendations' => [],
                'ai_analysis' => [],
                'pages' => [],
            ],
        ]);

        $pdf = (new ReportPdfService)->render($audit, $report);

        $this->assertStringStartsWith('%PDF-', $pdf);
    }
}
