<?php

namespace Tests\Feature\Audit;

use App\Enums\PlatformName;
use App\Enums\SiteType;
use App\Models\Audit;
use App\Models\AuditPage;
use App\Models\Website;
use App\Services\Audit\EcommerceAuditModule;
use App\Services\Audit\ProductAuditModule;
use App\Services\Audit\SiteClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Website classification, and the rules it exists to enforce (§46):
 * never show an ecommerce score on a non-ecommerce site, show N/A when a
 * service does not apply, and explain why it was skipped.
 */
class SiteClassificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeAudit(?PlatformName $platform = null): Audit
    {
        $website = Website::create([
            'name' => 'example.com', 'primary_url' => 'https://example.com/',
            'domain' => 'example.com', 'normalized_domain' => 'example.com',
            // `platform` is NOT NULL with an "unknown" member for exactly
            // this case — a site whose platform was not detected.
            'platform' => ($platform ?? PlatformName::Unknown)->value,
        ]);

        return Audit::create(['website_id' => $website->id, 'status' => 'analyzing', 'max_pages' => 10]);
    }

    private function addPage(Audit $audit, array $attributes = []): AuditPage
    {
        return AuditPage::create(array_merge([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'title' => 'Example',
        ], $attributes));
    }

    private function classify(Audit $audit)
    {
        return app(SiteClassifier::class)->classify($audit->fresh());
    }

    // --- The eight types ---

    public function test_a_shopify_store_with_product_data_is_ecommerce(): void
    {
        $audit = $this->makeAudit(PlatformName::Shopify);
        $this->addPage($audit, [
            'url' => 'https://example.com/products/thing',
            'structured_data' => [['@type' => 'Product', 'name' => 'Thing']],
            'cta_elements' => [['text' => 'Add to cart']],
        ]);

        $result = $this->classify($audit);

        $this->assertSame(SiteType::Ecommerce, $result->type);
        $this->assertTrue($result->isConfident());
    }

    public function test_a_subscription_product_is_saas_not_ecommerce(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, [
            'url' => 'https://example.com/pricing',
            'title' => 'Pricing',
            'structured_data' => [['@type' => 'SoftwareApplication', 'name' => 'App']],
            'cta_elements' => [['text' => 'Start free trial']],
            'headings' => [['level' => 1, 'text' => 'Simple pricing, per month']],
            'links' => [['href' => 'https://example.com/login', 'text' => 'Log in']],
        ]);

        // A pricing page and a checkout-ish flow are not a shop. Getting
        // this wrong means auditing shipping and returns on software.
        $this->assertSame(SiteType::Saas, $this->classify($audit)->type);
    }

    public function test_an_address_and_opening_hours_make_a_local_business(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, [
            'structured_data' => [[
                '@type' => 'LocalBusiness', 'name' => 'Cafe',
                'address' => ['@type' => 'PostalAddress', 'streetAddress' => '1 High St'],
            ]],
            'headings' => [['level' => 1, 'text' => 'Opening hours']],
            'links' => [['href' => 'tel:+441234567890', 'text' => 'Call us']],
        ]);

        $this->assertSame(SiteType::LocalBusiness, $this->classify($audit)->type);
    }

    public function test_dated_articles_make_a_blog(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, [
            'url' => 'https://example.com/blog/2026/01/a-post',
            'structured_data' => [['@type' => 'BlogPosting', 'headline' => 'A post']],
        ]);
        $this->addPage($audit, ['url' => 'https://example.com/blog/another']);

        $this->assertSame(SiteType::Blog, $this->classify($audit)->type);
    }

    public function test_project_pages_make_a_portfolio(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, ['url' => 'https://example.com/work/one', 'title' => 'Selected work']);
        $this->addPage($audit, ['url' => 'https://example.com/work/two']);

        $this->assertSame(SiteType::Portfolio, $this->classify($audit)->type);
    }

    public function test_a_quote_cta_and_services_section_make_a_service_business(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, [
            'url' => 'https://example.com/services',
            'cta_elements' => [['text' => 'Get a quote']],
            'headings' => [['level' => 1, 'text' => 'Our services']],
        ]);

        $this->assertSame(SiteType::ServiceBusiness, $this->classify($audit)->type);
    }

    public function test_a_single_page_with_one_action_is_a_landing_page(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, [
            'cta_elements' => [['text' => 'Join the waitlist']],
            'links' => [['href' => 'https://example.com/#features', 'text' => 'Features']],
        ]);

        $this->assertSame(SiteType::LandingPage, $this->classify($audit)->type);
    }

    public function test_a_multi_page_site_is_never_called_a_landing_page(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, ['cta_elements' => [['text' => 'Join the waitlist']]]);
        $this->addPage($audit, ['url' => 'https://example.com/second']);

        // A one-page *crawl* of a larger site is a crawl limitation, not a
        // single-page site, and the two must not be confused.
        $this->assertNotSame(SiteType::LandingPage, $this->classify($audit)->type);
    }

    // --- Honest uncertainty ---

    public function test_conflicting_signals_report_other_rather_than_guessing(): void
    {
        $audit = $this->makeAudit();
        // Equal, opposing evidence: a shop and a blog at the same strength.
        $this->addPage($audit, [
            'url' => 'https://example.com/products/thing',
            'structured_data' => [['@type' => 'Product', 'name' => 'Thing']],
        ]);
        $this->addPage($audit, [
            'url' => 'https://example.com/blog/post',
            'structured_data' => [['@type' => 'Article', 'headline' => 'Post']],
        ]);

        $result = $this->classify($audit);

        $this->assertSame(SiteType::Other, $result->type);
        $this->assertFalse($result->isConfident());
        $this->assertStringContainsString('more than one kind of site', $result->signals[0]);
    }

    public function test_a_crawl_with_no_pages_classifies_as_other_with_a_reason(): void
    {
        $result = $this->classify($this->makeAudit());

        $this->assertSame(SiteType::Other, $result->type);
        $this->assertSame(0, $result->confidence);
        $this->assertStringContainsString('nothing to classify', $result->signals[0]);
    }

    public function test_the_classification_always_carries_its_evidence(): void
    {
        $audit = $this->makeAudit(PlatformName::Shopify);
        $this->addPage($audit, [
            'structured_data' => [['@type' => 'Product', 'name' => 'Thing']],
            'cta_elements' => [['text' => 'Add to cart']],
        ]);

        $result = $this->classify($audit);

        // A decision that silences a whole service has to show its
        // working, strongest signal first.
        $this->assertNotEmpty($result->signals);
        $this->assertStringContainsString('Product structured data', implode(' ', $result->signals));
    }

    // --- Rule: never show an ecommerce score on a non-ecommerce site ---

    public function test_a_blog_gets_no_ecommerce_score_even_with_a_checkout_link(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, [
            'url' => 'https://example.com/blog/post',
            'structured_data' => [['@type' => 'BlogPosting', 'headline' => 'Post']],
            // The kind of stray signal that used to clear the detector's
            // threshold on a site with nothing to sell.
            'links' => [['href' => 'https://example.com/checkout', 'text' => 'Checkout']],
            'cta_elements' => [['text' => 'Buy the book']],
        ]);
        $this->addPage($audit, ['url' => 'https://example.com/blog/second']);

        $audit->update(['site_type' => $this->classify($audit)->type->value]);

        $result = app(EcommerceAuditModule::class)->run($audit->fresh());

        $this->assertFalse($result->isApplicable);
        $this->assertNull($result->score, 'a non-applicable service must have no score at all');
        $this->assertCount(0, $result->issues);
    }

    public function test_the_skip_reason_explains_the_decision_in_the_owners_terms(): void
    {
        $audit = $this->makeAudit();
        $audit->update(['site_type' => SiteType::Saas->value]);
        $this->addPage($audit);

        $result = app(EcommerceAuditModule::class)->run($audit->fresh());

        $this->assertFalse($result->isApplicable);
        $this->assertStringContainsString('SaaS product rather than a shop', $result->notApplicableReason);
        // Says what the checks cover, so the reader can tell whether they
        // agree with the decision.
        $this->assertStringContainsString('carts, shipping and returns', $result->notApplicableReason);
    }

    public function test_the_product_audit_is_skipped_for_the_same_reason(): void
    {
        $audit = $this->makeAudit();
        $audit->update(['site_type' => SiteType::Blog->value]);
        $this->addPage($audit);

        $result = app(ProductAuditModule::class)->run($audit->fresh());

        $this->assertFalse($result->isApplicable);
        // Not the generic "no product pages found" — that would suggest
        // the site is a shop whose products we failed to locate.
        $this->assertStringContainsString('blog or publication', $result->notApplicableReason);
    }

    public function test_a_local_business_can_still_be_audited_as_a_shop(): void
    {
        // Plenty of local businesses sell online too, so the type alone
        // must not veto the ecommerce assessment — the detector's own
        // evidence decides.
        $this->assertTrue(SiteType::LocalBusiness->canSellOnline());
        $this->assertTrue(SiteType::Other->canSellOnline());
        $this->assertFalse(SiteType::Saas->canSellOnline());
        $this->assertFalse(SiteType::Portfolio->canSellOnline());
    }

    public function test_every_type_has_a_label_and_a_description(): void
    {
        foreach (SiteType::cases() as $type) {
            $this->assertNotSame('', $type->label(), $type->value);
            $this->assertGreaterThan(30, strlen($type->description()), "{$type->value} description is too thin");
            $this->assertGreaterThan(30, strlen($type->ecommerceSkipReason()), "{$type->value} skip reason is too thin");
        }
    }
}
