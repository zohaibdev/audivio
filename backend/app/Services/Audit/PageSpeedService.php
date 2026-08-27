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
     * @return array{score: int, lcpMs: ?float, cls: ?float, tbtMs: ?float, fcpMs: ?float}|null
     *                                                                                          Null if the API key isn't configured, the provider is
     *                                                                                          disabled, its request limit is reached, or the call
     *                                                                                          fails — callers must degrade gracefully, not fail the
     *                                                                                          whole audit.
     */
    public function analyze(string $url): ?array
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
            [$result, $elapsedMs, $status] = $this->performRequest($resolver, $provider, $url);
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
    private function performRequest(ProviderResolver $resolver, ApiProvider $provider, string $url): array
    {
        $strategy = $provider->getConfigValue('mobile_enabled', true) ? 'mobile' : 'desktop';

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
            'score' => $performanceScore !== null ? (int) round($performanceScore * 100) : 0,
            'lcpMs' => $audits['largest-contentful-paint']['numericValue'] ?? null,
            'cls' => $audits['cumulative-layout-shift']['numericValue'] ?? null,
            'tbtMs' => $audits['total-blocking-time']['numericValue'] ?? null,
            'fcpMs' => $audits['first-contentful-paint']['numericValue'] ?? null,
        ], $elapsedMs, $response->status()];
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
