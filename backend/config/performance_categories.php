<?php

/*
|--------------------------------------------------------------------------
| Performance categories (§45)
|--------------------------------------------------------------------------
| The twelve areas the performance report groups every finding under —
| whether the finding came from Lighthouse or from the crawler's own
| measurements — so a reader sees one set of categories rather than two
| overlapping vocabularies.
|
| `audits` maps Lighthouse audit ids onto a category. The list covers the
| audits Lighthouse actually emits for the performance category; anything
| unrecognised falls to `other`, which is reported as its own group rather
| than dropped. A Lighthouse release adding an audit therefore still
| surfaces it, just ungrouped, and that shows up as a category to extend.
*/

return [
    'labels' => [
        'core_web_vitals' => 'Core Web Vitals',
        'server' => 'Server',
        'images' => 'Images',
        'javascript' => 'JavaScript',
        'css' => 'CSS',
        'fonts' => 'Fonts',
        'caching' => 'Caching',
        'compression' => 'Compression',
        'render_blocking' => 'Render blocking',
        'third_party' => 'Third party',
        'network' => 'Network',
        'dom' => 'DOM',
        'other' => 'Other',
    ],

    'audits' => [
        // Core Web Vitals and the metrics that make them up
        'largest-contentful-paint' => 'core_web_vitals',
        'largest-contentful-paint-element' => 'core_web_vitals',
        'cumulative-layout-shift' => 'core_web_vitals',
        'layout-shifts' => 'core_web_vitals',
        'non-composited-animations' => 'core_web_vitals',
        'total-blocking-time' => 'core_web_vitals',
        'first-contentful-paint' => 'core_web_vitals',
        'speed-index' => 'core_web_vitals',
        'interactive' => 'core_web_vitals',
        'max-potential-fid' => 'core_web_vitals',
        'prioritize-lcp-image' => 'core_web_vitals',

        // Server
        'server-response-time' => 'server',
        'redirects' => 'server',
        'uses-http2' => 'server',

        // Images
        'uses-optimized-images' => 'images',
        'uses-webp-images' => 'images',
        'modern-image-formats' => 'images',
        'uses-responsive-images' => 'images',
        'efficient-animated-content' => 'images',
        'offscreen-images' => 'images',
        'unsized-images' => 'images',
        'image-size-responsive' => 'images',
        'image-delivery' => 'images',

        // JavaScript
        'unused-javascript' => 'javascript',
        'unminified-javascript' => 'javascript',
        'legacy-javascript' => 'javascript',
        'duplicated-javascript' => 'javascript',
        'bootup-time' => 'javascript',
        'mainthread-work-breakdown' => 'javascript',
        'no-document-write' => 'javascript',
        'long-tasks' => 'javascript',

        // CSS
        'unused-css-rules' => 'css',
        'unminified-css' => 'css',

        // Fonts
        'font-display' => 'fonts',
        'preload-fonts' => 'fonts',

        // Caching
        'uses-long-cache-ttl' => 'caching',
        'cache-insight' => 'caching',

        // Compression
        'uses-text-compression' => 'compression',

        // Render blocking
        'render-blocking-resources' => 'render_blocking',
        'render-blocking-insight' => 'render_blocking',
        'critical-request-chains' => 'render_blocking',
        'preload-lcp-image' => 'render_blocking',
        'uses-rel-preconnect' => 'render_blocking',
        'uses-rel-preload' => 'render_blocking',

        // Third party
        'third-party-summary' => 'third_party',
        'third-party-facades' => 'third_party',

        // Network
        'total-byte-weight' => 'network',
        'network-requests' => 'network',
        'network-rtt' => 'network',
        'network-server-latency' => 'network',
        'resource-summary' => 'network',
        'largest-contentful-paint-lazy-loaded' => 'network',

        // DOM
        'dom-size' => 'dom',
    ],

    /*
    | How many opportunities/diagnostics to keep per page per strategy,
    | and how many affected resources to keep per finding. Both are stored
    | as JSON on page_performance_metrics, and a Lighthouse run can name
    | hundreds of resources on a single audit.
    */
    'max_items' => 20,
    'max_resources_per_item' => 8,
];
