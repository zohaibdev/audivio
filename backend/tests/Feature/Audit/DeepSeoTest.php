<?php

namespace Tests\Feature\Audit;

use App\Models\Audit;
use App\Models\AuditPage;
use App\Models\AuditSiteSignals;
use App\Models\Website;
use App\Services\Audit\SeoAuditModule;
use App\Services\Seo\IndexabilityResolver;
use App\Services\Seo\LinkGraph;
use App\Services\Seo\RedirectAnalyzer;
use App\Services\Seo\SchemaAnalyzer;
use App\Services\Seo\SocialCardAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The deep SEO audit (§44): the twenty-four checks, and the places where
 * the honest answer is "we could not tell" rather than a finding.
 */
class DeepSeoTest extends TestCase
{
    use RefreshDatabase;

    private const ORG_SCHEMA = [
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => 'Example',
        'url' => 'https://example.com',
        'logo' => 'https://example.com/logo.png',
        'sameAs' => ['https://x.com/example'],
    ];

    private function makeAudit(): Audit
    {
        $website = Website::create([
            'name' => 'example.com', 'primary_url' => 'https://example.com/',
            'domain' => 'example.com', 'normalized_domain' => 'example.com',
        ]);

        return Audit::create(['website_id' => $website->id, 'status' => 'analyzing', 'max_pages' => 10]);
    }

    private function addPage(Audit $audit, array $attributes = []): AuditPage
    {
        return AuditPage::create(array_merge([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'title' => 'A Perfectly Fine Page Title',
            'meta_description' => str_repeat('a', 100),
            'canonical_url' => 'https://example.com/',
            'headings' => [['level' => 1, 'text' => 'Welcome']],
            'images' => [],
            'links' => [],
            'redirect_chain' => [],
            'structured_data' => [self::ORG_SCHEMA],
        ], $attributes));
    }

    private function healthySignals(Audit $audit): void
    {
        AuditSiteSignals::create([
            'audit_id' => $audit->id,
            'robots_txt_url' => 'https://example.com/robots.txt',
            'robots_txt_found' => true,
            'robots_disallow_count' => 0,
            'robots_sitemaps' => ['https://example.com/sitemap.xml'],
            'sitemap_found' => true,
            'sitemap_urls' => ['https://example.com/'],
            'sitemap_url_count' => 1,
            'sitemap_declared_in_robots' => true,
        ]);
    }

    private function runModule(Audit $audit)
    {
        return app(SeoAuditModule::class)->run($audit->fresh());
    }

    private function codes(Audit $audit): array
    {
        return collect($this->runModule($audit)->issues)->pluck('code')->all();
    }

    // --- Indexability ---

    public function test_a_noindex_meta_tag_excludes_a_page_from_search(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, ['meta_robots' => 'noindex, follow']);

        $issue = collect($this->runModule($audit)->issues)->firstWhere('code', 'seo.not_indexable');

