<?php

namespace App\Services\Crawler;

use Illuminate\Support\Facades\Log;

class RobotsTxtService
{
    public function __construct(
        private readonly SafeHttpClient $http,
        private readonly UrlNormalizer $normalizer,
    ) {}

    /**
     * `found` is a three-state answer (§44): true when robots.txt was
     * fetched, false when the request completed and there was nothing
     * there, and null when the request itself failed. The SEO audit
     * reports the last of those as "could not check", never as "missing" —
     * sending an owner to create a file that already exists is worse than
     * saying nothing.
     *
     * @return array{disallow: string[], sitemaps: string[], found: bool|null, url: string|null}
     */
    public function fetch(string $baseUrl): array
    {
        $robotsUrl = null;

        try {
            $robotsUrl = $this->buildRobotsUrl($baseUrl);
            $result = $this->http->get($robotsUrl);
        } catch (\Throwable $e) {
            Log::channel('crawler')->info('robots.txt unavailable, proceeding without it', [
                'url' => $robotsUrl ?? $baseUrl,
                'error' => $e->getMessage(),
            ]);

            return ['disallow' => [], 'sitemaps' => [], 'found' => null, 'url' => $robotsUrl];
        }

        if ($result === null) {
            return ['disallow' => [], 'sitemaps' => [], 'found' => false, 'url' => $robotsUrl];
        }

        return $this->parse($result['body']) + ['found' => true, 'url' => $robotsUrl];
    }

    private function buildRobotsUrl(string $baseUrl): string
    {
        return $this->normalizer->origin($baseUrl).'/robots.txt';
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
