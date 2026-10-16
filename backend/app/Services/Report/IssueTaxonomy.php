<?php

namespace App\Services\Report;

use App\Enums\AccessibilityCheck;
use App\Enums\AuditCategory;

/**
 * Maps audit findings onto the user-facing report taxonomy: which
 * *service report* an issue belongs in, and which *category* within that
 * report groups it.
 *
 * Two distinct levels, resolved from two different sources on purpose:
 *
 * - **Service** comes from the issue's stored `AuditCategory` column, not
 *   from its code string. The column is written by the module that
 *   produced the issue, so it is always present and always correct;
 *   parsing a code prefix would break for AI-generated codes (whose
 *   suffix the model chooses) and axe rule ids.
 * - **Category** comes from the code, since that is the only thing that
 *   distinguishes "missing title" (Metadata) from "missing H1"
 *   (Headings) inside one service.
 *
 * Unknown codes resolve to the service's own fallback category rather
 * than being dropped — an AI-invented code or a newly added axe rule must
 * still appear in the report, just in a general bucket.
 */
class IssueTaxonomy
{
    /**
     * Service key => display name. Keys match the product spec's service
     * list and the `features` registry keys from CONTEXT.md §33.
     */
    public const SERVICES = [
        'performance' => 'Page Speed & Performance',
        'accessibility' => 'Accessibility / ADA',
        'seo' => 'SEO',
        'cro' => 'Conversion Rate Optimization',
        'aio' => 'AI Search Readiness',
        'ecommerce' => 'Ecommerce',
        'product_auditor' => 'AI Product Audit',
    ];

    /**
     * Internal AuditCategory value => report service key.
     *
     * `landing_page` and `conversion` both surface under CRO: their real
     * issue codes are value-proposition and CTA findings, which the
     * product spec lists as CRO categories. Note this deliberately
     * differs from FeatureAccessResolver's *plan-gating* map (§33), where
     * `landing_page` is gated by the `ecommerce` feature key — see the
     * known-limitation note in CONTEXT.md §35, since that means disabling
     * a plan's ecommerce feature also silently removes CRO's
     * value-proposition findings.
     */
    private const CATEGORY_SERVICE_MAP = [
        'performance' => 'performance',
        'accessibility' => 'accessibility',
        'seo' => 'seo',
        'conversion' => 'cro',
        'landing_page' => 'cro',
        'aio' => 'aio',
        'ecommerce' => 'ecommerce',
        'product_auditor' => 'product_auditor',
    ];

    /** Exact issue code => category within its service. */
    private const CODE_CATEGORY_MAP = [
        // Performance
        'performance.lcp' => 'Core Web Vitals',
        'performance.cls' => 'Core Web Vitals',
        'performance.pagespeed_score' => 'Loading Performance',
        'performance.slow_load_time' => 'Loading Performance',
        'performance.large_html' => 'Page Weight',
        'performance.heavy_page_weight' => 'Page Weight',
        'performance.missing_image_dimensions' => 'Images',
        'performance.heavy_images' => 'Images',
        'performance.large_javascript' => 'JavaScript',
        'performance.large_css' => 'CSS',
        'performance.too_many_fonts' => 'Fonts',
        'performance.too_many_requests' => 'Network',
        'performance.slow_server_response' => 'Server Response',
        'performance.third_party_weight' => 'Third-Party Resources',
        'performance.excessive_dom_size' => 'DOM',
        'performance.no_cache_headers' => 'Caching',
        'performance.no_compression' => 'Compression',

        // SEO (§44)
        'seo.missing_title' => 'Metadata',
        'seo.title_length' => 'Metadata',
        'seo.missing_meta_description' => 'Metadata',
        'seo.meta_description_length' => 'Metadata',
        'seo.duplicate_title' => 'Metadata',
        'seo.duplicate_meta_description' => 'Metadata',
        'seo.missing_canonical' => 'Canonical',
        'seo.not_indexable' => 'Indexability',
        'seo.client_error_page' => 'Indexability',
        'seo.server_error_page' => 'Indexability',
        'seo.missing_robots_txt' => 'Crawlability',
        'seo.missing_sitemap' => 'Crawlability',
        'seo.sitemap_not_declared' => 'Crawlability',
        'seo.missing_h1' => 'Headings',
        'seo.multiple_h1' => 'Headings',
        'seo.heading_hierarchy_skip' => 'Headings',
        'seo.missing_image_alt' => 'Images',
        'seo.image_missing_dimensions' => 'Images',
        'seo.broken_internal_links' => 'Links',
        'seo.internal_links_to_redirects' => 'Links',
        'seo.nofollow_internal_links' => 'Links',
        'seo.few_internal_links' => 'Links',
        'seo.orphan_pages' => 'Links',
        'seo.redirect_chain' => 'Redirects',
        'seo.redirect_loop' => 'Redirects',
        'seo.temporary_redirect' => 'Redirects',
        'seo.missing_open_graph' => 'Social Sharing',
        'seo.incomplete_open_graph' => 'Social Sharing',
        'seo.missing_twitter_card' => 'Social Sharing',

        // CRO (§46)
        'conversion.form_friction' => 'Forms',
        'conversion.long_forms' => 'Forms',
        'conversion.pages_without_cta' => 'CTA',
        'conversion.vague_cta_text' => 'CTA',
        'conversion.wide_navigation' => 'Navigation',
        'conversion.thin_navigation' => 'Navigation',
        'conversion.no_contact_route' => 'Trust Signals',
        'conversion.no_privacy_policy' => 'Trust Signals',
        'conversion.no_social_proof' => 'Social Proof',
        'conversion.no_tap_to_call' => 'Mobile Conversion',
        'conversion.small_tap_targets' => 'Mobile Conversion',
        'landing_page.no_cta' => 'CTA',
        'landing_page.no_headline' => 'Value Proposition',

        // AIO
        'aio.no_structured_data' => 'Structured Data',
        'aio.no_heading_structure' => 'Content Structure',

        // Ecommerce
        'ecommerce.missing_product_schema' => 'Structured Data',
        'ecommerce.missing_purchase_cta' => 'Add to Cart',
        'ecommerce.no_cart_route_found' => 'Checkout Signals',
        'ecommerce.missing_shipping_information' => 'Shipping',
        'ecommerce.missing_returns_information' => 'Returns',
        'ecommerce.no_review_data' => 'Trust',

        // Product audit
        'product.missing_product_schema' => 'Structured Data',
        'product.missing_price' => 'Pricing',
        'product.missing_availability' => 'Pricing',
        'product.no_images' => 'Images',
        'product.too_few_images' => 'Images',
        'product.thin_description' => 'Product Information',
        'product.no_review_data' => 'Trust',
        'product.no_add_to_cart' => 'CTA',
    ];

