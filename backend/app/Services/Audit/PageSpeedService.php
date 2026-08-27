<?php

namespace App\Services\Audit;

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

    /**
     * @return array{score: int, lcpMs: ?float, cls: ?float, tbtMs: ?float, fcpMs: ?float}|null
     *              Null if the API key isn't configured or the call fails —
     *              callers must degrade gracefully, not fail the whole audit.
     */
    public function analyze(string $url): ?array
    {
        $apiKey = config('services.pagespeed.key');

        if (empty($apiKey)) {
            Log::channel('crawler')->info('PageSpeed Insights skipped: no API key configured', ['url' => $url]);

            return null;
        }

        try {
            $response = Http::timeout(45)->get(self::ENDPOINT, [
                'url' => $url,
                'key' => $apiKey,
                'category' => 'performance',
                'strategy' => 'mobile',
            ]);
        } catch (\Throwable $e) {
            Log::channel('crawler')->warning('PageSpeed Insights request failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::channel('crawler')->warning('PageSpeed Insights returned an error', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $audits = $response->json('lighthouseResult.audits', []);
        $performanceScore = $response->json('lighthouseResult.categories.performance.score');

        return [
            'score' => $performanceScore !== null ? (int) round($performanceScore * 100) : 0,
            'lcpMs' => $audits['largest-contentful-paint']['numericValue'] ?? null,
            'cls' => $audits['cumulative-layout-shift']['numericValue'] ?? null,
            'tbtMs' => $audits['total-blocking-time']['numericValue'] ?? null,
            'fcpMs' => $audits['first-contentful-paint']['numericValue'] ?? null,
        ];
    }
}
