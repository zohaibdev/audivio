<?php

namespace App\Services\Audit;

use App\Contracts\AuditModule;
use App\DTOs\AuditIssueData;
use App\DTOs\AuditModuleResult;
use App\Enums\AuditCategory;
use App\Enums\IssueSeverity;
use App\Enums\MetricSource;
use App\Models\Audit;
use App\Models\AuditPage;
use App\Models\PagePerformanceMetric;

class PerformanceAuditModule implements AuditModule
{
    public function __construct(
        private readonly PageSpeedService $pageSpeed,
    ) {}

    public function category(): AuditCategory
    {
        return AuditCategory::Performance;
    }

    public function run(Audit $audit): AuditModuleResult
    {
        $pages = $audit->pages;
        $perPageIssues = [];

        foreach ($pages as $page) {
            $perPageIssues = array_merge(
                $perPageIssues,
                $this->checkLoadTime($page),
                $this->checkPageWeight($page),
                $this->checkImageDimensions($page),
            );
        }

        foreach ($pages as $page) {
            $perPageIssues = array_merge(
                $perPageIssues,
                $this->checkResourceBreakdown($page),
                $this->checkCaching($page),
                $this->checkCompression($page),
            );
        }

        $pagespeedPageCount = config('audit_scoring.performance.pagespeed_pages');
        foreach ($pages->take($pagespeedPageCount) as $page) {
            $perPageIssues = array_merge($perPageIssues, $this->checkPageSpeedInsights($page));
        }

        // Collapses e.g. "slow load time" found on every crawled page into
        // one site-wide issue instead of N near-identical deductions — see
        // PerPageIssueAggregator's docblock for why this is necessary for
        // the score to mean anything on a multi-page site.
        $issues = PerPageIssueAggregator::aggregate($perPageIssues);

        [$score, $breakdown] = ScoreCalculator::deductPoints($issues);

        return new AuditModuleResult(category: $this->category(), score: $score, issues: $issues, breakdown: $breakdown);
    }

    /** @return AuditIssueData[] */
    private function checkLoadTime(AuditPage $page): array
    {
        $max = config('audit_scoring.performance.max_load_time_ms');

        if (($page->load_time_ms ?? 0) > $max) {
            return [new AuditIssueData(
                severity: IssueSeverity::Medium,
                code: 'performance.slow_load_time',
                message: "Page took {$page->load_time_ms}ms to load (budget: {$max}ms): {$page->url}",
                recommendation: 'Investigate render-blocking resources, unoptimized assets, or slow server response time.',
                auditPageId: $page->id,
                meta: ['url' => $page->url, 'load_time_ms' => $page->load_time_ms],
            )];
        }

        return [];
    }

    /** @return AuditIssueData[] */
    private function checkPageWeight(AuditPage $page): array
    {
        $max = config('audit_scoring.performance.max_page_weight_bytes');

        if (($page->html_size_bytes ?? 0) > $max) {
            $kb = round($page->html_size_bytes / 1024);
            $maxKb = round($max / 1024);

            return [new AuditIssueData(
                severity: IssueSeverity::Low,
                code: 'performance.large_html',
                message: "HTML size is {$kb}KB (budget: {$maxKb}KB): {$page->url}",
                auditPageId: $page->id,
                meta: ['url' => $page->url, 'bytes' => $page->html_size_bytes],
            )];
        }

        return [];
    }

    /** @return AuditIssueData[] */
    private function checkImageDimensions(AuditPage $page): array
    {
        $missing = collect($page->images ?? [])
            ->filter(fn ($img) => empty($img['width']) || empty($img['height']))
            ->count();

        if ($missing === 0) {
            return [];
        }

        return [new AuditIssueData(
            severity: IssueSeverity::Low,
            code: 'performance.missing_image_dimensions',
            message: "{$missing} image(s) missing explicit width/height (risk of layout shift): {$page->url}",
            recommendation: 'Set explicit width/height (or aspect-ratio) on images to prevent cumulative layout shift.',
            auditPageId: $page->id,
            meta: ['url' => $page->url, 'count' => $missing],
        )];
    }

