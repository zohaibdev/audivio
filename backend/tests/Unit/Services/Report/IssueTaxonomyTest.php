<?php

namespace Tests\Unit\Services\Report;

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
            ['performance.excessive_dom_size', 'Render Blocking'],
        ];
    }

    public function test_axe_rules_map_to_accessibility_categories(): void
    {
        $this->assertSame('Contrast', $this->taxonomy->categoryFor(AuditCategory::Accessibility, 'axe.color-contrast'));
        $this->assertSame('Images', $this->taxonomy->categoryFor(AuditCategory::Accessibility, 'axe.image-alt'));
        $this->assertSame('ARIA', $this->taxonomy->categoryFor(AuditCategory::Accessibility, 'axe.aria-allowed-role'));
    }

    public function test_an_unknown_axe_rule_falls_back_rather_than_disappearing(): void
    {
        $this->assertSame(
            'Other Accessibility',
            $this->taxonomy->categoryFor(AuditCategory::Accessibility, 'axe.some-brand-new-rule'),
        );
    }

    public function test_an_ai_invented_code_falls_back_to_its_services_bucket(): void
    {
        $this->assertSame('Conversion Friction', $this->taxonomy->categoryFor(AuditCategory::Conversion, 'ai.conversion.whatever'));
        $this->assertTrue($this->taxonomy->isAiGenerated('ai.conversion.whatever'));
        $this->assertFalse($this->taxonomy->isAiGenerated('conversion.form_friction'));
    }
}
