<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Crawl limits
    |--------------------------------------------------------------------------
    | Hard ceilings enforced regardless of plan. Per-user/plan limits (free vs
    | paid page counts, daily audit counts) live in the `plans` table, not
    | here — these are the crawler's own safety limits.
    */
    'max_pages' => (int) env('CRAWLER_MAX_PAGES', 25),
    'max_depth' => (int) env('CRAWLER_MAX_DEPTH', 5),
    'request_timeout' => (int) env('CRAWLER_REQUEST_TIMEOUT', 15),
    'page_timeout' => (int) env('CRAWLER_PAGE_TIMEOUT', 30),
    'crawl_timeout' => (int) env('CRAWLER_CRAWL_TIMEOUT', 600),
    'max_redirects' => (int) env('CRAWLER_MAX_REDIRECTS', 5),
    'max_file_size_mb' => (int) env('CRAWLER_MAX_FILE_SIZE_MB', 10),
    'rate_limit_per_second' => (float) env('CRAWLER_RATE_LIMIT_PER_SECOND', 2),

    /*
    |--------------------------------------------------------------------------
    | SSRF protection
    |--------------------------------------------------------------------------
    */
    'allowed_schemes' => ['http', 'https'],
    'allowed_ports' => [80, 443],

    // Must match the UA used by crawler/crawl.mjs's Playwright context so
    // robots.txt / sitemap.xml fetches and page navigation identify the
    // same bot consistently.
    'user_agent' => env('CRAWLER_USER_AGENT', 'AudivioBot/1.0 (+https://audivio.example/bot)'),

    /*
    |--------------------------------------------------------------------------
    | Node/Playwright bridge
    |--------------------------------------------------------------------------
    */
    'node_binary' => env('CRAWLER_NODE_BINARY', 'node'),
    'script_path' => base_path('../crawler/crawl.mjs'),
    'screenshot_disk' => 'public',
];
