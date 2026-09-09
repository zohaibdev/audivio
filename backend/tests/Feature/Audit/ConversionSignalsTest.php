<?php

namespace Tests\Feature\Audit;

use App\Enums\PlatformName;
use App\Enums\SiteType;
use App\Models\Audit;
use App\Models\AuditPage;
use App\Models\Website;
use App\Services\Audit\ConversionSignalChecks;
use App\Services\Audit\ConversionSignalDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The countable half of the CRO and ecommerce audit (§46).
 *
 * These run without an AI provider, so they are what a report still says
 * when the AI is unavailable — which is exactly when it is otherwise at
 * its thinnest.
 */
class ConversionSignalsTest extends TestCase
{
    use RefreshDatabase;

    private function makeAudit(?SiteType $type = null): Audit
    {
        $website = Website::create([
            'name' => 'example.com', 'primary_url' => 'https://example.com/',
            'domain' => 'example.com', 'normalized_domain' => 'example.com',
            'platform' => PlatformName::Unknown->value,
        ]);

        return Audit::create([
            'website_id' => $website->id, 'status' => 'analyzing', 'max_pages' => 10,
            'site_type' => $type?->value,
        ]);
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

    /**
     * @return string[]
     */
    private function croCodes(Audit $audit): array
    {
        $audit = $audit->fresh();
        $signals = app(ConversionSignalDetector::class)->detect($audit);

        return collect(app(ConversionSignalChecks::class)->croIssues($audit, $signals))
            ->pluck('code')
            ->all();
    }

    /**
     * @return string[]
     */
    private function ecommerceCodes(Audit $audit): array
    {
        $audit = $audit->fresh();
        $signals = app(ConversionSignalDetector::class)->detect($audit);

        return collect(app(ConversionSignalChecks::class)->ecommerceIssues($audit, $signals))
            ->pluck('code')
            ->all();
    }

    // --- CTA visibility and clarity ---

    public function test_a_page_with_no_call_to_action_is_reported(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, ['cta_elements' => []]);

        $this->assertContains('conversion.pages_without_cta', $this->croCodes($audit));
    }

    public function test_generic_button_wording_is_reported_with_examples(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, ['cta_elements' => [['text' => 'Submit'], ['text' => 'Get your free quote']]]);

        $signals = app(ConversionSignalDetector::class)->detect($audit->fresh());
        $issues = app(ConversionSignalChecks::class)->croIssues($audit->fresh(), $signals);
        $issue = collect($issues)->firstWhere('code', 'conversion.vague_cta_text');

