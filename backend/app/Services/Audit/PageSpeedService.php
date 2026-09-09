<?php

namespace App\Services\Audit;

use App\Models\ApiProvider;
use App\Models\ApiUsageLog;
use App\Services\AI\AiProviderException;
use App\Services\Provider\ProviderResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client for Google's PageSpeed Insights API. Deliberately not called
 * per crawled page — it's slow (10-30s) and rate-limited, so
 * PerformanceAuditModule only runs it against a configured subset of pages
 * (the homepage by default).
 */
class PageSpeedService
{
    private const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    private const TEST_URL = 'https://example.com';

    /**
     * Runs mobile and desktop as two independent measurements, keyed by
     * strategy. Each is attempted separately, so one failing (or one being
     * disabled in provider config) never silently substitutes the other's
     * numbers — the missing strategy is simply absent from the result.
     *
     * @return array<string, array<string, mixed>> keyed 'mobile'/'desktop'; empty if neither ran
     */
    public function analyzeBoth(string $url): array
    {
        $provider = null;

        try {
            $provider = (new ProviderResolver)->resolve('pagespeed');
        } catch (\Throwable) {
            return [];
        }

        $results = [];

        foreach (['mobile' => 'mobile_enabled', 'desktop' => 'desktop_enabled'] as $strategy => $configKey) {
            // Mobile defaults on (the primary audit priority), desktop off
            // — matching the existing provider config defaults so enabling
            // desktop stays an explicit admin choice.
            if (! $provider->getConfigValue($configKey, $strategy === 'mobile')) {
                continue;
            }

            $result = $this->analyze($url, $strategy);

            if ($result !== null) {
                $results[$strategy] = $result;
            }
        }

        return $results;
    }

    /**
     * One strategy's measurement. Null if the API key isn't configured,
     * the provider is disabled, its request limit is reached, or the call
     * fails — callers must degrade gracefully, not fail the whole audit.
     *
     * @return array<string, mixed>|null
     */
    public function analyze(string $url, string $strategy = 'mobile'): ?array
    {
        $resolver = new ProviderResolver;

        try {
            $provider = $resolver->resolve('pagespeed');
        } catch (\Throwable) {
            Log::channel('crawler')->info('PageSpeed Insights skipped: not configured or disabled', ['url' => $url]);

            return null;
        }

        if ($this->exceedsConfiguredLimits($provider)) {
            Log::channel('crawler')->info('PageSpeed Insights skipped: request limit reached', ['url' => $url]);
            $this->logUsage(success: false, errorCategory: 'limit_exceeded');

            return null;
        }

        try {
            [$result, $elapsedMs, $status] = $this->performRequest($resolver, $provider, $url, $strategy);
        } catch (\Throwable $e) {
            Log::channel('crawler')->warning('PageSpeed Insights request failed', ['url' => $url, 'error' => $e->getMessage()]);
            $this->logUsage(success: false, errorCategory: 'exception');

            return null;
        }

        if ($result === null) {
            $this->logUsage(success: false, httpStatus: $status, responseTimeMs: $elapsedMs, errorCategory: 'http_error');

            return null;
        }

        $this->logUsage(success: true, httpStatus: $status, responseTimeMs: $elapsedMs);

        return $result;
    }

