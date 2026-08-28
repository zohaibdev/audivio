<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Overall score weights
    |--------------------------------------------------------------------------
    | How much each category contributes to the overall 0-100 score. Only
    | categories that actually ran for a given audit are included, and
    | weights are renormalized over just those (see ScoreCalculator) so an
    | audit with fewer modules run still produces a fair overall score.
    */
    'category_weights' => [
        'performance' => 25,
        'accessibility' => 20,
        'seo' => 25,
        'ecommerce' => 10,
        'landing_page' => 10,
        'conversion' => 5,
        'aio' => 5,
        'product_auditor' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Points deducted per issue severity
    |--------------------------------------------------------------------------
    | Each module starts a category at 100 and deducts points per issue
    | found, floored at 0. Severities are per-issue, not per-page — a
    | site-wide issue (e.g. "no canonical tags anywhere") should be raised
    | once, not once per page, to avoid disproportionate score damage on
    | larger sites.
    */
    'severity_points' => [
        'critical' => 20,
        'high' => 10,
        'medium' => 5,
        'low' => 2,
        'passed' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Accessibility (axe-core impact -> our severity)
    |--------------------------------------------------------------------------
    */
    'accessibility' => [
        'impact_severity_map' => [
            'critical' => 'critical',
            'serious' => 'high',
            'moderate' => 'medium',
            'minor' => 'low',
        ],
        // Above this many total violations across the audit, cap further
        // point deductions — a handful of repeated violation types on
        // every page shouldn't zero out the score.
        'max_scored_violations' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance
    |--------------------------------------------------------------------------
    */
    'performance' => [
        // PageSpeed Insights is only run against this many pages per audit
        // (homepage by default) — it's a slow (10-30s), rate-limited
        // external call, not something to run per crawled page.
        'pagespeed_pages' => 1,
        'max_load_time_ms' => 3000,
        'max_page_weight_bytes' => 2 * 1024 * 1024,

        // Per-resource budgets, checked against the crawler's own measured
        // transfer sizes (see PerformanceAuditModule::checkResourceBreakdown).
        // Starting points based on commonly cited web-performance guidance,
        // deliberately configurable rather than hardcoded — tune per project
        // rather than editing the module.
        'max_js_bytes' => 500 * 1024,
        'max_css_bytes' => 150 * 1024,
        'max_image_bytes' => 1024 * 1024,
        'max_font_requests' => 4,
        'max_requests' => 75,
        'max_third_party_bytes' => 500 * 1024,
        'max_ttfb_ms' => 800,
        'max_dom_nodes' => 1500,

        // Core Web Vitals thresholds (good / needs-improvement boundary),
        // per web.dev's published guidance. Used to label a metric
        // Good / Needs Improvement / Poor in the performance report.
        'vitals' => [
            'lcp_ms' => ['good' => 2500, 'poor' => 4000],
            'cls' => ['good' => 0.1, 'poor' => 0.25],
            'tbt_ms' => ['good' => 200, 'poor' => 600],
            'fcp_ms' => ['good' => 1800, 'poor' => 3000],
            'ttfb_ms' => ['good' => 800, 'poor' => 1800],
            'speed_index_ms' => ['good' => 3400, 'poor' => 5800],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SEO
    |--------------------------------------------------------------------------
    */
    'seo' => [
        'title_min_length' => 10,
        'title_max_length' => 60,
        'meta_description_min_length' => 50,
        'meta_description_max_length' => 160,
    ],

    /*
    |--------------------------------------------------------------------------
    | AI-driven modules (ecommerce, landing_page, conversion, aio)
    |--------------------------------------------------------------------------
    | Each AI call sends a pre-extracted summary of at most this many pages
    | (never raw HTML) to control both token cost and prompt size.
    */
    'ai_context_pages' => (int) env('AUDIT_AI_CONTEXT_PAGES', 5),

    /*
    |--------------------------------------------------------------------------
    | Ecommerce detection
    |--------------------------------------------------------------------------
    | Minimum weighted signal score (see EcommerceDetector) before a site is
    | treated as ecommerce at all. Below this, the category is reported as
    | Not Applicable rather than running deterministic/AI ecommerce checks
    | against a site that isn't selling anything.
    */
    'ecommerce' => [
        'min_confidence_score' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Product page auditing
    |--------------------------------------------------------------------------
    | Thresholds for ProductAuditModule, which only runs against pages
    | ProductPageDetector identified as real product pages.
    */
    'product' => [
        'min_images' => 3,
        'min_description_length' => 200,
    ],
];
