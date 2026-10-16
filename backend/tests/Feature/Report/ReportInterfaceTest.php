<?php

namespace Tests\Feature\Report;

use App\Enums\AccessibilityCheck;
use App\Enums\AuditCategory;
use App\Enums\IssueSeverity;
use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\AuditPage;
use App\Models\AuditScore;
use App\Models\Website;
use App\Services\Plan\Entitlements;
use App\Services\Report\IssueExplanations;
use App\Services\Report\PageExplorerBuilder;
use App\Services\Report\ServiceReportBuilder;
use App\Services\Report\ServiceReportVisibilityFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the report interface needs from the backend (§47): a per-page
 * score to sort and filter on, and a stated reason for every finding.
 */
class ReportInterfaceTest extends TestCase
{
    use RefreshDatabase;

    private Audit $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $website = Website::create([
            'name' => 'example.com', 'primary_url' => 'https://example.com/',
            'domain' => 'example.com', 'normalized_domain' => 'example.com',
            'platform' => 'unknown',
        ]);

        $this->audit = Audit::create([
            'website_id' => $website->id, 'status' => 'completed',
            'max_pages' => 5, 'pages_crawled' => 2, 'completed_at' => now(),
        ]);
    }

    private function addPage(string $url): AuditPage
    {
        return AuditPage::create([
            'audit_id' => $this->audit->id, 'url' => $url,
            'http_status' => 200, 'title' => 'Page',
        ]);
    }

    private function addIssue(AuditPage $page, IssueSeverity $severity, string $code = 'seo.missing_title'): AuditIssue
    {
        return AuditIssue::create([
            'audit_id' => $this->audit->id,
            'audit_page_id' => $page->id,
            'category' => AuditCategory::Seo,
            'severity' => $severity,
            'code' => $code,
            'message' => 'Something is wrong',
            'recommendation' => 'Fix it.',
        ]);
    }

    // --- Page score ---

    public function test_a_page_score_uses_the_same_severity_weights_as_the_category_scores(): void
    {
        $page = $this->addPage('https://example.com/');
        $this->addIssue($page, IssueSeverity::Critical); // 20
        $this->addIssue($page, IssueSeverity::High);     // 10
        $this->addIssue($page, IssueSeverity::Low);      // 2

        $rows = app(PageExplorerBuilder::class)->build($this->audit->fresh());

        // A page scoring 68 here means the same kind of thing as a
        // category scoring 68 — that is the point of sharing the weights.
        $this->assertSame(68, $rows[0]['score']);
    }

    public function test_a_page_with_no_issues_scores_100(): void
    {
        $this->addPage('https://example.com/');

        $this->assertSame(100, app(PageExplorerBuilder::class)->build($this->audit->fresh())[0]['score']);
    }

    public function test_a_page_score_never_goes_below_zero(): void
    {
        $page = $this->addPage('https://example.com/');

        for ($i = 0; $i < 10; $i++) {
            $this->addIssue($page, IssueSeverity::Critical);
        }

        // 200 points of deductions on a 100-point scale. A negative score
        // would sort below a page with nothing measured at all.
        $this->assertSame(0, app(PageExplorerBuilder::class)->build($this->audit->fresh())[0]['score']);
    }

    public function test_each_page_is_scored_from_its_own_issues(): void
    {
        $bad = $this->addPage('https://example.com/bad');
        $good = $this->addPage('https://example.com/good');
        $this->addIssue($bad, IssueSeverity::Critical);

        $rows = collect(app(PageExplorerBuilder::class)->build($this->audit->fresh()))->keyBy('url');

        $this->assertSame(80, $rows['https://example.com/bad']['score']);
        $this->assertSame(100, $rows['https://example.com/good']['score']);
    }

    // --- Why it matters ---

    public function test_every_finding_carries_a_reason(): void
    {
        $explanations = app(IssueExplanations::class);

        foreach ([
            [AuditCategory::Seo, 'seo.missing_title'],
            [AuditCategory::Performance, 'performance.lcp'],
            [AuditCategory::Conversion, 'conversion.no_social_proof'],
            [AuditCategory::Ecommerce, 'ecommerce.missing_returns_information'],
            [AuditCategory::Accessibility, 'axe.color-contrast'],
        ] as [$category, $code]) {
            $reason = $explanations->forIssue($category, $code);

            $this->assertGreaterThan(60, strlen($reason), "{$code} reason is too thin");
        }
    }

    public function test_an_unmapped_code_falls_back_to_its_category_rather_than_silence(): void
    {
        $reason = app(IssueExplanations::class)->forIssue(AuditCategory::Seo, 'seo.some_future_check');

        // A finding with no stated reason invites the reader to dismiss
        // it, so silence is never the answer.
        $this->assertNotSame('', $reason);
        $this->assertStringContainsString('Search engines', $reason);
    }

    public function test_accessibility_reasons_come_from_the_check_definitions(): void
    {
        $reason = app(IssueExplanations::class)->forIssue(AuditCategory::Accessibility, 'axe.color-contrast');

        // Delegated rather than duplicated, so the issue list and the §43
        // accessibility rollup can never disagree.
        $this->assertSame(
            AccessibilityCheck::forAxeRule('color-contrast')->whyItMatters(),
            $reason,
        );
    }

    public function test_the_reason_reaches_the_serialized_issue(): void
    {
        AuditScore::create(['audit_id' => $this->audit->id, 'category' => 'seo', 'score' => 60]);
        $page = $this->addPage('https://example.com/');
        $this->addIssue($page, IssueSeverity::High, 'seo.missing_meta_description');

        $issue = app(ServiceReportBuilder::class)->buildAll($this->audit->fresh())['seo']['issues'][0];

        $this->assertStringContainsString('sales copy', $issue['why_it_matters']);
    }

    public function test_a_free_viewer_still_gets_the_reason(): void
    {
        AuditScore::create(['audit_id' => $this->audit->id, 'category' => 'seo', 'score' => 60]);
        $page = $this->addPage('https://example.com/');
        $this->addIssue($page, IssueSeverity::Critical, 'seo.missing_title');

        $report = app(ServiceReportBuilder::class)->buildAll($this->audit->fresh())['seo'];

        $filtered = app(ServiceReportVisibilityFilter::class)->filter(
            $report,
            Entitlements::fromGuestConfig(config('entitlements.guest')),
        );

        $issue = $filtered['issues'][0];

        // The fix is withheld; the reason is not. A viewer who can see a
        // finding but not why it matters has been handed a complaint.
        $this->assertNotEmpty($issue['why_it_matters']);
        $this->assertArrayNotHasKey('recommendation', $issue);
    }
}
