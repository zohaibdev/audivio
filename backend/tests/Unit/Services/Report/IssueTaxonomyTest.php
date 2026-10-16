<?php

namespace Tests\Unit\Services\Report;

use App\Enums\AccessibilityCheck;
use App\Enums\AuditCategory;
use App\Services\Report\IssueTaxonomy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IssueTaxonomyTest extends TestCase
{
    private IssueTaxonomy $taxonomy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->taxonomy = new IssueTaxonomy;
    }

    public function test_service_is_resolved_from_the_stored_category_not_the_code(): void
    {
        // Even a code whose prefix looks like another service resolves by
        // its stored category — the column is what the producing module
        // wrote, so it is the reliable source.
        $this->assertSame('seo', $this->taxonomy->serviceFor(AuditCategory::Seo));
        $this->assertSame('performance', $this->taxonomy->serviceFor(AuditCategory::Performance));
        $this->assertSame('cro', $this->taxonomy->serviceFor(AuditCategory::Conversion));
        $this->assertSame('cro', $this->taxonomy->serviceFor(AuditCategory::LandingPage));
        $this->assertNull($this->taxonomy->serviceFor(AuditCategory::Overall));
    }

    /**
     * Every performance issue code the module can emit must map to a real
     * category. Without this, new resource checks silently pile into the
     * "Other Performance" bucket — which is exactly what happened when the
     * resource breakdown shipped before the taxonomy was updated.
     */
    #[DataProvider('performanceCodes')]
    public function test_every_performance_code_maps_to_a_specific_category(string $code, string $expected): void
    {
        $this->assertSame($expected, $this->taxonomy->categoryFor(AuditCategory::Performance, $code));
    }

    public static function performanceCodes(): array
    {
        return [
            ['performance.lcp', 'Core Web Vitals'],
            ['performance.cls', 'Core Web Vitals'],
            ['performance.pagespeed_score', 'Loading Performance'],
            ['performance.slow_load_time', 'Loading Performance'],
            ['performance.large_html', 'Page Weight'],
            ['performance.heavy_page_weight', 'Page Weight'],
            ['performance.missing_image_dimensions', 'Images'],
            ['performance.heavy_images', 'Images'],
            ['performance.large_javascript', 'JavaScript'],
            ['performance.large_css', 'CSS'],
            ['performance.too_many_fonts', 'Fonts'],
            ['performance.too_many_requests', 'Network'],
            ['performance.slow_server_response', 'Server Response'],
            ['performance.third_party_weight', 'Third-Party Resources'],
            // DOM size is a DOM finding, not a render-blocking one — it
            // was mislabelled while "DOM" had no category of its own.
            ['performance.excessive_dom_size', 'DOM'],
            ['performance.no_cache_headers', 'Caching'],
            ['performance.no_compression', 'Compression'],
        ];
    }

    /**
     * The issue list and the §43 accessibility rollup must group a rule
     * the same way. The taxonomy used to keep its own thinner copy of the
     * axe map, so a rule known to one and not the other was grouped as
     * "Contrast" in the rollup and "Other Accessibility" in the issue
     * list of the same report.
     */
    public function test_axe_rules_group_the_same_way_the_accessibility_rollup_does(): void
    {
        foreach (['color-contrast', 'image-alt', 'aria-allowed-role', 'td-headers-attr', 'html-has-lang'] as $rule) {
            $this->assertSame(
                AccessibilityCheck::forAxeRule($rule)->label(),
                $this->taxonomy->categoryFor(AuditCategory::Accessibility, "axe.{$rule}"),
                "axe rule {$rule}",
            );
        }
    }

    /**
     * Every SEO code the module can emit maps to a real category — the
     * same guard the performance codes have above, for the same reason.
     */
    #[DataProvider('seoCodes')]
    public function test_every_seo_code_maps_to_a_specific_category(string $code, string $expected): void
    {
        $this->assertSame($expected, $this->taxonomy->categoryFor(AuditCategory::Seo, $code));
    }

    public static function seoCodes(): array
    {
        return [
            ['seo.missing_title', 'Metadata'],
            ['seo.duplicate_meta_description', 'Metadata'],
            ['seo.missing_canonical', 'Canonical'],
            ['seo.not_indexable', 'Indexability'],
            ['seo.client_error_page', 'Indexability'],
            ['seo.server_error_page', 'Indexability'],
            ['seo.missing_robots_txt', 'Crawlability'],
            ['seo.missing_sitemap', 'Crawlability'],
            ['seo.sitemap_not_declared', 'Crawlability'],
            ['seo.heading_hierarchy_skip', 'Headings'],
            ['seo.image_missing_dimensions', 'Images'],
            ['seo.broken_internal_links', 'Links'],
            ['seo.internal_links_to_redirects', 'Links'],
            ['seo.nofollow_internal_links', 'Links'],
            ['seo.few_internal_links', 'Links'],
            ['seo.orphan_pages', 'Links'],
            ['seo.redirect_chain', 'Redirects'],
            ['seo.redirect_loop', 'Redirects'],
            ['seo.temporary_redirect', 'Redirects'],
            ['seo.missing_open_graph', 'Social Sharing'],
            ['seo.incomplete_open_graph', 'Social Sharing'],
            ['seo.missing_twitter_card', 'Social Sharing'],
            // Schema codes carry the type as a suffix, so they are matched
            // by prefix — adding a type to config/seo_schema.php must not
            // silently drop its findings into the catch-all.
            ['seo.schema_incomplete.Product', 'Structured Data'],
            ['seo.schema_recommended.Article', 'Structured Data'],
            ['seo.missing_schema.Organization', 'Structured Data'],
        ];
    }

    public function test_an_unknown_axe_rule_falls_back_rather_than_disappearing(): void
    {
        $this->assertSame(
            'Other Accessibility',
            $this->taxonomy->categoryFor(AuditCategory::Accessibility, 'axe.some-brand-new-rule'),
        );
    }

    /**
     * Every CRO and ecommerce code the modules can emit maps to a real
     * category — the same guard the performance and SEO codes have.
     */
    #[DataProvider('conversionCodes')]
    public function test_every_conversion_code_maps_to_a_specific_category(AuditCategory $category, string $code, string $expected): void
    {
        $this->assertSame($expected, $this->taxonomy->categoryFor($category, $code));
    }

    public static function conversionCodes(): array
    {
        return [
            [AuditCategory::Conversion, 'conversion.long_forms', 'Forms'],
            [AuditCategory::Conversion, 'conversion.pages_without_cta', 'CTA'],
            [AuditCategory::Conversion, 'conversion.vague_cta_text', 'CTA'],
            [AuditCategory::Conversion, 'conversion.wide_navigation', 'Navigation'],
            [AuditCategory::Conversion, 'conversion.thin_navigation', 'Navigation'],
            [AuditCategory::Conversion, 'conversion.no_contact_route', 'Trust Signals'],
            [AuditCategory::Conversion, 'conversion.no_privacy_policy', 'Trust Signals'],
            [AuditCategory::Conversion, 'conversion.no_social_proof', 'Social Proof'],
            [AuditCategory::Conversion, 'conversion.no_tap_to_call', 'Mobile Conversion'],
            [AuditCategory::Conversion, 'conversion.small_tap_targets', 'Mobile Conversion'],
            [AuditCategory::Ecommerce, 'ecommerce.no_cart_route_found', 'Checkout Signals'],
            [AuditCategory::Ecommerce, 'ecommerce.missing_shipping_information', 'Shipping'],
            [AuditCategory::Ecommerce, 'ecommerce.missing_returns_information', 'Returns'],
            [AuditCategory::Ecommerce, 'ecommerce.no_review_data', 'Trust'],
        ];
    }

    public function test_an_ai_invented_code_falls_back_to_its_services_bucket(): void
    {
        $this->assertSame('Conversion Friction', $this->taxonomy->categoryFor(AuditCategory::Conversion, 'ai.conversion.whatever'));
        $this->assertTrue($this->taxonomy->isAiGenerated('ai.conversion.whatever'));
        $this->assertFalse($this->taxonomy->isAiGenerated('conversion.form_friction'));
    }
}