        $this->assertNotNull($issue);
        $this->assertSame(1, count($issue->meta['examples']), 'only the vague one counts');
        $this->assertSame('Submit', $issue->meta['examples'][0]['text']);
    }

    public function test_specific_wording_containing_a_vague_phrase_is_not_flagged(): void
    {
        $audit = $this->makeAudit();
        // "Learn more" is vague; "Learn more about pricing" is not. A
        // substring match would flag both and train readers to ignore this.
        $this->addPage($audit, ['cta_elements' => [['text' => 'Learn more about pricing']]]);

        $this->assertNotContains('conversion.vague_cta_text', $this->croCodes($audit));
    }

    // --- Forms ---

    public function test_a_long_form_is_reported_and_hidden_fields_do_not_count(): void
    {
        $audit = $this->makeAudit();

        $fields = array_fill(0, 8, ['type' => 'text', 'required' => true]);
        $fields[] = ['type' => 'hidden', 'name' => '_token'];

        $this->addPage($audit, [
            'cta_elements' => [['text' => 'Send enquiry']],
            'forms' => [['fields' => $fields]],
        ]);

        $signals = app(ConversionSignalDetector::class)->detect($audit->fresh());

        // Hidden inputs are plumbing and cost a visitor nothing.
        $this->assertSame(8, $signals['forms']['long_forms'][0]['field_count']);
    }

    public function test_a_short_form_is_not_reported(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, [
            'cta_elements' => [['text' => 'Send enquiry']],
            'forms' => [['fields' => [['type' => 'email'], ['type' => 'text']]]],
        ]);

        $this->assertNotContains('conversion.long_forms', $this->croCodes($audit));
    }

    // --- Navigation ---

    public function test_repeated_links_to_one_destination_count_once(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, [
            'cta_elements' => [['text' => 'Get a quote']],
            'links' => [
                ['href' => 'https://example.com/about', 'text' => 'About'],
                // The same destination in a header and a footer is one
                // route; counting it twice would report tidy navigation
                // as sprawl.
                ['href' => 'https://example.com/about/', 'text' => 'About us'],
                ['href' => 'https://example.com/contact', 'text' => 'Contact'],
            ],
        ]);

        $signals = app(ConversionSignalDetector::class)->detect($audit->fresh());

        $this->assertSame(2, $signals['navigation']['primary_links']);
    }

    public function test_a_page_that_links_nowhere_is_reported(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, ['cta_elements' => [['text' => 'Get a quote']], 'links' => []]);

        $this->assertContains('conversion.thin_navigation', $this->croCodes($audit));
    }

    // --- Trust and social proof ---

    public function test_a_site_with_no_contact_route_is_reported(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, ['cta_elements' => [['text' => 'Get a quote']], 'links' => []]);

        $this->assertContains('conversion.no_contact_route', $this->croCodes($audit));
    }

    public function test_a_mailto_link_counts_as_a_contact_route(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, [
            'cta_elements' => [['text' => 'Get a quote']],
            'links' => [
                ['href' => 'mailto:hi@example.com', 'text' => 'Email us'],
                ['href' => 'https://example.com/privacy', 'text' => 'Privacy'],
                ['href' => 'https://example.com/about', 'text' => 'About'],
            ],
        ]);

        $this->assertNotContains('conversion.no_contact_route', $this->croCodes($audit));
    }

    public function test_review_markup_counts_as_social_proof(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, [
            'cta_elements' => [['text' => 'Get a quote']],
            'links' => [
                ['href' => 'https://example.com/contact', 'text' => 'Contact'],
                ['href' => 'https://example.com/privacy', 'text' => 'Privacy'],
            ],
            'structured_data' => [['@type' => 'Product', 'aggregateRating' => ['@type' => 'AggregateRating', 'ratingValue' => 4.5]]],
        ]);

        $this->assertNotContains('conversion.no_social_proof', $this->croCodes($audit));
    }

    // --- Mobile signals are type-aware ---

    public function test_a_local_business_with_no_tap_to_call_is_reported(): void
    {
        $audit = $this->makeAudit(SiteType::LocalBusiness);
        $this->addPage($audit, [
            'cta_elements' => [['text' => 'Book a table']],
            'links' => [
                ['href' => 'https://example.com/contact', 'text' => 'Contact'],
                ['href' => 'https://example.com/privacy', 'text' => 'Privacy'],
            ],
        ]);

        $this->assertContains('conversion.no_tap_to_call', $this->croCodes($audit));
    }

    public function test_a_blog_is_not_asked_for_a_phone_number(): void
    {
        $audit = $this->makeAudit(SiteType::Blog);
        $this->addPage($audit, [
            'cta_elements' => [['text' => 'Subscribe']],
            'links' => [
                ['href' => 'https://example.com/contact', 'text' => 'Contact'],
                ['href' => 'https://example.com/privacy', 'text' => 'Privacy'],
            ],
        ]);

        // Demanding a tap-to-call link would be advice about a business
        // this site does not run.
        $this->assertNotContains('conversion.no_tap_to_call', $this->croCodes($audit));
    }

    // --- Ecommerce areas ---

    public function test_a_shop_with_no_shipping_or_returns_information_is_reported(): void
    {
        $audit = $this->makeAudit(SiteType::Ecommerce);
        $this->addPage($audit, [
            'url' => 'https://example.com/products/thing',
            'cta_elements' => [['text' => 'Add to cart']],
            'links' => [['href' => 'https://example.com/cart', 'text' => 'Cart']],
        ]);

        $codes = $this->ecommerceCodes($audit);

        $this->assertContains('ecommerce.missing_shipping_information', $codes);
        $this->assertContains('ecommerce.missing_returns_information', $codes);
    }

    public function test_a_returns_policy_linked_by_text_alone_is_found(): void
    {
        $audit = $this->makeAudit(SiteType::Ecommerce);
        $this->addPage($audit, [
            'cta_elements' => [['text' => 'Add to cart']],
            'links' => [
                // A policy linked from the footer with an opaque URL. A
                // URL-only check would report this shop as having none.
                ['href' => 'https://example.com/p/17', 'text' => 'Returns policy'],
                ['href' => 'https://example.com/cart', 'text' => 'Cart'],
            ],
        ]);

        $this->assertNotContains('ecommerce.missing_returns_information', $this->ecommerceCodes($audit));
    }

    public function test_a_shop_with_no_reachable_cart_is_reported(): void
    {
        $audit = $this->makeAudit(SiteType::Ecommerce);
        $this->addPage($audit, [
            'cta_elements' => [['text' => 'Add to cart']],
            'links' => [
                ['href' => 'https://example.com/shipping', 'text' => 'Shipping'],
                ['href' => 'https://example.com/returns', 'text' => 'Returns'],
            ],
        ]);

        $this->assertContains('ecommerce.no_cart_route_found', $this->ecommerceCodes($audit));
    }

    public function test_findings_state_that_they_cover_crawled_pages_only(): void
    {
        $audit = $this->makeAudit(SiteType::Ecommerce);
        $this->addPage($audit, ['cta_elements' => [['text' => 'Add to cart']], 'links' => []]);

        $signals = app(ConversionSignalDetector::class)->detect($audit->fresh());
        $issue = collect(app(ConversionSignalChecks::class)->ecommerceIssues($audit->fresh(), $signals))
            ->firstWhere('code', 'ecommerce.missing_returns_information');

        // A returns policy on page 40 of a 25-page crawl is not absent,
        // and the finding must not claim it is.
        $this->assertSame('crawled pages only', $issue->meta['scope']);
    }
}