    public function testConnection(): array
    {
        $resolver = new ProviderResolver;

        try {
            $provider = $resolver->resolveConfigured('pagespeed');
        } catch (AiProviderException $e) {
            return ['success' => false, 'response_time_ms' => null, 'message' => $e->getMessage()];
        }

        try {
            [$result, $elapsedMs] = $this->performRequest($resolver, $provider, self::TEST_URL);
        } catch (\Throwable $e) {
            return ['success' => false, 'response_time_ms' => null, 'message' => $e->getMessage()];
        }

        if ($result === null) {
            return ['success' => false, 'response_time_ms' => $elapsedMs, 'message' => 'PageSpeed Insights returned an error response.'];
        }

        return ['success' => true, 'response_time_ms' => $elapsedMs, 'message' => 'Connected successfully.'];
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: int, 2: ?int} [result-or-null, elapsed ms, http status]
     */
    private function performRequest(ProviderResolver $resolver, ApiProvider $provider, string $url, string $strategy = 'mobile'): array
    {
        $startedAt = microtime(true);

        $response = Http::timeout($provider->getConfigValue('request_timeout', 45))->get(self::ENDPOINT, [
            'url' => $url,
            'key' => $provider->api_key,
            'category' => 'performance',
            'strategy' => $strategy,
        ]);

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        if (! $response->successful()) {
            $resolver->recordFailure($provider, "HTTP {$response->status()}");

            return [null, $elapsedMs, $response->status()];
        }

        $resolver->recordSuccess($provider);

        $audits = $response->json('lighthouseResult.audits', []);
        $performanceScore = $response->json('lighthouseResult.categories.performance.score');
        [$opportunities, $diagnostics] = $this->splitFindings($audits);

        return [[
            // A missing category score stays null rather than becoming 0 —
            // "Lighthouse didn't report a score" is not "this page scored
            // zero", and the report must be able to tell them apart.
            'score' => $performanceScore !== null ? (int) round($performanceScore * 100) : null,
            'strategy' => $strategy,
            'lcpMs' => $audits['largest-contentful-paint']['numericValue'] ?? null,
            'cls' => $audits['cumulative-layout-shift']['numericValue'] ?? null,
            'tbtMs' => $audits['total-blocking-time']['numericValue'] ?? null,
            'fcpMs' => $audits['first-contentful-paint']['numericValue'] ?? null,
            'ttfbMs' => $audits['server-response-time']['numericValue'] ?? null,
            'speedIndexMs' => $audits['speed-index']['numericValue'] ?? null,
            // Lighthouse "opportunities" carry a measured saving; a
            // failing audit without one is a diagnostic. Both are passed
            // through as-is — we never invent a saving Lighthouse didn't
            // compute (section 45).
            'opportunities' => $opportunities,
            'diagnostics' => $diagnostics,
            // Real-user (CrUX) measurements for this URL, when Google has
            // enough traffic to report them. This is the only place INP
            // can come from: it is a field metric, and a lab run cannot
            // produce one.
            'fieldMetrics' => $this->extractFieldMetrics($response->json('loadingExperience')),
        ], $elapsedMs, $response->status()];
    }

    /**
     * Split Lighthouse's failing audits into opportunities and
     * diagnostics (§45).
     *
     * The distinction is Lighthouse's own, not ours: an audit carrying a
     * measured `overallSavingsMs`/`Bytes` is an opportunity — fixing it
     * saves a quantified amount. Everything else that failed is a
     * diagnostic: a real problem with no number attached. Keeping them
     * apart matters because showing "potential savings" on a finding
     * Lighthouse never quantified would invent the one figure a reader
     * would act on.
     *
     * Passing audits (score 1) are skipped, and so are informative ones
     * with no score at all — neither is a finding.
     *
     * @param  array<string, mixed>  $audits
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function splitFindings(array $audits): array
    {
        $max = (int) config('performance_categories.max_items');
        $opportunities = [];
        $diagnostics = [];

        foreach ($audits as $id => $audit) {
            if (! is_array($audit)) {
                continue;
            }

            $score = $audit['score'] ?? null;

            // score === 1 is already passing; a null score is an
            // informative audit with no pass/fail judgement.
            if ($score === null || $score >= 1) {
                continue;
            }

            $savingsMs = $audit['details']['overallSavingsMs'] ?? null;
            $savingsBytes = $audit['details']['overallSavingsBytes'] ?? null;

            $entry = [
                'id' => $id,
                'title' => $audit['title'] ?? $id,
                'description' => $audit['description'] ?? null,
                'category' => $this->categoryFor($id),
                'score' => $score,
                // Lighthouse's own human-readable summary ("Potential
                // savings of 320 KiB"), kept verbatim rather than
                // recomputed from the raw numbers.
                'display_value' => $audit['displayValue'] ?? null,
                'resources' => $this->extractResources($audit),
            ];

            if ($savingsMs === null && $savingsBytes === null) {
                $diagnostics[] = $entry;

                continue;
            }

            $opportunities[] = $entry + [
                'savings_ms' => $savingsMs !== null ? (int) round($savingsMs) : null,
                'savings_bytes' => $savingsBytes !== null ? (int) round($savingsBytes) : null,
            ];
        }

        // Biggest measured time saving first — the order a reader should
        // work in. Diagnostics have no savings to rank by, so the worst
        // score leads instead.
        usort($opportunities, fn ($a, $b) => ($b['savings_ms'] ?? 0) <=> ($a['savings_ms'] ?? 0));
        usort($diagnostics, fn ($a, $b) => ($a['score'] ?? 1) <=> ($b['score'] ?? 1));

        return [array_slice($opportunities, 0, $max), array_slice($diagnostics, 0, $max)];
    }

    /**
     * The specific resources an audit blames, from its details table.
     *
     * Lighthouse names the URL, and usually a size and a saving, per row.
     * Without these a finding says "your images are too big" and leaves
     * the owner to work out which ones.
     *
     * @param  array<string, mixed>  $audit
     * @return array<int, array<string, mixed>>
     */
    private function extractResources(array $audit): array
    {
        $items = $audit['details']['items'] ?? null;

        if (! is_array($items)) {
            return [];
        }

        $max = (int) config('performance_categories.max_resources_per_item');
        $resources = [];

        foreach ($items as $item) {
            if (count($resources) >= $max) {
                break;
            }

            if (! is_array($item)) {
                continue;
            }

            // Rows without a URL are summary rows (totals, group headers).
            $url = $item['url'] ?? ($item['source']['url'] ?? null);

            if (! is_string($url) || $url === '') {
                continue;
            }

            $resources[] = array_filter([
                'url' => substr($url, 0, 500),
                'total_bytes' => isset($item['totalBytes']) ? (int) round($item['totalBytes']) : null,
                'wasted_bytes' => isset($item['wastedBytes']) ? (int) round($item['wastedBytes']) : null,
                'wasted_ms' => isset($item['wastedMs']) ? (int) round($item['wastedMs']) : null,
            ], fn ($value) => $value !== null);
        }

        return $resources;
    }

