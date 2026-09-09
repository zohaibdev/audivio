<?php

namespace App\Services\Crawler;

use Illuminate\Support\Facades\Log;

class SitemapService
{
    private const MAX_SUB_SITEMAPS = 20;

    private const MAX_URLS = 5000;

    public function __construct(
        private readonly SafeHttpClient $http,
        private readonly UrlNormalizer $normalizer,
    ) {}

    /**
     * @param  string[]  $sitemapUrls  Seed sitemap URLs (from robots.txt or the default /sitemap.xml)
     * @return string[] Discovered page URLs, same-domain only
     */
    public function discoverUrls(array $sitemapUrls, string $domain): array
    {
        $urls = [];
        $sitemapsFetched = 0;

        // The seeds are filtered by domain like every nested entry, not
        // trusted because of where they came from: a `Sitemap:` line in
        // robots.txt is attacker-controlled content on the audited site,
        // so it can name any host it likes. Off-domain seeds are dropped
        // rather than fetched, which keeps the crawl to the site the user
        // actually asked to audit.
        $queue = array_values(array_filter(
            $sitemapUrls,
            fn ($url) => is_string($url) && $this->isSameDomain($url, $domain),
        ));

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
            $result = $this->http->get($url);
        } catch (\Throwable $e) {
            Log::channel('crawler')->info('Sitemap fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        if ($result === null) {
            return null;
        }

        libxml_use_internal_errors(true);
        // LIBXML_NONET stops the parser itself from becoming an SSRF
        // vector by fetching an external DTD or schema the document names.
        // LIBXML_NOENT is deliberately *not* passed: leaving entity
        // substitution off is what keeps a hostile sitemap from reading
        // local files (XXE) or expanding into gigabytes (billion laughs).
        $xml = simplexml_load_string($result['body'], 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();

        return $xml ?: null;
    }

    private function isSameDomain(string $url, string $domain): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if ($host === null || $host === false) {
            return false;
        }

        return rtrim(strtolower($host), '.') === rtrim(strtolower($domain), '.');
    }

    public function defaultSitemapUrl(string $baseUrl): string
    {
        return $this->normalizer->origin($baseUrl).'/sitemap.xml';
    }
}
