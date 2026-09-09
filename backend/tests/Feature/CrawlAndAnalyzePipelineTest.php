<?php

namespace Tests\Feature;

use App\DTOs\CrawledPageData;
use App\Enums\AuditStatus;
use App\Jobs\Crawl\CrawlWebsiteJob;
use App\Models\Audit;
use App\Models\AuditPage;
use App\Models\AuditScore;
use App\Models\Report;
use App\Models\Screenshot;
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
        Storage::fake(config('crawler.screenshot_disk'));

        // A robots.txt declaring a sitemap that lists only the homepage.
        // The crawl still stays on one page, so the test is as
        // deterministic as it was — but since §44 the SEO audit reports a
        // missing robots.txt and a missing sitemap as real findings, and a
        // fixture asserting "every category scores 100" has to be a site
        // that genuinely has nothing wrong with it.
        Http::fake([
            'https://example.com/robots.txt' => Http::response(
                'User-agent: *
Sitemap: https://example.com/sitemap.xml
',
                200,
            ),
            'https://example.com/sitemap.xml' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.com/</loc></url></urlset>',
                200,
            ),
            '*' => Http::response('', 404),
        ]);

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
                headings: [
                    ['level' => 1, 'text' => 'Welcome'],
                    // §46 reports a site with no visible social proof; a
                    // fixture asserting "every category scores 100" has to
                    // be a page that genuinely has nothing wrong with it.
                    ['level' => 2, 'text' => 'What our customers say'],
                ],
                links: [
                    ['href' => 'https://example.com/about', 'text' => 'About'],
                    ['href' => 'https://example.com/contact', 'text' => 'Contact'],
                    ['href' => 'https://example.com/privacy', 'text' => 'Privacy policy'],
                ],
                images: [['src' => 'a.png', 'alt' => 'A picture', 'width' => 100, 'height' => 100]],
                forms: [],
                // A complete Organization node: since §44 the SEO audit
                // reports a site with no Organization schema, so a fixture
                // meant to represent a clean page has to actually have one.
                structuredData: [
                    ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'Example', 'url' => 'https://example.com', 'logo' => 'https://example.com/logo.png', 'sameAs' => ['https://x.com/example']],
                ],
                // "Learn more" on its own is exactly the vague wording
                // §46 now reports, so the fixture says what it does.
                ctaElements: [['text' => 'Book a free consultation', 'href' => 'https://example.com/book']],
                htmlSizeBytes: 5000,
                headers: [],
                htmlSample: '<html><head></head><body>Welcome</body></html>',
                accessibilityViolations: [],
            ));
        });

        $website = Website::create(['primary_url' => 'https://example.com', 'domain' => 'example.com', 'normalized_domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'status' => AuditStatus::Queued, 'max_pages' => 5]);

        CrawlWebsiteJob::dispatch($audit->fresh());

        $audit->refresh();

        $this->assertSame(AuditStatus::Completed, $audit->status);
        $this->assertSame(1, AuditPage::where('audit_id', $audit->id)->count());

        $scores = AuditScore::where('audit_id', $audit->id)->get()->keyBy(fn ($s) => $s->category->value);

        foreach (['performance', 'accessibility', 'seo', 'ecommerce', 'landing_page', 'conversion', 'aio', 'product_auditor', 'overall'] as $category) {
            $this->assertTrue($scores->has($category), "Missing score row for category: {$category}");
        }

        // The fixture page shows no ecommerce signals at all (no Product
        // schema, no cart/checkout link, no add-to-cart CTA) — a real
        // EcommerceDetector correctly reports Not Applicable, not a
        // manufactured 100, for a site that isn't selling anything.
        $this->assertFalse($scores['ecommerce']->is_applicable);
        $this->assertNull($scores['ecommerce']->score);

        // Same reasoning as ecommerce above: the fixture page carries no
        // Product schema, no product-shaped URL and no add-to-cart action,
        // so the product audit correctly has nothing to audit and reports
        // Not Applicable rather than a manufactured 100.
        $this->assertFalse($scores['product_auditor']->is_applicable);
        $this->assertNull($scores['product_auditor']->score);

        // The page was crafted to be flawless against every deterministic
        // rule across the remaining modules, and no AI keys are configured
        // in the test environment (graceful degradation, no AI-derived
        // issues) — every *applicable* category, and therefore the
        // overall score (which excludes Not Applicable categories from
        // its weighting), must be 100.
        foreach ($scores as $category => $score) {
            if (in_array($category, ['ecommerce', 'product_auditor'], true)) {
                continue;
            }
            $this->assertSame(100, $score->score, "Expected category '{$category}' to score 100, got {$score->score}");
        }

        $report = Report::where('audit_id', $audit->id)->first();
        $this->assertNotNull($report, 'GenerateReportJob should have created a Report row');
        $this->assertSame(100, $report->content['overview']['overall_score']);
        $this->assertSame(0, count($report->content['issues']), 'A flawless page should have zero issues in the report');
    }

    /**
     * Regression: CrawlOrchestrator::toPublicPath() used to normalize
     * backslashes to forward slashes *before* stripping the disk's base
     * path, so on Windows the base path (still backslash-separated) never
     * matched anywhere in the already-normalized string, and the full
     * absolute filesystem path was stored instead of a relative one —
     * found only by inspecting a real screenshot's stored path after a
     * live audit; every other test fakes the disk with a consistent
     * separator throughout and never exercised the mismatch.
     */
    public function test_screenshot_storage_paths_are_stored_relative_to_the_disk_not_as_absolute_paths(): void
    {
        Storage::fake(config('crawler.screenshot_disk'));
        Http::fake(['*' => Http::response('', 404)]);

        // The same disk the orchestrator resolves paths against. Writing
        // the fixture to `public` while the crawler reads `local` was
        // making this test fail for a reason production would not have.
        $disk = Storage::disk(config('crawler.screenshot_disk'));
        $desktopAbsolute = $disk->path('screenshots/1/homepage/desktop.png');
        $mobileAbsolute = $disk->path('screenshots/1/homepage/mobile.png');
        @mkdir(dirname($desktopAbsolute), 0777, true);
        file_put_contents($desktopAbsolute, 'fake-png-bytes');
        file_put_contents($mobileAbsolute, 'fake-png-bytes');

        $this->mock(PlaywrightCrawlerClient::class, function ($mock) use ($desktopAbsolute, $mobileAbsolute) {
            $mock->shouldReceive('crawl')->once()->andReturn(new CrawledPageData(
                url: 'https://example.com/',
                httpStatus: 200,
                redirectChain: [],
                loadTimeMs: 500,
                screenshotDesktopPath: $desktopAbsolute,
                screenshotMobilePath: $mobileAbsolute,
                title: 'A Perfectly Fine Page Title',
                metaDescription: str_repeat('a', 100),
                canonicalUrl: 'https://example.com/',
                headings: [['level' => 1, 'text' => 'Welcome']],
                links: [],
                images: [],
                forms: [],
                structuredData: [],
                ctaElements: [],
                htmlSizeBytes: 5000,
                headers: [],
                htmlSample: '<html><head></head><body>Welcome</body></html>',
                accessibilityViolations: [],
            ));
        });

        $website = Website::create(['primary_url' => 'https://example.com', 'domain' => 'example.com', 'normalized_domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'status' => AuditStatus::Queued, 'max_pages' => 5]);

        CrawlWebsiteJob::dispatch($audit->fresh());

        $screenshots = Screenshot::where('audit_id', $audit->id)->get()->keyBy(fn ($s) => $s->viewport->value);

        $this->assertCount(2, $screenshots);
        // Still relative to the disk root, whichever disk that is — §48
        // moved screenshots off `public`, and an absolute Windows path
        // here is the regression this test exists for.
        $this->assertSame('screenshots/1/homepage/desktop.png', $screenshots['desktop']->storage_path);
        $this->assertSame('screenshots/1/homepage/mobile.png', $screenshots['mobile']->storage_path);
        $this->assertStringNotContainsString(':', $screenshots['desktop']->storage_path, 'must not be a Windows absolute path (e.g. C:\\...)');
    }

    public function test_pipeline_marks_audit_failed_when_the_homepage_crawl_fails(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $this->mock(PlaywrightCrawlerClient::class, function ($mock) {
            $mock->shouldReceive('crawl')->once()->andThrow(new CrawlerException('Navigation timed out'));
        });

        $website = Website::create(['primary_url' => 'https://example.com', 'domain' => 'example.com', 'normalized_domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'status' => AuditStatus::Queued, 'max_pages' => 5]);

        CrawlWebsiteJob::dispatch($audit->fresh());

        $audit->refresh();

        $this->assertSame(AuditStatus::Failed, $audit->status);
        $this->assertNotNull($audit->error_message);
        $this->assertSame(0, AuditPage::where('audit_id', $audit->id)->count());
        $this->assertSame(0, AuditScore::where('audit_id', $audit->id)->count());
        $this->assertSame(0, Report::where('audit_id', $audit->id)->count());
    }
}
