<?php

namespace Tests\Feature\Api;

use App\Enums\AuditCategory;
use App\Enums\IssueSeverity;
use App\Enums\ScreenshotViewport;
use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\AuditPage;
use App\Models\AuditScore;
use App\Models\Plan;
use App\Models\Report;
use App\Models\Screenshot;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;
use App\Services\Report\BrandingResolver;
use App\Services\Report\ServiceReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceReportApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Audit $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'x']);
        // Registration always creates a subscription in production, and
        // since §40 an owner's own plan decides how much of their report
        // they see — an owner with no plan row at all falls back to the
        // guest view, which is not what this file is testing.
        Subscription::create([
            'user_id' => $this->owner->id,
            'plan_id' => Plan::create([
                'name' => 'Owner plan', 'slug' => 'owner-plan', 'price_cents' => 0,
                'max_pages' => 25, 'daily_audit_limit' => 10, 'report_visibility_percent' => 100,
            ])->id,
            'started_at' => now(),
        ]);
        $website = Website::create(['user_id' => $this->owner->id, 'primary_url' => 'https://example.com', 'domain' => 'example.com', 'normalized_domain' => 'example.com']);

        $this->audit = Audit::create([
            'website_id' => $website->id, 'user_id' => $this->owner->id,
            'status' => 'completed', 'max_pages' => 5, 'pages_crawled' => 1, 'completed_at' => now(),
        ]);

        AuditScore::create(['audit_id' => $this->audit->id, 'category' => 'seo', 'score' => 70]);
        AuditScore::create(['audit_id' => $this->audit->id, 'category' => 'overall', 'score' => 70]);
        AuditScore::create([
            'audit_id' => $this->audit->id, 'category' => 'ecommerce',
            'score' => null, 'is_applicable' => false,
        ]);

        $page = AuditPage::create([
            'audit_id' => $this->audit->id, 'url' => 'https://example.com/a',
            'http_status' => 200, 'title' => 'Page A',
        ]);

        AuditIssue::create([
            'audit_id' => $this->audit->id, 'audit_page_id' => $page->id, 'category' => AuditCategory::Seo,
            'severity' => IssueSeverity::Critical, 'code' => 'seo.missing_title',
            'message' => 'Missing title', 'recommendation' => 'Add a title.',
        ]);
        AuditIssue::create([
            'audit_id' => $this->audit->id, 'audit_page_id' => $page->id, 'category' => AuditCategory::Seo,
            'severity' => IssueSeverity::Low, 'code' => 'seo.title_length',
            'message' => 'Title too short', 'recommendation' => 'Lengthen it.',
        ]);

        Screenshot::create([
            'audit_id' => $this->audit->id, 'audit_page_id' => $page->id,
            'viewport' => ScreenshotViewport::Desktop, 'width' => 1366, 'height' => 900,
            'storage_path' => 'screenshots/a-desktop.png',
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

    public function test_owner_sees_full_service_reports_including_pages_and_screenshots(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->ownerToken()}")
            ->getJson("/api/audits/{$this->audit->id}/report/services");

        $response->assertStatus(200)->assertJsonPath('data.is_full_access', true);

        $seo = $response->json('data.services.seo');
        $this->assertSame(70, $seo['score']);
        $this->assertCount(2, $seo['issues'], 'the owner sees every severity, not just major ones');
        $this->assertCount(1, $seo['pages']);
        // A signed URL, not the raw storage path (§48): emitting the
        // path is what made screenshots walkable from a sequential audit
        // id.
        $this->assertStringContainsString('/api/screenshots/', $seo['pages'][0]['screenshots']['desktop']);
        $this->assertStringContainsString('signature=', $seo['pages'][0]['screenshots']['desktop']);
        $this->assertNull($seo['pages'][0]['screenshots']['mobile'], 'a missing screenshot reads as null, not a fabricated path');
    }

    public function test_anonymous_viewer_gets_scores_but_not_page_findings_or_low_severity_issues(): void
    {
        $response = $this->getJson("/api/audits/{$this->audit->id}/report/services");

        $response->assertStatus(200)->assertJsonPath('data.is_full_access', false);

        $seo = $response->json('data.services.seo');
        $this->assertSame(70, $seo['score'], 'the score itself stays visible so the audit is provably real');
        $this->assertSame(2, $seo['severity_summary']['total'], 'counts stay honest even when detail is withheld');
        $this->assertCount(1, $seo['issues'], 'only the critical issue is shown; the low-severity one is withheld');
        $this->assertSame([], $seo['pages'], 'page-level findings are full-access content');
        $this->assertSame(1, $seo['hidden_issue_count']);
        $this->assertSame(1, $seo['hidden_page_count']);
    }

    public function test_anonymous_issue_detail_is_redacted_but_not_misrepresented(): void
    {
        $seo = $this->getJson("/api/audits/{$this->audit->id}/report/services")->json('data.services.seo');
        $issue = $seo['issues'][0];

        $this->assertSame('seo.missing_title', $issue['code']);
        $this->assertSame('Metadata', $issue['category']);
        $this->assertArrayNotHasKey('recommendation', $issue, 'the fix itself is paid detail');
        $this->assertArrayNotHasKey('evidence', $issue);
        $this->assertArrayNotHasKey('affected_pages', $issue, 'the URL list is withheld, but the count is not');
        $this->assertArrayHasKey('affected_page_count', $issue);
    }

    public function test_a_not_applicable_service_is_reported_as_such_not_scored(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->ownerToken()}")
            ->getJson("/api/audits/{$this->audit->id}/report/services/ecommerce");

        $response->assertStatus(200)
            ->assertJsonPath('data.score', null)
            ->assertJsonPath('data.is_applicable', false)
            ->assertJsonPath('data.status', 'not_applicable');
    }

    public function test_a_service_that_never_ran_is_404_not_an_empty_report(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->ownerToken()}")
            ->getJson("/api/audits/{$this->audit->id}/report/services/performance")
            ->assertStatus(404);
    }

    public function test_page_explorer_requires_full_access(): void
    {
        $this->getJson("/api/audits/{$this->audit->id}/pages")->assertStatus(403);

        $this->withHeader('Authorization', "Bearer {$this->ownerToken()}")
            ->getJson("/api/audits/{$this->audit->id}/pages")
            ->assertStatus(200)
            ->assertJsonPath('data.0.url', 'https://example.com/a')
            ->assertJsonPath('data.0.issue_count', 2)
            ->assertJsonPath('data.0.severity_summary.critical', 1)
            ->assertJsonPath('data.0.issues_by_service.seo', 2);
    }

    public function test_service_pdf_renders_a_real_pdf_for_a_plan_with_pdf_export(): void
    {
        // Moves the owner onto a PDF-enabled plan. `subscriptions.user_id`
        // is unique — one active plan per user — so an upgrade updates the
        // existing row rather than adding a second.
        $this->owner->subscription->update(['plan_id' => Plan::create([
            'name' => 'Pro', 'slug' => 'pro', 'max_pages' => 100, 'daily_audit_limit' => 10,
            'features' => ['pdf_export' => true],
        ])->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->ownerToken()}")
            ->get("/api/audits/{$this->audit->id}/report/services/seo/pdf");

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="seo-report.pdf"');

        // Assert on the real binary, not just that dompdf was called — a
        // broken Blade view would still return 200 with the right headers.
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_the_service_pdf_header_names_the_site_it_audited(): void
    {
        $html = view('reports.service-pdf', [
            'audit' => $this->audit->fresh(),
            'report' => app(ServiceReportBuilder::class)
                ->buildAll($this->audit->fresh())['seo'],
            'branding' => app(BrandingResolver::class)->forAudit($this->audit->fresh()),
        ])->render();

        // The header read `$website->url`, a column renamed to
        // `primary_url` in §37. Blade renders a missing attribute as an
        // empty string, so every per-service PDF shipped with a blank
        // site URL and nothing failed.
        // Asserted against the header block specifically: the audited
        // domain appears all over the body, so a plain substring check
        // passes even when the header is blank.
        $this->assertMatchesRegularExpression(
            '~<p class="muted">\s*https://example\.com~',
            $html,
        );
    }

    public function test_service_pdf_is_refused_for_an_anonymous_viewer(): void
    {
        $this->get("/api/audits/{$this->audit->id}/report/services/seo/pdf")->assertStatus(403);
    }

    public function test_service_pdf_404s_for_a_service_that_never_ran(): void
    {
        // Moves the owner onto a PDF-enabled plan. `subscriptions.user_id`
        // is unique — one active plan per user — so an upgrade updates the
        // existing row rather than adding a second.
        $this->owner->subscription->update(['plan_id' => Plan::create([
            'name' => 'Pro', 'slug' => 'pro', 'max_pages' => 100, 'daily_audit_limit' => 10,
            'features' => ['pdf_export' => true],
        ])->id]);

        $this->withHeader('Authorization', "Bearer {$this->ownerToken()}")
            ->get("/api/audits/{$this->audit->id}/report/services/performance/pdf")
            ->assertStatus(404);
    }

    public function test_service_reports_404_before_the_report_exists(): void
    {
        $this->audit->report->delete();

        $this->withHeader('Authorization', "Bearer {$this->ownerToken()}")
            ->getJson("/api/audits/{$this->audit->id}/report/services")
            ->assertStatus(404);
    }
}