    /**
     * Deterministic checks against the crawler's own measured resource
     * breakdown — real transferred bytes and request counts, not estimates.
     * Thresholds live in config so they're tunable without a code change.
     *
     * @return AuditIssueData[]
     */
    /**
     * Static assets served without a usable cache lifetime (§45).
     *
     * Measured from the `Cache-Control` header of every response the
     * crawler saw, so the finding names the exact files. Repeat visitors
     * re-download every one of these.
     *
     * Silent when the crawl recorded no caching summary: that is an audit
     * from before this was captured, not a site with no caching.
     *
     * @return AuditIssueData[]
     */
    private function checkCaching(AuditPage $page): array
    {
        $caching = $this->crawlerMetric($page)?->caching;

        if (! is_array($caching) || ($caching['consideredCount'] ?? 0) === 0) {
            return [];
        }

        $uncached = (int) ($caching['uncachedCount'] ?? 0);
        $budget = (int) config('audit_scoring.performance.max_uncached_assets');

        if ($uncached <= $budget) {
            return [];
        }

        $bytes = (int) ($caching['uncachedBytes'] ?? 0);

        return [new AuditIssueData(
            severity: IssueSeverity::Medium,
            code: 'performance.no_cache_headers',
            message: "{$uncached} static asset(s) totalling ".$this->kb($bytes).' are served without a cache lifetime: '.$page->url,
            recommendation: 'Set a long Cache-Control max-age on versioned static assets. Without one, every returning visitor downloads these files again even though they have not changed.',
            auditPageId: $page->id,
            meta: [
                'url' => $page->url,
                'uncached_count' => $uncached,
                'cached_count' => (int) ($caching['cachedCount'] ?? 0),
                'considered_count' => (int) ($caching['consideredCount'] ?? 0),
                'uncached_bytes' => $bytes,
                'budget_count' => $budget,
                // The actual files, with the header each one sent.
                'resources' => $caching['uncachedSamples'] ?? [],
            ],
        )];
    }

    /**
     * Text resources served uncompressed (§45).
     *
     * Images, fonts and media are excluded upstream — they are already
     * compressed formats, and gzipping a JPEG costs CPU to save nothing.
     *
     * @return AuditIssueData[]
     */
    private function checkCompression(AuditPage $page): array
    {
        $compression = $this->crawlerMetric($page)?->compression;

        if (! is_array($compression) || ($compression['consideredCount'] ?? 0) === 0) {
            return [];
        }

        $bytes = (int) ($compression['uncompressedBytes'] ?? 0);
        $budget = (int) config('audit_scoring.performance.max_uncompressed_bytes');

        if ($bytes <= $budget) {
            return [];
        }

        $count = (int) ($compression['uncompressedCount'] ?? 0);

        return [new AuditIssueData(
            severity: IssueSeverity::High,
            code: 'performance.no_compression',
            message: "{$count} text resource(s) totalling ".$this->kb($bytes).' are served without compression: '.$page->url,
            recommendation: 'Enable gzip or Brotli compression for HTML, CSS and JavaScript. This is usually one server setting and typically cuts these files by 60-80% with no change to the site itself.',
            auditPageId: $page->id,
            meta: [
                'url' => $page->url,
                'uncompressed_count' => $count,
                'compressed_count' => (int) ($compression['compressedCount'] ?? 0),
                'considered_count' => (int) ($compression['consideredCount'] ?? 0),
                'uncompressed_bytes' => $bytes,
                'budget_bytes' => $budget,
                'resources' => $compression['uncompressedSamples'] ?? [],
            ],
        )];
    }

    private function crawlerMetric(AuditPage $page): ?PagePerformanceMetric
    {
        return $page->performanceMetrics
            ->firstWhere(fn ($m) => $m->source === MetricSource::Crawler);
    }

