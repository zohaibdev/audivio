<?php

namespace Tests\Unit\Services\Audit;

use App\Models\Audit;
use App\Models\AuditPage;
use App\Models\Website;
use App\Services\Audit\ProductAuditModule;
use App\Services\Audit\ProductPageDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductAuditModuleTest extends TestCase
{
    use RefreshDatabase;

    private function makeAudit(): Audit
    {
        $website = Website::create(['primary_url' => 'https://shop.example', 'domain' => 'shop.example', 'normalized_domain' => 'shop.example']);

        return Audit::create(['website_id' => $website->id, 'status' => 'analyzing', 'max_pages' => 10]);
    }

    private function module(): ProductAuditModule
    {
        return new ProductAuditModule(new ProductPageDetector);
    }

    private function productPage(Audit $audit, array $attributes = []): AuditPage
    {
        return AuditPage::create(array_merge([
            'audit_id' => $audit->id,
            'url' => 'https://shop.example/product/widget',
            'http_status' => 200,
            'title' => 'Widget',
            'meta_description' => str_repeat('A really good widget. ', 20),
            'images' => [['src' => 'a.jpg'], ['src' => 'b.jpg'], ['src' => 'c.jpg']],
            'cta_elements' => [['text' => 'Add to Cart']],
            'structured_data' => [[
                '@type' => 'Product',
                'name' => 'Widget',
                'description' => str_repeat('A really good widget. ', 20),
                'offers' => ['price' => '19.99', 'availability' => 'InStock'],
                'aggregateRating' => ['ratingValue' => 4.5],
            ]],
        ], $attributes));
    }

    public function test_a_site_with_no_product_pages_is_not_applicable(): void
    {
        $audit = $this->makeAudit();
        AuditPage::create([
            'audit_id' => $audit->id, 'url' => 'https://shop.example/about',
            'http_status' => 200, 'images' => [], 'cta_elements' => [], 'structured_data' => [],
        ]);

        $result = $this->module()->run($audit->fresh());

        $this->assertNull($result->score, 'no products means no score, not a zero');
        $this->assertFalse($result->isApplicable);
        $this->assertCount(0, $result->issues);
    }

    public function test_a_fully_specified_product_page_scores_100(): void
    {
        $audit = $this->makeAudit();
        $this->productPage($audit);

        $result = $this->module()->run($audit->fresh());

        $this->assertTrue($result->isApplicable);
        $this->assertSame(100, $result->score);
        $this->assertCount(0, $result->issues);
    }

    public function test_a_product_page_is_detected_from_schema_alone(): void
    {
        $audit = $this->makeAudit();
        // No product-shaped URL and no add-to-cart CTA — the Product
        // schema by itself is definitive.
        $this->productPage($audit, ['url' => 'https://shop.example/w', 'cta_elements' => []]);

        $result = $this->module()->run($audit->fresh());

        $this->assertTrue($result->isApplicable);
        $this->assertTrue(collect($result->issues)->pluck('code')->contains('product.no_add_to_cart'));
    }

    /**
     * A product-shaped URL alone is not enough — /shop/ and /products/
     * listing pages routinely match, and a site-wide nav "Add to cart"
     * link would otherwise make every page look like a product.
     */
    public function test_a_product_shaped_url_without_a_buy_signal_is_not_a_product_page(): void
    {
        $audit = $this->makeAudit();
        AuditPage::create([
            'audit_id' => $audit->id, 'url' => 'https://shop.example/products/listing',
            'http_status' => 200, 'images' => [], 'cta_elements' => [], 'structured_data' => [],
        ]);

        $result = $this->module()->run($audit->fresh());

        $this->assertFalse($result->isApplicable);
    }

    public function test_missing_price_and_availability_are_flagged(): void
    {
        $audit = $this->makeAudit();
        $this->productPage($audit, [
            'structured_data' => [['@type' => 'Product', 'name' => 'Widget', 'description' => str_repeat('x', 300)]],
        ]);

        $codes = collect($this->module()->run($audit->fresh())->issues)->pluck('code');

        $this->assertTrue($codes->contains('product.missing_price'));
        $this->assertTrue($codes->contains('product.missing_availability'));
    }

    public function test_a_product_page_with_no_images_is_critical(): void
    {
        $audit = $this->makeAudit();
        $this->productPage($audit, ['images' => []]);

        $issue = collect($this->module()->run($audit->fresh())->issues)
            ->firstWhere('code', 'product.no_images');

        $this->assertNotNull($issue);
        $this->assertSame('critical', $issue->severity->value);
    }

    public function test_too_few_images_and_thin_copy_are_flagged(): void
    {
        $audit = $this->makeAudit();
        $this->productPage($audit, [
            'images' => [['src' => 'a.jpg']],
            'meta_description' => 'Short.',
            'structured_data' => [[
                '@type' => 'Product', 'name' => 'Widget', 'description' => 'Short.',
                'offers' => ['price' => '9.99', 'availability' => 'InStock'],
            ]],
        ]);

        $codes = collect($this->module()->run($audit->fresh())->issues)->pluck('code');

        $this->assertTrue($codes->contains('product.too_few_images'));
        $this->assertTrue($codes->contains('product.thin_description'));
    }

    /**
     * A page with no Product schema already gets missing_product_schema;
     * also reporting missing price/availability/reviews would be three
     * extra findings for one root cause.
     */
    public function test_a_missing_schema_is_reported_once_not_as_several_derived_findings(): void
    {
        $audit = $this->makeAudit();
        $this->productPage($audit, ['structured_data' => []]);

        $codes = collect($this->module()->run($audit->fresh())->issues)->pluck('code');

        $this->assertTrue($codes->contains('product.missing_product_schema'));
        $this->assertFalse($codes->contains('product.missing_price'));
        $this->assertFalse($codes->contains('product.missing_availability'));
        $this->assertFalse($codes->contains('product.no_review_data'));
    }

    public function test_findings_across_several_product_pages_are_aggregated(): void
    {
        $audit = $this->makeAudit();
        foreach (['a', 'b', 'c'] as $slug) {
            $this->productPage($audit, [
                'url' => "https://shop.example/product/{$slug}",
                'images' => [],
            ]);
        }

        $issues = collect($this->module()->run($audit->fresh())->issues);
        $noImages = $issues->where('code', 'product.no_images');

        $this->assertCount(1, $noImages, 'the same defect on 3 products is one finding, not three');
        $this->assertSame(3, $noImages->first()->meta['affected_page_count']);
    }
}
