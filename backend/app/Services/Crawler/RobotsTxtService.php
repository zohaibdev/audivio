<?php

namespace App\Services\Crawler;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RobotsTxtService
{
    public function __construct(
        private readonly UrlSecurityValidator $validator,
    ) {}

    /**
     * @return array{disallow: string[], sitemaps: string[]}
     */
    public function fetch(string $baseUrl): array
    {
        $robotsUrl = $this->buildRobotsUrl($baseUrl);

        try {
            $this->validator->assertSafe($robotsUrl);
            $response = Http::withUserAgent(config('crawler.user_agent'))
                ->timeout(config('crawler.request_timeout'))
                ->get($robotsUrl);
        } catch (\Throwable $e) {
            Log::channel('crawler')->info('robots.txt unavailable, proceeding without it', [
                'url' => $robotsUrl,
                'error' => $e->getMessage(),
            ]);

            return ['disallow' => [], 'sitemaps' => []];
        }

        if (! $response->successful()) {
            return ['disallow' => [], 'sitemaps' => []];
        }

        return $this->parse($response->body());
    }

    private function buildRobotsUrl(string $baseUrl): string
    {
        $parts = parse_url($baseUrl);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';

        return "{$scheme}://{$host}/robots.txt";
    }

    /**
     * @return array{disallow: string[], sitemaps: string[]}
     */
    private function parse(string $contents): array
    {
        $disallow = [];
        $sitemaps = [];
        $appliesToUs = false;

        foreach (preg_split('/\r\n|\r|\n/', $contents) as $line) {
            $line = trim(preg_replace('/#.*/', '', $line));
            if ($line === '') {
                continue;
            }

            if (preg_match('/^User-agent:\s*(.+)$/i', $line, $m)) {
                $appliesToUs = trim($m[1]) === '*';

                continue;
            }

            if ($appliesToUs && preg_match('/^Disallow:\s*(.*)$/i', $line, $m)) {
                $path = trim($m[1]);
                if ($path !== '') {
                    $disallow[] = $path;
                }

                continue;
            }

            if (preg_match('/^Sitemap:\s*(.+)$/i', $line, $m)) {
                $sitemaps[] = trim($m[1]);
            }
        }

        return ['disallow' => $disallow, 'sitemaps' => $sitemaps];
    }

    public function isAllowed(array $disallowRules, string $path): bool
    {
        foreach ($disallowRules as $rule) {
            if ($rule === '/') {
                return false;
            }
            if (str_starts_with($path, $rule)) {
                return false;
            }
        }

        return true;
    }
}
