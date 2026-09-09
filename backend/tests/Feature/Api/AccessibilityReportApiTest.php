<?php

namespace Tests\Feature\Api;

use App\Enums\AuditCategory;
use App\Enums\IssueSeverity;
use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\AuditPage;
use App\Models\AuditScore;
use App\Models\Plan;
use App\Models\Report;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;
use App\Services\Plan\EntitlementResolver;
use App\Services\Report\BrandingResolver;
use App\Services\Report\ReportBuilder;
use App\Services\Report\ReportVisibilityFilter;
use App\Services\Report\ServiceReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * How the accessibility assessment reaches a reader (§43): the eight
 * report fields, the WCAG citations, and the disclaimer that must survive
 * every visibility tier.
 */
class AccessibilityReportApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Audit $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'x']);
        Subscription::create([
            'user_id' => $this->owner->id,
            'plan_id' => Plan::create([
                'name' => 'Owner plan', 'slug' => 'owner-plan', 'price_cents' => 0,
                'max_pages' => 25, 'daily_audit_limit' => 10, 'report_visibility_percent' => 100,
            ])->id,
            'started_at' => now(),
        ]);

        $website = Website::create([
            'user_id' => $this->owner->id, 'primary_url' => 'https://example.com',
            'domain' => 'example.com', 'normalized_domain' => 'example.com',
        ]);

        $this->audit = Audit::create([
            'website_id' => $website->id, 'user_id' => $this->owner->id,
            'status' => 'completed', 'max_pages' => 5, 'pages_crawled' => 2, 'completed_at' => now(),
        ]);

        AuditScore::create(['audit_id' => $this->audit->id, 'category' => 'overall', 'score' => 62]);
        AuditScore::create([
            'audit_id' => $this->audit->id, 'category' => 'accessibility', 'score' => 62,
            'breakdown' => [
                'coverage' => [
                    'pages_crawled' => 2,
                    'pages_scanned' => 1,
                    'passed_checks' => 24,
                    'passed_rules' => ['html-has-lang', 'image-alt'],
                    'needs_review' => ['color-contrast'],
                ],
                'checks' => [
                    ['check' => 'contrast', 'label' => 'Contrast', 'why_it_matters' => 'because', 'issue_count' => 1, 'element_count' => 4, 'passed' => false],
                    ['check' => 'tables', 'label' => 'Tables', 'why_it_matters' => 'because', 'issue_count' => 0, 'element_count' => 0, 'passed' => true],
                ],
                'disclaimer' => config('accessibility.disclaimer'),
            ],
        ]);

        $page = AuditPage::create([
            'audit_id' => $this->audit->id, 'url' => 'https://example.com/a',
            'http_status' => 200, 'title' => 'Page A',
        ]);

        AuditIssue::create([
            'audit_id' => $this->audit->id, 'audit_page_id' => $page->id,
            'category' => AuditCategory::Accessibility, 'severity' => IssueSeverity::High,
            'code' => 'axe.color-contrast', 'message' => 'Elements must meet contrast thresholds',
            'recommendation' => 'Raise the contrast ratio.',
            'meta' => [
                'check' => 'contrast', 'check_label' => 'Contrast',
                'why_it_matters' => 'Low contrast text is unreadable for people with low vision.',
                'wcag' => ['criteria' => ['1.4.3'], 'level' => 'AA', 'is_best_practice' => false],
                'wcag_label' => 'WCAG 1.4.3 (Level AA)',
                'is_best_practice' => false,
                'affected_pages' => ['https://example.com/a'],
                'affected_page_count' => 1,
                'affected_element_count' => 4,
                'elements' => [[
                    'page_url' => 'https://example.com/a', 'selector' => '.hero p',
                    'html' => '<p>hi</p>', 'evidence' => 'contrast of 2.4:1',
                ]],
            ],
        ]);

        AuditIssue::create([
            'audit_id' => $this->audit->id, 'audit_page_id' => $page->id,
            'category' => AuditCategory::Accessibility, 'severity' => IssueSeverity::Low,
            'code' => 'axe.region', 'message' => 'All content should be in landmarks',
            'recommendation' => 'Wrap content in landmarks.',
            'meta' => [
                'check' => 'landmarks', 'check_label' => 'Landmarks',
                'why_it_matters' => 'Screen reader users navigate by landmark.',
                'wcag' => ['criteria' => [], 'level' => null, 'is_best_practice' => true],
                'wcag_label' => null,
                'is_best_practice' => true,
                'affected_pages' => ['https://example.com/a'],
                'affected_page_count' => 1,
                'affected_element_count' => 1,
                'elements' => [],
            ],
        ]);

        Report::create([
            'audit_id' => $this->audit->id,
            'content' => ['overview' => [], 'issues' => [], 'recommendations' => [], 'ai_analysis' => [], 'pages' => []],
            'generated_at' => now(),
        ]);
    }

    private function ownerToken(): string
    {
        return $this->owner->createToken('api')->plainTextToken;
    }

    private function ownerReport(): array
    {
        return $this->withHeader('Authorization', "Bearer {$this->ownerToken()}")
            ->getJson("/api/audits/{$this->audit->id}/report/services")
            ->json('data.services.accessibility');
    }

    public function test_the_report_carries_the_eight_summary_fields_the_spec_names(): void
    {
        $summary = $this->ownerReport()['accessibility']['summary'];

        $this->assertSame(62, $summary['score']);
        $this->assertSame(0, $summary['critical_issues']);
        $this->assertSame(1, $summary['high_issues']);
        $this->assertSame(0, $summary['medium_issues']);
        $this->assertSame(1, $summary['low_issues']);
        $this->assertSame(24, $summary['passed_checks']);
        $this->assertSame(1, $summary['affected_pages']);
        $this->assertSame(5, $summary['affected_elements']);
    }

    public function test_the_report_says_when_the_scan_did_not_cover_every_page(): void
    {
        $coverage = $this->ownerReport()['accessibility']['coverage'];

        // Two pages crawled, one scanned. Presenting this as a finished
        // assessment of the whole site would be the report's own claim,
        // not the scanner's.
        $this->assertTrue($coverage['is_partial']);
        $this->assertSame(2, $coverage['pages_crawled']);
        $this->assertSame(1, $coverage['pages_scanned']);
        $this->assertSame(1, $coverage['needs_review_count']);
    }

    public function test_wcag_criteria_are_listed_and_best_practice_findings_add_none(): void
    {
        $criteria = $this->ownerReport()['accessibility']['wcag_criteria'];

        // The contrast issue cites 1.4.3; the landmark issue is a best
        // practice and must not manufacture a criterion of its own.
        $this->assertCount(1, $criteria);
        $this->assertSame('1.4.3', $criteria[0]['criterion']);
        $this->assertSame('AA', $criteria[0]['level']);
    }

    public function test_issue_evidence_reaches_the_owner_intact(): void
    {
        $issues = collect($this->ownerReport()['issues'])->keyBy('code');
        $contrast = $issues['axe.color-contrast']['evidence'];

        $this->assertSame('WCAG 1.4.3 (Level AA)', $contrast['wcag_label']);
        $this->assertSame('.hero p', $contrast['elements'][0]['selector']);
        $this->assertSame('contrast of 2.4:1', $contrast['elements'][0]['evidence']);
    }

    public function test_the_owner_report_carries_the_disclaimer(): void
    {
        $disclaimer = $this->ownerReport()['accessibility']['disclaimer'];

        $this->assertStringContainsString('automated accessibility assessment', strtolower($disclaimer['summary']));
        $this->assertStringContainsString('not a legal compliance audit', strtolower($disclaimer['summary']));
    }

    public function test_an_anonymous_viewer_sees_the_score_and_the_disclaimer_with_it(): void
    {
        $service = $this->getJson("/api/audits/{$this->audit->id}/report/services")
            ->json('data.services.accessibility');

        // The free view is exactly where a bare number is most likely to
        // be screenshotted as proof of compliance, so the caveat is not
        // withheld as paid detail.
        $this->assertSame(62, $service['score']);
        $this->assertNotEmpty($service['accessibility']['disclaimer']['summary']);
        $this->assertSame(24, $service['accessibility']['summary']['passed_checks']);
        $this->assertTrue($service['accessibility']['coverage']['is_partial']);
    }

    public function test_an_anonymous_viewer_does_not_get_the_paid_accessibility_detail(): void
    {
        $service = $this->getJson("/api/audits/{$this->audit->id}/report/services")
            ->json('data.services.accessibility');

        $this->assertSame([], $service['accessibility']['wcag_criteria']);
        // Area names and counts stay — the shape of the findings without
        // the findings themselves.
        $this->assertArrayNotHasKey('why_it_matters', $service['accessibility']['checks'][0]);
        $this->assertSame(1, $service['accessibility']['checks'][0]['issue_count']);
    }

    public function test_the_combined_report_carries_the_disclaimer_too(): void
    {
        // Built the way GenerateReportJob builds it, rather than read back
        // from a fixture row — the point is what gets *stored*.
        $content = app(ReportBuilder::class)->build($this->audit->fresh());

        $this->assertStringContainsString(
            'not a legal compliance audit',
            strtolower($content['disclaimers']['accessibility']['summary']),
        );
    }

    public function test_a_restricted_viewer_of_the_combined_report_still_gets_the_disclaimer(): void
    {
        $content = app(ReportBuilder::class)->build($this->audit->fresh());

        $filtered = app(ReportVisibilityFilter::class)->filter(
            $content,
            app(EntitlementResolver::class)->for(null),
        );

        $this->assertFalse($filtered['is_full_access']);
        $this->assertNotEmpty($filtered['disclaimers']['accessibility']['summary']);
    }

    public function test_the_service_pdf_template_renders_the_disclaimer_and_coverage(): void
    {
        $report = app(ServiceReportBuilder::class)->buildAll($this->audit->fresh())['accessibility'];

        $html = view('reports.service-pdf', [
            'audit' => $this->audit->fresh(),
            'report' => $report,
            // From the real resolver, as production does — otherwise the
            // template's branding block is never exercised by any test.
            'branding' => app(BrandingResolver::class)->forAudit($this->audit->fresh()),
        ])->render();

        // The PDF is the artifact most likely to be forwarded as evidence,
        // so the caveat has to be in the document itself, not only on the
        // web page it was exported from.
        $this->assertStringContainsString('Automated accessibility assessment', $html);
        $this->assertStringContainsString('not a legal compliance audit', $html);
        $this->assertStringContainsString('It is not a conformance rating', $html);
        $this->assertStringContainsString('Accessibility check coverage', $html);
        // Never "accessible" for an area a scan merely found nothing in.
        $this->assertStringContainsString('No automated issues found', $html);
        $this->assertStringNotContainsString('Compliant', $html);
    }

    public function test_an_audit_without_an_accessibility_score_disclaims_nothing(): void
    {
        AuditScore::where('audit_id', $this->audit->id)->where('category', 'accessibility')->delete();

        $content = app(ReportBuilder::class)->build($this->audit->fresh());

        // A caveat about a scan that never ran would imply one did.
        $this->assertSame([], $content['disclaimers']);
    }
}
