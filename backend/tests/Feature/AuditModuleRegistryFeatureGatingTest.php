<?php

namespace Tests\Feature;

use App\DTOs\CrawledPageData;
use App\Enums\AuditStatus;
use App\Jobs\Crawl\CrawlWebsiteJob;
use App\Models\Audit;
use App\Models\AuditScore;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;
use App\Services\Crawler\PlaywrightCrawlerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TC-FEATURE-001/002: a plan's audit-feature toggles must actually change
 * which modules run for a real audit, not just what /api/me/features
 * reports. Reuses CrawlAndAnalyzePipelineTest's proven "flawless page"
 * fixture (real CrawlWebsiteJob -> ... -> AuditModuleRegistry::dispatchAnalysis
 * -> RunAuditModuleJob -> CalculateScoreJob, all real except the
 * Playwright subprocess and outbound HTTP) rather than hand-crafting an
 * AuditPage row, since each module's exact field expectations aren't this
 * test's concern.
 */
class AuditModuleRegistryFeatureGatingTest extends TestCase
{
    use RefreshDatabase;

    private function seedFeatures(): void
    {
        foreach (['performance', 'accessibility', 'cro', 'seo', 'aio', 'ecommerce', 'product_auditor'] as $key) {
            Feature::create(['key' => $key, 'name' => ucfirst($key), 'is_enabled' => true]);
        }
    }

    private function runFlawlessPipelineAudit(Plan $plan): Audit
    {
        Storage::fake('public');
        Http::fake(['*' => Http::response('', 404)]);

        $this->mock(PlaywrightCrawlerClient::class, function ($mock) {
            $mock->shouldReceive('crawl')->once()->andReturn(new CrawledPageData(
                url: 'https://example.com/',
                httpStatus: 200,
                redirectChain: [],
                loadTimeMs: 500,
                screenshotDesktopPath: null,
                screenshotMobilePath: null,
                title: 'A Perfectly Fine Page Title',
                metaDescription: str_repeat('a', 100),
                canonicalUrl: 'https://example.com/',
                headings: [['level' => 1, 'text' => 'Welcome']],
                links: [],
                images: [['src' => 'a.png', 'alt' => 'A picture', 'width' => 100, 'height' => 100]],
                forms: [],
                structuredData: [['@type' => 'Article']],
                ctaElements: [['text' => 'Learn more', 'href' => 'https://example.com/more']],
                htmlSizeBytes: 5000,
                headers: [],
                htmlSample: '<html><head></head><body>Welcome</body></html>',
                accessibilityViolations: [],
            ));
        });

        $user = User::create(['name' => 'User', 'email' => 'user@example.com', 'password' => 'x']);
        Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'started_at' => now()]);

        $website = Website::create(['user_id' => $user->id, 'url' => 'https://example.com', 'domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'user_id' => $user->id, 'status' => AuditStatus::Pending, 'max_pages' => 5]);

        CrawlWebsiteJob::dispatch($audit->fresh());

        return $audit->fresh();
    }

    public function test_a_plan_that_disables_seo_and_accessibility_skips_only_those_modules(): void
    {
        $this->seedFeatures();

        $plan = Plan::create(['name' => 'Restricted', 'slug' => 'restricted', 'max_pages' => 25, 'daily_audit_limit' => 5]);
        foreach (['performance', 'cro', 'aio', 'ecommerce'] as $key) {
            $plan->auditFeatures()->attach(Feature::where('key', $key)->first()->id, ['is_enabled' => true]);
        }
        // seo and accessibility deliberately left unassigned (disabled).

        $audit = $this->runFlawlessPipelineAudit($plan);

        $categoriesScored = $audit->scores()->pluck('category')->map(fn ($c) => $c->value)->all();

        $this->assertNotContains('seo', $categoriesScored);
        $this->assertNotContains('accessibility', $categoriesScored);
        foreach (['performance', 'ecommerce', 'landing_page', 'conversion', 'aio', 'overall'] as $expected) {
            $this->assertContains($expected, $categoriesScored, "Missing score row for category: {$expected}");
        }
        $this->assertSame(AuditStatus::Completed, $audit->status);
    }

    public function test_a_plan_with_every_feature_disabled_still_completes_the_audit_with_a_zero_overall_score(): void
    {
        $this->seedFeatures();
        $plan = Plan::create(['name' => 'Bare', 'slug' => 'bare', 'max_pages' => 25, 'daily_audit_limit' => 5]);
        // No auditFeatures attached at all — every category denied.

        $audit = $this->runFlawlessPipelineAudit($plan);

        $this->assertSame(AuditStatus::Completed, $audit->status);
        $this->assertSame(0, AuditScore::where('audit_id', $audit->id)->where('category', 'overall')->first()->score);
        $this->assertSame(0, AuditScore::where('audit_id', $audit->id)->where('category', '!=', 'overall')->count());
    }
}