    /**
     * Code *prefix* => category, for codes whose tail is variable.
     *
     * Checked after the exact map, so an exact entry always wins.
     */
    private const CODE_PREFIX_CATEGORY_MAP = [
        'seo.schema_incomplete.' => 'Structured Data',
        'seo.schema_recommended.' => 'Structured Data',
        'seo.missing_schema.' => 'Structured Data',
    ];

    /** Fallback category per service, for codes not in the maps above. */
    private const SERVICE_FALLBACK_CATEGORY = [
        'performance' => 'Other Performance',
        'accessibility' => 'Other Accessibility',
        'seo' => 'Other SEO',
        'cro' => 'Conversion Friction',
        'aio' => 'Semantic Content',
        'ecommerce' => 'Conversion',
        'product_auditor' => 'Product Information',
    ];

    public function serviceFor(AuditCategory $category): ?string
    {
        return self::CATEGORY_SERVICE_MAP[$category->value] ?? null;
    }

    public function categoryFor(AuditCategory $category, string $code): string
    {
        $service = $this->serviceFor($category);

        if (isset(self::CODE_CATEGORY_MAP[$code])) {
            return self::CODE_CATEGORY_MAP[$code];
        }

        // Schema codes carry the schema.org type as a suffix
        // (`seo.schema_incomplete.Product`), so they cannot be listed
        // exhaustively — adding a type to config/seo_schema.php would
        // otherwise silently drop its findings into the catch-all.
        foreach (self::CODE_PREFIX_CATEGORY_MAP as $prefix => $mapped) {
            if (str_starts_with($code, $prefix)) {
                return $mapped;
            }
        }

        if (str_starts_with($code, 'axe.')) {
            // Delegated to the one place axe rules are grouped (§43).
            // This used to be a second, thinner copy of that map, and the
            // two drifted: a rule grouped as "Contrast" in the
            // accessibility rollup could appear under "Other
            // Accessibility" in the issue list of the same report.
            $check = AccessibilityCheck::forAxeRule(substr($code, 4));

            return $check === AccessibilityCheck::Other
                ? self::SERVICE_FALLBACK_CATEGORY['accessibility']
                : $check->label();
        }

        return self::SERVICE_FALLBACK_CATEGORY[$service] ?? 'Other';
    }

    /**
     * Whether an issue came from an AI model rather than a deterministic
     * rule. The report must label these differently — AI findings are
     * interpretation, deterministic findings are measurement (§35).
     */
    public function isAiGenerated(string $code): bool
    {
        return str_starts_with($code, 'ai.');
    }

    public function serviceName(string $serviceKey): string
    {
        return self::SERVICES[$serviceKey] ?? $serviceKey;
    }
}
