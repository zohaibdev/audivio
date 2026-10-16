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

    // Ceiling on concurrently-running crawl jobs across the whole system.
    // The `crawler` rate limiter throttles how *fast* pages are fetched;
    // this caps how *many* audits can be crawling at once, so a burst of
    // submissions can't spawn an unbounded number of Chromium subprocesses.
    'max_concurrent_crawls' => (int) env('CRAWLER_MAX_CONCURRENT_CRAWLS', 5),

    /*
    |--------------------------------------------------------------------------
    | SSRF protection
    |--------------------------------------------------------------------------
    */
    'allowed_schemes' => ['http', 'https'],
    'allowed_ports' => [80, 443],

    'max_url_length' => (int) env('CRAWLER_MAX_URL_LENGTH', 2048),
    'max_query_length' => (int) env('CRAWLER_MAX_QUERY_LENGTH', 2048),
    'max_host_length' => 253,

    // Cap on the body size of the plain-HTTP fetches (robots.txt,
    // sitemap.xml) made from PHP. Page bodies fetched by the Node/
    // Playwright side are bounded separately by `max_file_size_mb`.
    'max_response_bytes' => (int) env('CRAWLER_MAX_RESPONSE_BYTES', 5 * 1024 * 1024),

    // Address ranges that must never be reached, on top of what PHP's
    // FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE already rejects.
    // Those flags miss several ranges that are still routable-to-internal
    // in practice — notably carrier-grade NAT (100.64/10), the IETF
    // protocol assignments block (192.0.0.0/24), and the benchmark range
    // (198.18/15) — and miss NAT64 (64:ff9b::/96) on the IPv6 side, which
    // embeds an arbitrary IPv4 destination. Mirrors the list in
    // crawler/lib/ssrf.mjs; the two can't share code across the language
    // boundary, so they are kept deliberately in sync.
    'blocked_cidrs' => [
        // IPv4
        '0.0.0.0/8',          // "this network"
        '10.0.0.0/8',         // private
        '100.64.0.0/10',      // carrier-grade NAT
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local, incl. cloud metadata 169.254.169.254
        '172.16.0.0/12',      // private
        '192.0.0.0/24',       // IETF protocol assignments
        '192.0.2.0/24',       // TEST-NET-1
        '192.88.99.0/24',     // deprecated 6to4 relay anycast
        '192.168.0.0/16',     // private
        '198.18.0.0/15',      // benchmarking
        '198.51.100.0/24',    // TEST-NET-2
        '203.0.113.0/24',     // TEST-NET-3
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved, incl. 255.255.255.255 broadcast

        // IPv6
        '::/128',             // unspecified
        '::1/128',            // loopback
        '::ffff:0:0/96',      // IPv4-mapped (checked against the IPv4 list too)
        '64:ff9b::/96',       // NAT64 — embeds an arbitrary IPv4 destination
        '100::/64',           // discard-only
        '2001:db8::/32',      // documentation
        'fc00::/7',           // unique local
        'fe80::/10',          // link-local
        'ff00::/8',           // multicast
    ],

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
    /*
    | Screenshots are private (§48). They are captures of someone else's
    | site, on a path keyed by a sequential audit id, so serving them from
    | the public disk made them walkable by anyone. They are now streamed
    | through a signed, authorized route instead.
    |
    | Captures taken before this change are still on the `public` disk and
    | are still served — ScreenshotController reads from either.
    */
    'screenshot_disk' => env('SCREENSHOT_DISK', 'local'),

    /*
    | How long a signed screenshot URL stays valid. Long enough to read a
    | report without links dying mid-session; short enough that a
    | forwarded URL stops working before it travels far. The authorization
    | check on the route is what actually protects the file — this only
    | bounds how long a *valid* link is worth forwarding.
    */
    'screenshot_url_ttl_minutes' => (int) env('SCREENSHOT_URL_TTL_MINUTES', 60),
];
