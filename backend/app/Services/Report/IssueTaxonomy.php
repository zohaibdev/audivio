<?php

namespace App\Services\Report;

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
        'performance.excessive_dom_size' => 'Render Blocking',

        // SEO
        'seo.missing_title' => 'Metadata',
        'seo.title_length' => 'Metadata',
        'seo.missing_meta_description' => 'Metadata',
        'seo.meta_description_length' => 'Metadata',
        'seo.duplicate_title' => 'Metadata',
        'seo.missing_canonical' => 'Canonical',
        'seo.missing_h1' => 'Headings',
        'seo.multiple_h1' => 'Headings',
        'seo.missing_image_alt' => 'Images',
        'seo.broken_internal_links' => 'Links',
        'seo.redirect_chain' => 'Links',

        // CRO
        'conversion.form_friction' => 'Forms',
        'landing_page.no_cta' => 'CTA',
        'landing_page.no_headline' => 'Value Proposition',

        // AIO
        'aio.no_structured_data' => 'Structured Data',
        'aio.no_heading_structure' => 'Content Structure',

        // Ecommerce
        'ecommerce.missing_product_schema' => 'Structured Data',
        'ecommerce.missing_purchase_cta' => 'Add to Cart',

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
     * axe-core rule id => accessibility category. Only rules actually
     * observed from real audits are listed; anything else falls back to
     * the service default, so a new axe rule still appears in the report.
     */
    private const AXE_RULE_CATEGORY_MAP = [
        'image-alt' => 'Images',
        'input-image-alt' => 'Images',
        'color-contrast' => 'Contrast',
        'link-name' => 'Links',
        'link-in-text-block' => 'Links',
        'heading-order' => 'Headings',
        'empty-heading' => 'Headings',
        'label' => 'Labels',
        'form-field-multiple-labels' => 'Labels',
        'select-name' => 'Forms',
        'aria-allowed-role' => 'ARIA',
        'aria-allowed-attr' => 'ARIA',
        'aria-required-attr' => 'ARIA',
        'aria-valid-attr-value' => 'ARIA',
        'presentation-role-conflict' => 'ARIA',
        'region' => 'Semantic HTML',
        'landmark-one-main' => 'Semantic HTML',
        'html-has-lang' => 'Semantic HTML',
        'frame-title' => 'Semantic HTML',
        'skip-link' => 'Keyboard Navigation',
        'tabindex' => 'Keyboard Navigation',
        'focus-order-semantics' => 'Keyboard Navigation',
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

        if (str_starts_with($code, 'axe.')) {
            $rule = substr($code, 4);

            return self::AXE_RULE_CATEGORY_MAP[$rule] ?? self::SERVICE_FALLBACK_CATEGORY['accessibility'];
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