        $this->assertNotNull($issue);
        $this->assertStringContainsString('meta name="robots"', $issue->meta['reasons'][0]);
    }

    public function test_an_x_robots_tag_header_excludes_a_page_that_looks_fine_in_the_markup(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, ['response_headers' => ['x-robots-tag' => 'noindex']]);

        $issue = collect($this->runModule($audit)->issues)->firstWhere('code', 'seo.not_indexable');

        // The whole point of reading headers: nothing in the HTML says
        // this page is excluded, so an owner cannot find it by viewing
        // source.
        $this->assertNotNull($issue);
        $this->assertStringContainsString('X-Robots-Tag', $issue->meta['reasons'][0]);
        $this->assertStringContainsString('does not appear anywhere in the page markup', $issue->meta['reasons'][0]);
    }

    public function test_a_canonical_pointing_at_another_page_makes_this_one_non_indexable(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, [
            'url' => 'https://example.com/product?colour=red',
            'canonical_url' => 'https://example.com/product',
        ]);

        $issue = collect($this->runModule($audit)->issues)->firstWhere('code', 'seo.not_indexable');

        $this->assertNotNull($issue);
        $this->assertStringContainsString('index that page instead', $issue->meta['reasons'][0]);
    }

    public function test_a_self_referencing_canonical_is_not_a_problem(): void
    {
        $audit = $this->makeAudit();
        $this->healthySignals($audit);
        // Trailing slash and case differences are formatting, not a
        // different page. Flagging these would bury the real cases.
        $this->addPage($audit, [
            'url' => 'https://example.com/about',
            'canonical_url' => 'https://Example.com/about/',
        ]);

        $this->assertNotContains('seo.not_indexable', $this->codes($audit));
    }

    public function test_an_error_page_is_reported_once_as_a_status_problem(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, [
            'url' => 'https://example.com/gone',
            'http_status' => 404,
            'canonical_url' => 'https://example.com/gone',
        ]);

        $codes = $this->codes($audit);

        // A 404 is already a status finding with a better message;
        // reporting it a second time as "not indexable" would double-count
        // one problem and double the score damage.
        $this->assertContains('seo.client_error_page', $codes);
        $this->assertNotContains('seo.not_indexable', $codes);
    }

    // --- Robots.txt and sitemap ---

    public function test_a_missing_robots_txt_and_sitemap_are_reported(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit);
        AuditSiteSignals::create([
            'audit_id' => $audit->id,
            'robots_txt_found' => false,
            'sitemap_found' => false,
        ]);

        $codes = $this->codes($audit);

        $this->assertContains('seo.missing_robots_txt', $codes);
        $this->assertContains('seo.missing_sitemap', $codes);
    }

    public function test_a_failed_robots_fetch_is_not_reported_as_a_missing_file(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit);
        AuditSiteSignals::create([
            'audit_id' => $audit->id,
            // Null: the request itself failed. Telling an owner to create
            // a file that may already exist is worse than saying nothing.
            'robots_txt_found' => null,
            'sitemap_found' => null,
        ]);

        $codes = $this->codes($audit);

        $this->assertNotContains('seo.missing_robots_txt', $codes);
        $this->assertNotContains('seo.missing_sitemap', $codes);
    }

    public function test_a_sitemap_found_only_by_guessing_is_reported_as_undeclared(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit);
        AuditSiteSignals::create([
            'audit_id' => $audit->id,
            'robots_txt_found' => true,
            'sitemap_found' => true,
            'sitemap_url_count' => 12,
            'sitemap_declared_in_robots' => false,
        ]);

        $this->assertContains('seo.sitemap_not_declared', $this->codes($audit));
    }

    // --- Redirects ---

    public function test_a_redirect_chain_is_reported_with_its_hops_and_types(): void
    {
        $audit = $this->makeAudit();
        $this->healthySignals($audit);
        $this->addPage($audit, [
            'url' => 'https://example.com/final',
            'redirect_chain' => [
                ['url' => 'http://example.com/old', 'status' => 301],
                ['url' => 'https://example.com/older', 'status' => 302],
            ],
        ]);

        $issue = collect($this->runModule($audit)->issues)->firstWhere('code', 'seo.redirect_chain');

        $this->assertNotNull($issue);
        $this->assertSame(2, $issue->meta['redirects'][0]['hop_count']);
        $this->assertSame('permanent', $issue->meta['redirects'][0]['hops'][0]['type']);
        $this->assertSame('temporary', $issue->meta['redirects'][0]['hops'][1]['type']);
    }

    public function test_a_single_redirect_is_not_called_a_chain(): void
    {
        $audit = $this->makeAudit();
        $this->healthySignals($audit);
        $this->addPage($audit, [
            'url' => 'https://example.com/page',
            'redirect_chain' => [['url' => 'http://example.com/page', 'status' => 301]],
        ]);

        // http -> https is one hop and entirely correct. Calling it a
        // chain would flag almost every site on the web.
        $this->assertNotContains('seo.redirect_chain', $this->codes($audit));
    }

    public function test_a_redirect_loop_is_reported_as_critical(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, [
            'url' => 'https://example.com/loop',
            'redirect_chain' => [
                ['url' => 'https://example.com/loop', 'status' => 302],
                ['url' => 'https://example.com/other', 'status' => 302],
            ],
        ]);

        $issue = collect($this->runModule($audit)->issues)->firstWhere('code', 'seo.redirect_loop');

        $this->assertNotNull($issue);
        $this->assertSame('critical', $issue->severity->value);
    }

    public function test_a_temporary_redirect_is_flagged_only_when_the_status_was_recorded(): void
    {
        $audit = $this->makeAudit();
        $this->healthySignals($audit);
        // Pre-§44 shape: a bare URL with no status. Guessing 301 or 302
        // here would be inventing the one fact that matters.
        $this->addPage($audit, [
            'url' => 'https://example.com/legacy',
            'redirect_chain' => ['https://example.com/old-legacy'],
        ]);

        $this->assertNotContains('seo.temporary_redirect', $this->codes($audit));

        $hops = app(RedirectAnalyzer::class)->hops(AuditPage::where('url', 'https://example.com/legacy')->first());
        $this->assertSame('unknown', $hops[0]['type']);
        $this->assertNull($hops[0]['status']);
    }

    // --- Links ---

    public function test_a_broken_internal_link_names_the_page_it_is_on(): void
    {
        $audit = $this->makeAudit();
        $this->healthySignals($audit);
        $this->addPage($audit, [
            'url' => 'https://example.com/',
            'links' => [
                ['href' => 'https://example.com/gone', 'text' => 'Old page'],
                ['href' => 'https://example.com/fine', 'text' => 'Fine'],
                ['href' => 'https://elsewhere.test/x', 'text' => 'Off-site'],
            ],
        ]);
        $this->addPage($audit, ['url' => 'https://example.com/gone', 'http_status' => 404]);
        $this->addPage($audit, ['url' => 'https://example.com/fine']);

        $issue = collect($this->runModule($audit)->issues)->firstWhere('code', 'seo.broken_internal_links');

        $this->assertNotNull($issue);
        $this->assertSame('https://example.com/', $issue->meta['broken_links'][0]['from']);
        $this->assertSame(404, $issue->meta['broken_links'][0]['status']);
        $this->assertSame('Old page', $issue->meta['broken_links'][0]['anchor_text']);
    }

    public function test_a_link_to_an_uncrawled_page_is_never_called_broken(): void
    {
        $audit = $this->makeAudit();
        $this->healthySignals($audit);
        $this->addPage($audit, [
            'links' => [['href' => 'https://example.com/never-crawled', 'text' => 'Deep page']],
        ]);

        // Asserting a link is broken without having fetched its target
        // would be a guess presented as a finding.
        $this->assertNotContains('seo.broken_internal_links', $this->codes($audit));
    }

    public function test_orphan_pages_are_pages_nothing_links_to(): void
    {
        $audit = $this->makeAudit();
        $this->healthySignals($audit);
        $this->addPage($audit, [
            'url' => 'https://example.com/',
            'links' => [['href' => 'https://example.com/linked', 'text' => 'Linked']],
        ]);
        $this->addPage($audit, ['url' => 'https://example.com/linked']);
        $this->addPage($audit, ['url' => 'https://example.com/orphan']);

        $issue = collect($this->runModule($audit)->issues)->firstWhere('code', 'seo.orphan_pages');

        $this->assertNotNull($issue);
        // The entry point is never an orphan — nothing links to a homepage
        // from above it.
        $this->assertSame(['https://example.com/orphan'], $issue->meta['affected_pages']);
    }

    public function test_www_and_bare_domain_links_count_as_internal(): void
    {
        $audit = $this->makeAudit();
        $links = new LinkGraph($this->auditWithLinks($audit), 'example.com');

        $this->assertCount(2, $links->internalLinks());
        $this->assertCount(1, $links->externalLinks());
    }

    private function auditWithLinks(Audit $audit): Audit
    {
        $this->addPage($audit, [
            'links' => [
                ['href' => 'https://www.example.com/a', 'text' => 'With www'],
                ['href' => 'https://blog.example.com/b', 'text' => 'Subdomain'],
                ['href' => 'https://other.test/c', 'text' => 'External'],
            ],
        ]);

        return $audit->fresh();
    }

    public function test_a_nofollow_link_to_your_own_page_is_reported(): void
    {
        $audit = $this->makeAudit();
        $this->healthySignals($audit);
        $this->addPage($audit, [
            'links' => [['href' => 'https://example.com/a', 'text' => 'Internal', 'rel' => 'nofollow noopener']],
        ]);

        $this->assertContains('seo.nofollow_internal_links', $this->codes($audit));
    }

    // --- Social cards ---

    public function test_missing_open_graph_tags_are_reported_with_the_predicted_preview(): void
    {
        $audit = $this->makeAudit();
        $this->healthySignals($audit);
        $this->addPage($audit, ['open_graph' => [], 'twitter_card' => []]);

        $issue = collect($this->runModule($audit)->issues)->firstWhere('code', 'seo.missing_open_graph');

        $this->assertNotNull($issue);
        // The preview shows what will actually be shared: the platforms
        // fall back to <title>, so the card is not blank.
        $this->assertSame('A Perfectly Fine Page Title', $issue->meta['preview']['title']['value']);
        $this->assertSame('<title>', $issue->meta['preview']['title']['source']);
        $this->assertTrue($issue->meta['preview']['title']['is_fallback']);
        $this->assertNull($issue->meta['preview']['image']['value']);
    }

    public function test_a_page_crawled_before_social_tags_were_captured_is_not_reported(): void
    {
        $audit = $this->makeAudit();
        $this->healthySignals($audit);
        // Null, not an empty map: nobody looked at this page's meta tags.
        $this->addPage($audit, ['open_graph' => null, 'twitter_card' => null]);

        $codes = $this->codes($audit);

        $this->assertNotContains('seo.missing_open_graph', $codes);
        $this->assertNotContains('seo.missing_twitter_card', $codes);
    }

    public function test_twitter_falls_back_to_open_graph_but_still_needs_a_card_type(): void
    {
        $audit = $this->makeAudit();
        $this->healthySignals($audit);
        $page = $this->addPage($audit, [
            'open_graph' => [
                'og:title' => 'Shared title',
                'og:description' => 'Shared description',
                'og:image' => 'https://example.com/card.png',
                'og:url' => 'https://example.com/',
            ],
            'twitter_card' => [],
        ]);

        $codes = $this->codes($audit);
        $this->assertNotContains('seo.incomplete_open_graph', $codes);
        $this->assertContains('seo.missing_twitter_card', $codes);

        $preview = app(SocialCardAnalyzer::class)->analyze($page->fresh())['preview'];
        $this->assertSame('Shared title', $preview['title']['value']);
        $this->assertSame('og:title', $preview['title']['source']);
    }

    // --- Structured data ---

    public function test_a_product_missing_required_properties_is_reported(): void
    {
        $audit = $this->makeAudit();
        $this->healthySignals($audit);
        $this->addPage($audit, [
            'structured_data' => [
                self::ORG_SCHEMA,
                ['@type' => 'Product', 'name' => 'A thing'],
            ],
        ]);

        $issue = collect($this->runModule($audit)->issues)->firstWhere('code', 'seo.schema_incomplete.Product');

        $this->assertNotNull($issue);
        $this->assertSame(['offers'], $issue->meta['missing_required']);
    }

    public function test_a_subtype_is_audited_as_the_type_it_inherits_from(): void
    {
        $analyzer = app(SchemaAnalyzer::class);

        // A Restaurant is a LocalBusiness; falling through to the generic
        // bucket would leave a real business type unaudited.
        $this->assertSame('LocalBusiness', $analyzer->canonicalType('Restaurant'));
        $this->assertSame('Article', $analyzer->canonicalType('BlogPosting'));
        $this->assertSame('Product', $analyzer->canonicalType(['Thing', 'Product']));
        $this->assertSame('Organization', $analyzer->canonicalType('https://schema.org/Organization'));
        $this->assertNull($analyzer->canonicalType('SomeTypeWeDoNotAudit'));
    }

    public function test_schema_nested_in_a_graph_is_found(): void
    {
        $audit = $this->makeAudit();
        $page = $this->addPage($audit, [
            'structured_data' => [[
                '@context' => 'https://schema.org',
                '@graph' => [
                    ['@type' => 'WebSite', 'name' => 'Example'],
                    self::ORG_SCHEMA,
                    ['@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'name' => 'Home']]],
                ],
            ]],
        ]);

        $types = app(SchemaAnalyzer::class)->typesOn($page);

        $this->assertContains('Organization', $types);
        $this->assertContains('BreadcrumbList', $types);
    }

    public function test_a_declared_but_empty_property_does_not_count_as_present(): void
    {
        $audit = $this->makeAudit();
        $this->healthySignals($audit);
        $this->addPage($audit, [
            'structured_data' => [
                self::ORG_SCHEMA,
                ['@type' => 'Article', 'headline' => '', 'author' => []],
            ],
        ]);

        $issue = collect($this->runModule($audit)->issues)->firstWhere('code', 'seo.schema_incomplete.Article');

        // An empty headline is worth exactly what no headline is worth.
        $this->assertNotNull($issue);
        $this->assertSame(['headline'], $issue->meta['missing_required']);
    }

    public function test_a_site_with_no_organization_schema_is_told_so_once(): void
    {
        $audit = $this->makeAudit();
        $this->healthySignals($audit);
        $this->addPage($audit, ['url' => 'https://example.com/', 'structured_data' => []]);
        $this->addPage($audit, ['url' => 'https://example.com/a', 'structured_data' => []]);

        $issues = collect($this->runModule($audit)->issues)->where('code', 'seo.missing_schema.Organization');

        $this->assertCount(1, $issues, 'a site-wide expectation is one finding, not one per page');
        $this->assertStringContainsString('Only JSON-LD', $issues->first()->meta['note']);
    }

    // --- Headings and images ---

    public function test_a_skipped_heading_level_is_reported_but_going_back_up_is_not(): void
    {
        $audit = $this->makeAudit();
        $this->healthySignals($audit);
        $this->addPage($audit, [
            'headings' => [
                ['level' => 1, 'text' => 'Title'],
                ['level' => 2, 'text' => 'Section'],
                ['level' => 4, 'text' => 'Skipped past H3'],
                ['level' => 2, 'text' => 'Back up is fine'],
            ],
        ]);

        $issue = collect($this->runModule($audit)->issues)->firstWhere('code', 'seo.heading_hierarchy_skip');

        $this->assertNotNull($issue);
        $this->assertSame(['H2 to H4'], $issue->meta['skips']);
    }

    public function test_images_without_declared_dimensions_are_reported_only_when_captured(): void
    {
        $audit = $this->makeAudit();
        $this->healthySignals($audit);
        $this->addPage($audit, [
            'url' => 'https://example.com/new',
            'images' => [
                ['src' => 'a.png', 'alt' => 'Fine', 'declaredWidth' => null, 'declaredHeight' => null],
            ],
        ]);
        $this->addPage($audit, [
            'url' => 'https://example.com/old',
            // Pre-§44 shape: the attribute was never recorded.
            'images' => [['src' => 'b.png', 'alt' => 'Fine']],
        ]);

        $issue = collect($this->runModule($audit)->issues)->firstWhere('code', 'seo.image_missing_dimensions');

        // Exactly one page qualifies, so the finding stays attached to
        // that page rather than becoming a site-wide list.
        $this->assertNotNull($issue);
        $this->assertSame('https://example.com/new', $issue->meta['url']);
        $this->assertSame(1, $issue->meta['count']);
    }

    // --- Coverage ---

    public function test_the_breakdown_says_which_checks_actually_ran(): void
    {
        $audit = $this->makeAudit();
        $this->healthySignals($audit);
        $this->addPage($audit, ['open_graph' => null, 'twitter_card' => null, 'meta_robots' => null]);

        $checks = collect($this->runModule($audit)->breakdown['coverage']['checks'])->keyBy('check');

        $this->assertCount(24, $checks, 'every named check is accounted for');
        $this->assertTrue($checks['title']['ran']);
        // A check that never ran must not look like a check that passed.
        $this->assertFalse($checks['og_tags']['ran']);
        $this->assertStringContainsString('Re-run the audit', $checks['og_tags']['not_run_reason']);
    }

    public function test_indexability_directives_are_reported_verbatim(): void
    {
        $audit = $this->makeAudit();
        $page = $this->addPage($audit, [
            'meta_robots' => 'NoIndex, NoFollow',
            'response_headers' => ['x-robots-tag' => 'noarchive'],
        ]);

        $verdict = app(IndexabilityResolver::class)->resolve($page);

        // Stored as served, not normalized into our own vocabulary.
        $this->assertSame('NoIndex, NoFollow', $verdict['directives']['meta_robots']);
        $this->assertSame('noarchive', $verdict['directives']['x_robots_tag']);
        $this->assertFalse($verdict['is_indexable']);
    }
}
