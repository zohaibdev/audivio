<?php

namespace App\Services\Crawler;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SitemapService
{
    private const MAX_SUB_SITEMAPS = 20;

    private const MAX_URLS = 5000;

    public function __construct(
        private readonly UrlSecurityValidator $validator,
    ) {}

    /**
     * @param  string[]  $sitemapUrls  Seed sitemap URLs (from robots.txt or the default /sitemap.xml)
     * @return string[] Discovered page URLs, same-domain only
     */
    public function discoverUrls(array $sitemapUrls, string $domain): array
    {
        $urls = [];
        $sitemapsFetched = 0;
        $queue = $sitemapUrls;

        while ($queue !== [] && $sitemapsFetched < self::MAX_SUB_SITEMAPS && count($urls) < self::MAX_URLS) {
            $sitemapUrl = array_shift($queue);
            $sitemapsFetched++;

            $xml = $this->fetchXml($sitemapUrl);
            if ($xml === null) {
                continue;
            }

            if (isset($xml->sitemap)) {
                foreach ($xml->sitemap as $entry) {
                    $loc = trim((string) $entry->loc);
                    if ($loc !== '' && $this->isSameDomain($loc, $domain)) {
                        $queue[] = $loc;
                    }
                }

                continue;
            }

            if (isset($xml->url)) {
                foreach ($xml->url as $entry) {
                    $loc = trim((string) $entry->loc);
                    if ($loc !== '' && $this->isSameDomain($loc, $domain)) {
                        $urls[] = $loc;
                    }
                    if (count($urls) >= self::MAX_URLS) {
                        break;
                    }
                }
            }
        }

        return array_values(array_unique($urls));
    }

    private function fetchXml(string $url): ?\SimpleXMLElement
    {
        try {
            $this->validator->assertSafe($url);
            $response = Http::withUserAgent(config('crawler.user_agent'))
                ->timeout(config('crawler.request_timeout'))
                ->get($url);
        } catch (\Throwable $e) {
            Log::channel('crawler')->info('Sitemap fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response->body());
        libxml_clear_errors();

        return $xml ?: null;
    }

    private function isSameDomain(string $url, string $domain): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return $host !== null && strtolower($host) === strtolower($domain);
    }

    public function defaultSitemapUrl(string $baseUrl): string
    {
        $parts = parse_url($baseUrl);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';

        return "{$scheme}://{$host}/sitemap.xml";
    }
}
