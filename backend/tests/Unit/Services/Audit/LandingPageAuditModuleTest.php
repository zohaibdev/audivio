<?php

namespace Tests\Unit\Services\Audit;

use App\Models\Audit;
use App\Models\AuditPage;
use App\Models\Website;
use App\Services\AI\AiAnalysisService;
use App\Services\Audit\LandingPageAuditModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPageAuditModuleTest extends TestCase
{
    use RefreshDatabase;

    private function makeAudit(): Audit
    {
        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com']);

        return Audit::create(['website_id' => $website->id, 'status' => 'analyzing', 'max_pages' => 5]);
    }

    private function module(): LandingPageAuditModule
    {
        return new LandingPageAuditModule(app(AiAnalysisService::class));
    }

    public function test_page_with_cta_and_heading_scores_100(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'headings' => [['level' => 1, 'text' => 'Welcome']],
            'cta_elements' => [['text' => 'Get Started']],
        ]);

        $result = $this->module()->run($audit->fresh());

        $this->assertSame(100, $result->score);
        $this->assertCount(0, $result->issues);
    }

    public function test_no_cta_anywhere_is_flagged_high(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'headings' => [['level' => 1, 'text' => 'Welcome']],
            'cta_elements' => [],
        ]);

        $result = $this->module()->run($audit->fresh());

        $issue = collect($result->issues)->firstWhere('code', 'landing_page.no_cta');
        $this->assertNotNull($issue);
        $this->assertSame('high', $issue->severity->value);
    }

    public function test_page_with_no_headings_is_flagged_medium(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'headings' => [],
            'cta_elements' => [['text' => 'Get Started']],
        ]);

        $result = $this->module()->run($audit->fresh());

        $issue = collect($result->issues)->firstWhere('code', 'landing_page.no_headline');
        $this->assertNotNull($issue);
        $this->assertSame('medium', $issue->severity->value);
    }

    public function test_cta_count_is_summed_across_all_pages(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id, 'url' => 'https://example.com/a', 'http_status' => 200,
            'headings' => [['level' => 1, 'text' => 'A']], 'cta_elements' => [],
        ]);
        AuditPage::create([
            'audit_id' => $audit->id, 'url' => 'https://example.com/b', 'http_status' => 200,
            'headings' => [['level' => 1, 'text' => 'B']], 'cta_elements' => [['text' => 'Buy now']],
        ]);

        $result = $this->module()->run($audit->fresh());

        // At least one page has a CTA, so the site-wide "no CTA anywhere" check should not fire.
        $this->assertFalse(collect($result->issues)->pluck('code')->contains('landing_page.no_cta'));
    }
}