    /**
     * CrUX field data for this URL — what real visitors actually
     * experienced over the trailing 28 days (§45).
     *
     * This is the only honest source of INP: it is measured from real
     * interactions, and no lab run can produce one. When Google has too
     * little traffic for a URL there is no field data at all, and the
     * report says so rather than substituting the lab numbers, which
     * describe a different thing entirely.
     *
     * @param  array<string, mixed>|null  $loadingExperience
     * @return array<string, mixed>|null
     */
    private function extractFieldMetrics(?array $loadingExperience): ?array
    {
        $metrics = $loadingExperience['metrics'] ?? null;

        if (! is_array($metrics) || $metrics === []) {
            return null;
        }

        $map = [
            'lcp_ms' => 'LARGEST_CONTENTFUL_PAINT_MS',
            'inp_ms' => 'INTERACTION_TO_NEXT_PAINT',
            'cls' => 'CUMULATIVE_LAYOUT_SHIFT_SCORE',
            'fcp_ms' => 'FIRST_CONTENTFUL_PAINT_MS',
            'ttfb_ms' => 'EXPERIMENTAL_TIME_TO_FIRST_BYTE',
        ];

        $extracted = [];

        foreach ($map as $key => $cruxKey) {
            $percentile = $metrics[$cruxKey]['percentile'] ?? null;

            if ($percentile === null) {
                continue;
            }

            $extracted[$key] = [
                // CrUX reports CLS multiplied by 100 as an integer.
                'value' => $key === 'cls' ? $percentile / 100 : (int) $percentile,
                'category' => $metrics[$cruxKey]['category'] ?? null,
            ];
        }

        if ($extracted === []) {
            return null;
        }

        return [
            'source' => 'crux',
            'overall_category' => $loadingExperience['overall_category'] ?? null,
            'metrics' => $extracted,
        ];
    }

    private function categoryFor(string $auditId): string
    {
        return config("performance_categories.audits.{$auditId}", 'other');
    }

    private function exceedsConfiguredLimits(ApiProvider $provider): bool
    {
        $dailyLimit = $provider->getConfigValue('daily_request_limit');

        if ($dailyLimit === null) {
            return false;
        }

        $today = ApiUsageLog::where('provider', 'pagespeed')->whereDate('created_at', now()->toDateString())->count();

        return $today >= $dailyLimit;
    }

    private function logUsage(bool $success, ?int $httpStatus = null, ?int $responseTimeMs = null, ?string $errorCategory = null): void
    {
        ApiUsageLog::create([
            'provider' => 'pagespeed',
            'service' => 'pagespeed_analyze',
            'success' => $success,
            'http_status' => $httpStatus,
            'response_time_ms' => $responseTimeMs,
            'error_category' => $errorCategory,
        ]);
    }
}