    private function checkResourceBreakdown(AuditPage $page): array
    {
        $metric = $page->performanceMetrics
            ->firstWhere(fn ($m) => $m->source === MetricSource::Crawler);

        if ($metric === null) {
            return [];
        }

        $issues = [];
        $byType = $metric->by_type ?? [];
        $thresholds = config('audit_scoring.performance');

        $bucket = function (string $key) use ($byType): array {
            return ['requests' => $byType[$key]['requests'] ?? 0, 'bytes' => $byType[$key]['bytes'] ?? 0];
        };

        $js = $bucket('js');
        if ($js['bytes'] > $thresholds['max_js_bytes']) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::High,
                code: 'performance.large_javascript',
                message: $this->kb($js['bytes'])." of JavaScript across {$js['requests']} file(s): {$page->url}",
                recommendation: 'Split large bundles, defer non-critical scripts, and remove unused JavaScript so the browser has less to download and execute before the page becomes usable.',
                auditPageId: $page->id,
                meta: ['url' => $page->url, 'bytes' => $js['bytes'], 'requests' => $js['requests'], 'budget_bytes' => $thresholds['max_js_bytes']],
            );
        }

        $css = $bucket('css');
        if ($css['bytes'] > $thresholds['max_css_bytes']) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Medium,
                code: 'performance.large_css',
                message: $this->kb($css['bytes'])." of CSS across {$css['requests']} file(s): {$page->url}",
                recommendation: 'Remove unused CSS rules and inline only what is needed for above-the-fold content.',
                auditPageId: $page->id,
                meta: ['url' => $page->url, 'bytes' => $css['bytes'], 'requests' => $css['requests'], 'budget_bytes' => $thresholds['max_css_bytes']],
            );
        }

        $images = $bucket('image');
        if ($images['bytes'] > $thresholds['max_image_bytes']) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Medium,
                code: 'performance.heavy_images',
                message: $this->kb($images['bytes'])." of images across {$images['requests']} file(s): {$page->url}",
                recommendation: 'Compress images, serve modern formats (WebP/AVIF), and size them to their display dimensions.',
                auditPageId: $page->id,
                meta: ['url' => $page->url, 'bytes' => $images['bytes'], 'requests' => $images['requests'], 'budget_bytes' => $thresholds['max_image_bytes']],
            );
        }

        $fonts = $bucket('font');
        if ($fonts['requests'] > $thresholds['max_font_requests']) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Low,
                code: 'performance.too_many_fonts',
                message: "{$fonts['requests']} font files loaded: {$page->url}",
                recommendation: 'Reduce the number of font families and weights, and use font-display: swap so text stays visible while fonts load.',
                auditPageId: $page->id,
                meta: ['url' => $page->url, 'requests' => $fonts['requests'], 'bytes' => $fonts['bytes'], 'budget_requests' => $thresholds['max_font_requests']],
            );
        }

        if (($metric->total_requests ?? 0) > $thresholds['max_requests']) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Medium,
                code: 'performance.too_many_requests',
                message: "{$metric->total_requests} network requests to load this page: {$page->url}",
                recommendation: 'Bundle assets where practical and remove unnecessary third-party embeds — each request adds connection overhead.',
                auditPageId: $page->id,
                meta: ['url' => $page->url, 'requests' => $metric->total_requests, 'budget_requests' => $thresholds['max_requests']],
            );
        }

        if (($metric->total_transfer_bytes ?? 0) > $thresholds['max_page_weight_bytes']) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::High,
                code: 'performance.heavy_page_weight',
                message: 'Total page weight is '.$this->kb($metric->total_transfer_bytes).": {$page->url}",
                recommendation: 'Reduce the largest resource types first — the breakdown above shows where the weight actually is.',
                auditPageId: $page->id,
                meta: ['url' => $page->url, 'bytes' => $metric->total_transfer_bytes, 'budget_bytes' => $thresholds['max_page_weight_bytes']],
            );
        }

        if (($metric->third_party_bytes ?? 0) > $thresholds['max_third_party_bytes']) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Medium,
                code: 'performance.third_party_weight',
                message: $this->kb($metric->third_party_bytes)." from {$metric->third_party_requests} third-party request(s): {$page->url}",
                recommendation: 'Audit third-party scripts (analytics, chat widgets, embeds) and load them lazily or remove those that are not essential.',
                auditPageId: $page->id,
                meta: ['url' => $page->url, 'bytes' => $metric->third_party_bytes, 'requests' => $metric->third_party_requests, 'budget_bytes' => $thresholds['max_third_party_bytes']],
            );
        }

        if (($metric->ttfb_ms ?? 0) > $thresholds['max_ttfb_ms']) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::High,
                code: 'performance.slow_server_response',
                message: "Server took {$metric->ttfb_ms}ms to send the first byte: {$page->url}",
                recommendation: 'Investigate slow server-side processing, database queries, or missing caching — this delay happens before the browser can render anything.',
                auditPageId: $page->id,
                meta: ['url' => $page->url, 'ttfb_ms' => $metric->ttfb_ms, 'budget_ms' => $thresholds['max_ttfb_ms']],
            );
        }

        if (($metric->dom_nodes ?? 0) > $thresholds['max_dom_nodes']) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Low,
                code: 'performance.excessive_dom_size',
                message: "{$metric->dom_nodes} DOM elements on the page: {$page->url}",
                recommendation: 'Simplify the page structure or paginate long lists — very large DOM trees slow down rendering and interaction.',
                auditPageId: $page->id,
                meta: ['url' => $page->url, 'dom_nodes' => $metric->dom_nodes, 'budget_nodes' => $thresholds['max_dom_nodes']],
            );
        }

        return $issues;
    }

    private function kb(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? round($bytes / 1024 / 1024, 1).'MB'
            : round($bytes / 1024).'KB';
    }

    /** @return AuditIssueData[] */
    private function checkPageSpeedInsights(AuditPage $page): array
    {
        $results = $this->pageSpeed->analyzeBoth($page->url);

        if ($results === []) {
            return [];
        }

        foreach ($results as $strategy => $result) {
            $this->storePageSpeedMetric($page, $strategy, $result);
        }

        // Issues are raised from the MOBILE result when available, since
        // mobile is the primary audit priority; desktop numbers are still
        // stored and shown, they just don't drive the score twice.
        $result = $results['mobile'] ?? reset($results);

        if ($result['score'] === null) {
            return [];
        }

        $issues = [];

        if ($result['score'] < 50) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Critical,
                code: 'performance.pagespeed_score',
                message: "PageSpeed Insights performance score is {$result['score']}/100: {$page->url}",
                recommendation: 'Review the full PageSpeed Insights report for prioritized fixes.',
                auditPageId: $page->id,
                meta: ['url' => $page->url, 'score' => $result['score']],
            );
        } elseif ($result['score'] < 90) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Medium,
                code: 'performance.pagespeed_score',
                message: "PageSpeed Insights performance score is {$result['score']}/100: {$page->url}",
                auditPageId: $page->id,
                meta: ['url' => $page->url, 'score' => $result['score']],
            );
        }

        if ($result['lcpMs'] !== null && $result['lcpMs'] > 2500) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::High,
                code: 'performance.lcp',
                message: 'Largest Contentful Paint is '.round($result['lcpMs']).'ms (should be under 2500ms): '.$page->url,
                auditPageId: $page->id,
                meta: ['url' => $page->url, 'lcp_ms' => $result['lcpMs']],
            );
        }

        if ($result['cls'] !== null && $result['cls'] > 0.1) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Medium,
                code: 'performance.cls',
                message: 'Cumulative Layout Shift is '.round($result['cls'], 3).' (should be under 0.1): '.$page->url,
                auditPageId: $page->id,
                meta: ['url' => $page->url, 'cls' => $result['cls']],
            );
        }

        return $issues;
    }

    /**
     * Stores one strategy's Lighthouse result against this exact page and
     * strategy. The unique key on (page, source, strategy) means a mobile
     * result can never overwrite or masquerade as a desktop one.
     *
     * @param  array<string, mixed>  $result
     */
    private function storePageSpeedMetric(AuditPage $page, string $strategy, array $result): void
    {
        PagePerformanceMetric::updateOrCreate(
            [
                'audit_page_id' => $page->id,
                'source' => MetricSource::PageSpeed->value,
                'strategy' => $strategy,
            ],
            [
                'audit_id' => $page->audit_id,
                'score' => $result['score'],
                'lcp_ms' => $this->intOrNull($result['lcpMs'] ?? null),
                'cls' => $result['cls'] ?? null,
                'tbt_ms' => $this->intOrNull($result['tbtMs'] ?? null),
                'fcp_ms' => $this->intOrNull($result['fcpMs'] ?? null),
                'ttfb_ms' => $this->intOrNull($result['ttfbMs'] ?? null),
                'speed_index_ms' => $this->intOrNull($result['speedIndexMs'] ?? null),
                // Stored per strategy, because mobile and desktop produce
                // different findings with different savings (§45).
                'opportunities' => $result['opportunities'] ?? null,
                'diagnostics' => $result['diagnostics'] ?? null,
                // Null whenever CrUX has too little traffic for this URL —
                // never backfilled from the lab run, which measures a
                // different thing.
                'field_metrics' => $result['fieldMetrics'] ?? null,
            ],
        );
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) round((float) $value);
    }
}
