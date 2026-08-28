<?php

namespace Tests\Unit\Services\Audit;

use App\Models\Audit;
use App\Models\AuditPage;
use App\Models\Website;
use App\Services\AI\AiAnalysisService;
use App\Services\Audit\EcommerceAuditModule;
use App\Services\Audit\EcommerceDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EcommerceAuditModuleTest extends TestCase
{
    use RefreshDatabase;

    private function makeAudit(string $platform = 'custom'): Audit
    {
        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com', 'platform' => $platform]);

        return Audit::create(['website_id' => $website->id, 'status' => 'analyzing', 'max_pages' => 5]);
    }

    private function module(): EcommerceAuditModule
    {
        return new EcommerceAuditModule(app(AiAnalysisService::class), new EcommerceDetector);
    }

    public function test_non_ecommerce_site_is_not_applicable_with_no_score(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'cta_elements' => [['text' => 'Learn more']],
            'structured_data' => [],
        ]);

        $result = $this->module()->run($audit->fresh());

        $this->assertNull($result->score);
        $this->assertFalse($result->isApplicable);
        $this->assertNotNull($result->notApplicableReason);
        $this->assertCount(0, $result->issues);
    }

    /**
     * The exact false-positive this detector was rewritten to fix: a
     * generic transaction-adjacent word (here "Checkout," as a nonprofit's
     * event-ticket order button might say) must not, on its own, be
     * enough to treat the site as ecommerce.
     */
    public function test_a_lone_generic_checkout_cta_does_not_trigger_ecommerce_detection(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/tickets-checkout/',
            'http_status' => 200,
            'cta_elements' => [['text' => 'Checkout']],
            'structured_data' => [],
        ]);

        $result = $this->module()->run($audit->fresh());

        $this->assertFalse($result->isApplicable);
        $this->assertNull($result->score);
    }

    /**
     * A second real false positive found by re-running a real audit
     * against the fix above: a nonprofit's event-ticket flow has both a
     * "/tickets/checkout/"-shaped link AND a generic CTA on the same
     * page — two weak signals that used to sum to the old threshold with
     * zero actual product evidence anywhere on the site.
     */
    public function test_a_checkout_link_plus_a_generic_cta_alone_does_not_trigger_ecommerce_detection(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/tickets/checkout/',
            'http_status' => 200,
            'cta_elements' => [['text' => 'Checkout']],
            'links' => [['href' => 'https://example.com/tickets/checkout/', 'text' => 'Checkout']],
            'structured_data' => [],
        ]);

        $result = $this->module()->run($audit->fresh());

        $this->assertFalse($result->isApplicable);
        $this->assertNull($result->score);
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

        $result = $this->module()->run($audit->fresh());

        $this->assertTrue($result->isApplicable);
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

        $result = $this->module()->run($audit->fresh());

        $this->assertTrue($result->isApplicable);
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

        $result = $this->module()->run($audit->fresh());

        $this->assertTrue($result->isApplicable);
        $codes = collect($result->issues)->pluck('code');
        $this->assertTrue($codes->contains('ecommerce.missing_product_schema'));
        $this->assertTrue($codes->contains('ecommerce.missing_purchase_cta'));
    }
}
