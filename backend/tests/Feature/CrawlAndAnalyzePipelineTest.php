<?php

namespace Tests\Feature;

use App\DTOs\CrawledPageData;
use App\Enums\AuditStatus;
use App\Jobs\Crawl\CrawlWebsiteJob;
use App\Models\Audit;
use App\Models\AuditPage;
use App\Models\AuditScore;
use App\Models\Website;
use App\Services\Crawler\CrawlerException;
use App\Services\Crawler\PlaywrightCrawlerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Drives the real crawl -> 7-module analysis -> scoring pipeline end to
 * end, the way `php artisan audit:crawl` does manually, but as a
 * repeatable assertion-based test. The queue connection is `sync` in
 * phpunit.xml, so dispatching CrawlWebsiteJob runs the whole cascade
 * (CrawlPageJob -> AuditModuleRegistry::dispatchAnalysis ->
 * 7x RunAuditModuleJob -> CalculateScoreJob) synchronously within this
 * test — no queue worker needed.
 *
 * The only thing faked is PlaywrightCrawlerClient (no real Playwright
 * subprocess/browser) and outbound HTTP (no real robots.txt/sitemap
 * fetch) — everything else (SSRF validation, platform detection, all 7
 * audit modules, scoring math) runs for real.
 */
class CrawlAndAnalyzePipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_pipeline_produces_a_completed_audit_with_all_category_scores(): void
    {
        Storage::fake('public');

        // No robots.txt / sitemap.xml on this fake site — crawl stays to
        // just the homepage, keeping the test deterministic.
        Http::fake(['*' => Http::response('', 404)]);

        $this->mock(PlaywrightCrawlerClient::class, function ($mock) {
            $mock->shouldReceive('crawl')->once()->andReturn(new CrawledPageData(
                url: 'https://example.com/',
                httpStatus: 200,
                redirectChain: [],
                loadTimeMs: 500,
                screenshotPath: null,
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

        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'status' => AuditStatus::Pending, 'max_pages' => 5]);

        CrawlWebsiteJob::dispatch($audit->fresh());

        $audit->refresh();

        $this->assertSame(AuditStatus::Completed, $audit->status);
        $this->assertSame(1, AuditPage::where('audit_id', $audit->id)->count());

        $scores = AuditScore::where('audit_id', $audit->id)->get()->keyBy(fn ($s) => $s->category->value);

        foreach (['performance', 'accessibility', 'seo', 'ecommerce', 'landing_page', 'conversion', 'aio', 'overall'] as $category) {
            $this->assertTrue($scores->has($category), "Missing score row for category: {$category}");
        }

        // The page was crafted to be flawless against every deterministic
        // rule across all 7 modules, and no AI keys are configured in the
        // test environment (graceful degradation, no AI-derived issues) —
        // every category, and therefore the overall score, must be 100.
        foreach ($scores as $category => $score) {
            $this->assertSame(100, $score->score, "Expected category '{$category}' to score 100, got {$score->score}");
        }
    }

    public function test_pipeline_marks_audit_failed_when_the_homepage_crawl_fails(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $this->mock(PlaywrightCrawlerClient::class, function ($mock) {
            $mock->shouldReceive('crawl')->once()->andThrow(new CrawlerException('Navigation timed out'));
        });

        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'status' => AuditStatus::Pending, 'max_pages' => 5]);

        CrawlWebsiteJob::dispatch($audit->fresh());

        $audit->refresh();

        $this->assertSame(AuditStatus::Failed, $audit->status);
        $this->assertNotNull($audit->error_message);
        $this->assertSame(0, AuditPage::where('audit_id', $audit->id)->count());
        $this->assertSame(0, AuditScore::where('audit_id', $audit->id)->count());
    }
}
