<?php

namespace Tests\Unit\Services\Audit;

use App\Models\Audit;
use App\Models\AuditPage;
use App\Models\Website;
use App\Services\Audit\EcommerceAuditModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EcommerceAuditModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.providers.claude.api_key' => null]); // exercise the no-API-key fallback path
    }

    private function makeAudit(string $platform = 'custom'): Audit
    {
        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com', 'platform' => $platform]);

        return Audit::create(['website_id' => $website->id, 'status' => 'analyzing', 'max_pages' => 5]);
    }

    public function test_non_ecommerce_site_scores_100_with_no_issues(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'cta_elements' => [['text' => 'Learn more']],
            'structured_data' => [],
        ]);

        $result = (new EcommerceAuditModule(app(\App\Services\AI\AiAnalysisService::class)))->run($audit->fresh());

        $this->assertSame(100, $result->score);
        $this->assertCount(0, $result->issues);
    }

    public function test_purchase_cta_without_product_schema_flagged(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/product',
            'http_status' => 200,
            'cta_elements' => [['text' => 'Add to Cart']],
            'structured_data' => [],
        ]);

        $result = (new EcommerceAuditModule(app(\App\Services\AI\AiAnalysisService::class)))->run($audit->fresh());

        $codes = collect($result->issues)->pluck('code');
        $this->assertTrue($codes->contains('ecommerce.missing_product_schema'));
        $this->assertFalse($codes->contains('ecommerce.missing_purchase_cta'));
    }

    public function test_product_schema_without_purchase_cta_flagged(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/product',
            'http_status' => 200,
            'cta_elements' => [],
            'structured_data' => [['@type' => 'Product', 'name' => 'Widget']],
        ]);

        $result = (new EcommerceAuditModule(app(\App\Services\AI\AiAnalysisService::class)))->run($audit->fresh());

        $codes = collect($result->issues)->pluck('code');
        $this->assertTrue($codes->contains('ecommerce.missing_purchase_cta'));
        $this->assertFalse($codes->contains('ecommerce.missing_product_schema'));
    }

    public function test_shopify_platform_treated_as_ecommerce_even_without_signals(): void
    {
        $audit = $this->makeAudit(platform: 'shopify');

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'cta_elements' => [],
            'structured_data' => [],
        ]);

        $result = (new EcommerceAuditModule(app(\App\Services\AI\AiAnalysisService::class)))->run($audit->fresh());

        $codes = collect($result->issues)->pluck('code');
        $this->assertTrue($codes->contains('ecommerce.missing_product_schema'));
        $this->assertTrue($codes->contains('ecommerce.missing_purchase_cta'));
    }
}
