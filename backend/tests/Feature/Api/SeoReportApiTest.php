<?php

namespace Tests\Feature\Api;

use App\Contracts\BacklinkProvider;
use App\DTOs\BacklinkData;
use App\Enums\AuditCategory;
use App\Enums\IssueSeverity;
use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\AuditPage;
use App\Models\AuditScore;
use App\Models\AuditSiteSignals;
use App\Models\Plan;
use App\Models\Report;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;
use App\Services\Backlink\BacklinkService;
use App\Services\Report\BrandingResolver;
use App\Services\Report\ServiceReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The four dedicated SEO views (§44) — redirect report, Open Graph
 * preview, schema report and the page-by-page table — plus the backlink
 * status, which must never be a number this product made up.
 */
class SeoReportApiTest extends TestCase
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
            'user_id' => $this->owner->id, 'primary_url' => 'https://example.com/',
            'domain' => 'example.com', 'normalized_domain' => 'example.com',
        ]);

        $this->audit = Audit::create([
            'website_id' => $website->id, 'user_id' => $this->owner->id,
            'status' => 'completed', 'max_pages' => 5, 'pages_crawled' => 3, 'completed_at' => now(),
        ]);

        AuditScore::create(['audit_id' => $this->audit->id, 'category' => 'overall', 'score' => 70]);
        AuditScore::create([
            'audit_id' => $this->audit->id, 'category' => 'seo', 'score' => 70,
            'breakdown' => ['coverage' => ['pages_analyzed' => 3, 'checks' => []]],
        ]);

        AuditSiteSignals::create([
            'audit_id' => $this->audit->id,
            'robots_txt_url' => 'https://example.com/robots.txt',
            'robots_txt_found' => true,
            'robots_disallow_count' => 2,
            'robots_sitemaps' => ['https://example.com/sitemap.xml'],
            'sitemap_found' => true,
            'sitemap_url_count' => 40,
            'sitemap_declared_in_robots' => true,
        ]);

        AuditPage::create([
            'audit_id' => $this->audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'title' => 'Home',
            'meta_description' => 'Welcome to the homepage of a shop that sells things.',
            'canonical_url' => 'https://example.com/',
            'headings' => [['level' => 1, 'text' => 'Welcome']],
            'images' => [['src' => 'https://example.com/a.png', 'alt' => '']],
            'links' => [['href' => 'https://example.com/product', 'text' => 'Product']],
            'redirect_chain' => [],
            'open_graph' => [
                'og:title' => 'Shared home', 'og:description' => 'A description',
                'og:image' => 'https://example.com/card.png', 'og:url' => 'https://example.com/',
            ],
            'twitter_card' => ['twitter:card' => 'summary_large_image'],
            'structured_data' => [[
                '@type' => 'Organization', 'name' => 'Example', 'url' => 'https://example.com',
                'logo' => 'l.png', 'sameAs' => ['https://x.com/e'],
            ]],
        ]);

        AuditPage::create([
            'audit_id' => $this->audit->id,
            'url' => 'https://example.com/product',
            'http_status' => 200,
            'title' => 'Product',
            'meta_description' => null,
            'canonical_url' => 'https://example.com/product',
            'headings' => [['level' => 1, 'text' => 'A product']],
            'images' => [],
            'links' => [],
            'redirect_chain' => [
                ['url' => 'http://example.com/product', 'status' => 301],
                ['url' => 'https://example.com/old-product', 'status' => 302],
            ],
            'open_graph' => [],
            'twitter_card' => [],
            'structured_data' => [['@type' => 'Product', 'name' => 'A thing']],
        ]);

        Report::create([
            'audit_id' => $this->audit->id,
            'content' => ['overview' => [], 'issues' => [], 'recommendations' => [], 'ai_analysis' => [], 'pages' => []],
            'generated_at' => now(),
        ]);

        AuditIssue::create([
            'audit_id' => $this->audit->id,
            'category' => AuditCategory::Seo, 'severity' => IssueSeverity::High,
            'code' => 'seo.missing_meta_description', 'message' => 'Missing description',
            'recommendation' => 'Add one.',
        ]);
    }

    private function seoReport(): array
    {
        return $this->withHeader('Authorization', "Bearer {$this->owner->createToken('api')->plainTextToken}")
            ->getJson("/api/audits/{$this->audit->id}/report/services")
            ->json('data.services.seo');
    }

    // --- Redirect report ---

    public function test_the_redirect_report_separates_chains_loops_and_temporary_redirects(): void
    {
        $redirects = $this->seoReport()['seo']['redirects'];

        $this->assertSame(1, $redirects['total']);
        $this->assertSame(1, $redirects['chains']);
        $this->assertSame(0, $redirects['loops']);
        $this->assertSame(1, $redirects['temporary']);

        $entry = $redirects['entries'][0];
        $this->assertSame('https://example.com/product', $entry['final_url']);
        $this->assertSame(2, $entry['hop_count']);
        $this->assertSame('permanent', $entry['hops'][0]['type']);
        $this->assertSame('temporary', $entry['hops'][1]['type']);
    }

    public function test_the_redirect_report_states_what_it_covers(): void
    {
        // Redirects on pages outside the crawl budget are not in here, and
        // the report does not imply they are absent.
        $this->assertStringContainsString(
            "this audit's pages",
            $this->seoReport()['seo']['redirects']['scope_note'],
        );
    }

    // --- Open Graph preview ---

    public function test_the_og_preview_shows_what_each_field_will_actually_render(): void
    {
        $social = $this->seoReport()['seo']['social'];

        $this->assertSame(2, $social['analyzed_page_count']);
        $this->assertSame(1, $social['pages_with_open_graph']);
        $this->assertSame(1, $social['pages_with_og_image']);

        $home = collect($social['previews'])->firstWhere('url', 'https://example.com/');
        $this->assertSame('Shared home', $home['preview']['title']['value']);
        $this->assertSame('og:title', $home['preview']['title']['source']);
        $this->assertFalse($home['preview']['title']['is_fallback']);

        // The product page has no OG tags at all, so the platforms fall
        // back to the <title> — which is what an owner needs to see.
        $product = collect($social['previews'])->firstWhere('url', 'https://example.com/product');
        $this->assertSame('Product', $product['preview']['title']['value']);
        $this->assertTrue($product['preview']['title']['is_fallback']);
        $this->assertNull($product['preview']['image']['value']);
    }

    public function test_the_preview_says_it_is_derived_from_tags_not_captured_live(): void
    {
        $this->assertStringContainsString(
            'derived from the tags',
            $this->seoReport()['seo']['social']['preview_note'],
        );
    }

    // --- Schema report ---

    public function test_the_schema_report_lists_which_types_are_present_and_where(): void
    {
        $schema = $this->seoReport()['seo']['schema'];
        $types = collect($schema['types'])->keyBy('type');

        $this->assertTrue($types['Organization']['present']);
        $this->assertSame(['https://example.com/'], $types['Organization']['pages']);
        $this->assertTrue($types['Product']['present']);
        $this->assertFalse($types['FAQPage']['present']);
        $this->assertSame(0, $types['FAQPage']['page_count']);

        $product = collect($schema['pages'])->firstWhere('url', 'https://example.com/product');
        $this->assertSame(['offers'], $product['problems'][0]['missing_required']);
    }

    public function test_the_schema_report_states_that_only_json_ld_is_read(): void
    {
        // A site using microdata would otherwise read this report as
        // "you have no structured data", which would be false.
        $this->assertStringContainsString('Microdata and RDFa', $this->seoReport()['seo']['schema']['note']);
    }

    // --- Page-by-page report ---

    public function test_the_page_by_page_report_has_one_row_per_crawled_page(): void
    {
        $pages = collect($this->seoReport()['seo']['pages'])->keyBy('url');

        $this->assertCount(2, $pages);

        $home = $pages['https://example.com/'];
        $this->assertSame(200, $home['http_status']);
        $this->assertSame(4, $home['title_length']);
        $this->assertSame(1, $home['h1_count']);
        $this->assertSame(1, $home['image_count']);
        $this->assertSame(1, $home['images_missing_alt']);
        $this->assertSame(1, $home['internal_links_out']);
        $this->assertTrue($home['is_indexable']);
        $this->assertContains('Organization', $home['schema_types']);

        $product = $pages['https://example.com/product'];
        $this->assertNull($product['meta_description_length']);
        $this->assertSame(1, $product['internal_links_in']);
        $this->assertSame(2, $product['redirect_hop_count']);
    }

    // --- Site report ---

    public function test_the_site_report_distinguishes_not_found_from_unknown(): void
    {
        $site = $this->seoReport()['seo']['site'];

        $this->assertSame('found', $site['robots_txt']['status']);
        $this->assertSame(2, $site['robots_txt']['disallow_count']);
        $this->assertSame('found', $site['sitemap']['status']);
        $this->assertSame(40, $site['sitemap']['url_count']);
        $this->assertSame(1, $site['links']['internal']);
    }

    public function test_an_audit_with_no_site_signals_reports_unknown_not_missing(): void
    {
        AuditSiteSignals::where('audit_id', $this->audit->id)->delete();

        $site = $this->seoReport()['seo']['site'];

        // "We did not record this" must not render as "this site has no
        // robots.txt".
        $this->assertSame('unknown', $site['robots_txt']['status']);
        $this->assertSame('unknown', $site['sitemap']['status']);
        $this->assertNull($site['sitemap']['url_count']);
    }

    // --- Backlinks ---

    public function test_with_no_provider_the_report_says_so_instead_of_showing_numbers(): void
    {
        $backlinks = $this->seoReport()['backlinks'];

        $this->assertSame('not_configured', $backlinks['status']);
        $this->assertNull($backlinks['data']);
        $this->assertStringContainsString('Connect a backlink data provider', $backlinks['message']);
    }

    public function test_a_configured_provider_supplies_real_attributed_data(): void
    {
        $this->app->bind(BacklinkProvider::class, fn () => new class implements BacklinkProvider
        {
            public function name(): string
            {
                return 'test-index';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function fetch(string $domain): ?BacklinkData
            {
                return new BacklinkData(
                    domain: $domain,
                    providerName: 'test-index',
                    fetchedAt: now(),
                    totalBacklinks: 1200,
                    referringDomains: 85,
                    domainAuthority: 42,
                    authorityScale: '0-100',
                    topReferrers: [['domain' => 'news.test', 'authority' => 70]],
                );
            }
        });

        $backlinks = $this->seoReport()['backlinks'];

        $this->assertSame('available', $backlinks['status']);
        $this->assertSame('test-index', $backlinks['provider']);
        $this->assertSame(1200, $backlinks['data']['total_backlinks']);
        // Attributed and time-stamped: backlink indexes disagree, so a
        // figure without its source reads as a fact about the web.
        $this->assertSame('test-index', $backlinks['data']['provider']);
        $this->assertNotNull($backlinks['data']['fetched_at']);
    }

    public function test_a_failing_provider_reports_no_data_rather_than_zero(): void
    {
        $this->app->bind(BacklinkProvider::class, fn () => new class implements BacklinkProvider
        {
            public function name(): string
            {
                return 'flaky-index';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function fetch(string $domain): ?BacklinkData
            {
                throw new \RuntimeException('rate limited');
            }
        });

        $backlinks = app(BacklinkService::class)->forDomain('example.com');

        // "The provider is down" says nothing about the site. Reporting
        // zero backlinks here would be a false claim the reader has no way
        // to distinguish from a measured one.
        $this->assertSame('unavailable', $backlinks['status']);
        $this->assertNull($backlinks['data']);
        $this->assertStringContainsString('rather than an estimate', $backlinks['message']);
    }

    public function test_a_successful_answer_is_cached_but_a_failure_is_not(): void
    {
        $calls = 0;

        $this->app->bind(BacklinkProvider::class, function () use (&$calls) {
            return new class($calls) implements BacklinkProvider
            {
                public function __construct(private int &$calls) {}

                public function name(): string
                {
                    return 'counting-index';
                }

                public function isConfigured(): bool
                {
                    return true;
                }

                public function fetch(string $domain): ?BacklinkData
                {
                    $this->calls++;

                    // Fails first, succeeds after — the shape of a
                    // provider recovering from an outage.
                    return $this->calls === 1
                        ? null
                        : new BacklinkData($domain, 'counting-index', now(), totalBacklinks: 7);
                }
            };
        });

        $service = app(BacklinkService::class);

        $this->assertSame('unavailable', $service->forDomain('example.com')['status']);
        $this->assertSame('available', $service->forDomain('example.com')['status']);
        // The failure was not pinned in place; the success is reused
        // rather than billing a call per report render.
        $this->assertSame('available', $service->forDomain('example.com')['status']);
        $this->assertSame(2, $calls);
    }

    public function test_an_unknown_driver_name_falls_back_to_no_data_rather_than_failing(): void
    {
        config(['backlinks.driver' => 'a-typo']);

        // A typo in an env var must not take audits down, and reporting
        // "no backlink data" is always safe.
        $this->assertFalse(app(BacklinkService::class)->isEnabled());
        $this->assertSame('not_configured', app(BacklinkService::class)->forDomain('example.com')['status']);
    }

    // --- Visibility ---

    public function test_the_seo_detail_block_is_full_access_content(): void
    {
        $service = $this->getJson("/api/audits/{$this->audit->id}/report/services")
            ->json('data.services.seo');

        // The score and severity counts stay visible; the page-by-page
        // table and the redirect list are the paid detail.
        $this->assertSame(70, $service['score']);
        $this->assertArrayNotHasKey('seo', $service);
    }

    public function test_the_service_pdf_renders_the_seo_views_and_the_backlink_state(): void
    {
        $report = app(ServiceReportBuilder::class)->buildAll($this->audit->fresh())['seo'];

        $html = view('reports.service-pdf', [
            'audit' => $this->audit->fresh(),
            'report' => $report,
            // From the real resolver, as production does — otherwise the
            // template's branding block is never exercised by any test.
            'branding' => app(BrandingResolver::class)->forAudit($this->audit->fresh()),
        ])->render();

        $this->assertStringContainsString('Redirect report', $html);
        $this->assertStringContainsString('https://example.com/old-product [302]', $html);
        $this->assertStringContainsString('Structured data', $html);
        $this->assertStringContainsString('Page by page', $html);
        // With no provider configured the PDF states that, and shows no
        // figure a reader could mistake for a measurement.
        $this->assertStringContainsString('Connect a backlink data provider', $html);
        $this->assertStringNotContainsString('Referring domains', $html);
    }

    public function test_the_builder_is_not_run_for_other_services(): void
    {
        $reports = app(ServiceReportBuilder::class)->buildAll($this->audit->fresh());

        $this->assertNotNull($reports['seo']['seo']);
        $this->assertNull($reports['seo']['accessibility'] ?? null);
    }
}
