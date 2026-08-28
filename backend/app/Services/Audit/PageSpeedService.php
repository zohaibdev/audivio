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
            // Lighthouse "opportunities" carry a measured millisecond
            // saving; anything without one is a diagnostic, not an
            // opportunity. Both are passed through as-is — we never invent
            // a saving Lighthouse didn't compute.
            'opportunities' => $this->extractOpportunities($audits),
        ], $elapsedMs, $response->status()];
    }

    /**
     * @param  array<string, mixed>  $audits
     * @return array<int, array<string, mixed>>
     */
    private function extractOpportunities(array $audits): array
    {
        $opportunities = [];

        foreach ($audits as $id => $audit) {
            $savingsMs = $audit['details']['overallSavingsMs'] ?? null;
            $savingsBytes = $audit['details']['overallSavingsBytes'] ?? null;

            if ($savingsMs === null && $savingsBytes === null) {
                continue;
            }

            // score === 1 means Lighthouse considers this already passing;
            // surfacing it as an "opportunity" would be noise.
            if (($audit['score'] ?? null) === 1) {
                continue;
            }

            $opportunities[] = [
                'id' => $id,
                'title' => $audit['title'] ?? $id,
                'description' => $audit['description'] ?? null,
                'savings_ms' => $savingsMs !== null ? (int) round($savingsMs) : null,
                'savings_bytes' => $savingsBytes !== null ? (int) round($savingsBytes) : null,
            ];
        }

        usort($opportunities, fn ($a, $b) => ($b['savings_ms'] ?? 0) <=> ($a['savings_ms'] ?? 0));

        return array_slice($opportunities, 0, 15);
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
