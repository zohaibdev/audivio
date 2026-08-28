<?php

namespace App\Services\Crawler;

use App\DTOs\CrawledPageData;
use App\Enums\CrawlJobStatus;
use App\Enums\CrawlUrlStatus;
use App\Enums\ScreenshotViewport;
use App\Models\Audit;
use App\Models\AuditPage;
use App\Models\CrawlJob;
use App\Models\CrawlUrl;
use App\Models\Screenshot;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CrawlOrchestrator
{
    public function __construct(
        private readonly UrlSecurityValidator $validator,
        private readonly RobotsTxtService $robotsTxt,
        private readonly SitemapService $sitemap,
        private readonly PlatformDetector $platformDetector,
        private readonly PlaywrightCrawlerClient $crawlerClient,
    ) {}

    /**
     * Crawl the homepage, detect the platform, discover the rest of the
     * site's URLs (sitemap first, falling back to on-page links), and seed
     * the CrawlJob/CrawlUrl rows. Returns the CrawlJob with its pending
     * (not-yet-crawled) URLs — the caller dispatches CrawlPageJob for each.
     */
    public function startCrawl(Audit $audit): CrawlJob
    {
        $website = $audit->website;
        $this->validator->assertSafe($website->url);

        $homepage = $this->crawlerClient->crawl(
            $website->url,
            $this->screenshotAbsoluteDir($audit->id, 'homepage'),
        );

        $website->update([
            'platform' => $this->platformDetector->detect($homepage->htmlSample, $homepage->headers),
        ]);

        $crawlJob = CrawlJob::create([
            'audit_id' => $audit->id,
            'status' => CrawlJobStatus::Running,
            'started_at' => now(),
        ]);

        $homepageCrawlUrl = CrawlUrl::create([
            'crawl_job_id' => $crawlJob->id,
            'url' => $website->url,
            'status' => CrawlUrlStatus::Completed,
            'http_status' => $homepage->httpStatus,
            'depth' => 0,
            'discovered_via' => 'seed',
            'attempted_at' => now(),
        ]);

        $this->storeAuditPage($audit, $homepageCrawlUrl, $homepage);

        $discovered = $this->discoverUrls($website->url, $website->domain, $audit->max_pages);
        $normalizedHomepage = $this->normalizeUrl($website->url);

        $pendingCrawlUrls = [];
        foreach ($discovered as $url) {
            if ($this->normalizeUrl($url) === $normalizedHomepage) {
                continue;
            }

            $pendingCrawlUrls[] = CrawlUrl::create([
                'crawl_job_id' => $crawlJob->id,
                'url' => $url,
                'status' => CrawlUrlStatus::Pending,
                'depth' => 1,
                'discovered_via' => 'sitemap',
            ]);
        }

        $crawlJob->update([
            'total_urls' => count($pendingCrawlUrls) + 1,
            'processed_urls' => 1,
        ]);

        return $crawlJob->fresh('urls');
    }

    /**
     * Crawl one previously-discovered URL, store its AuditPage, and expand
     * the frontier with any new same-domain links found on it (breadth-
     * first, bounded by the audit's page limit and the crawler's depth
     * limit). Returns newly created pending CrawlUrl rows for the caller
     * to dispatch jobs for.
     *
     * @return CrawlUrl[]
     */
    public function crawlDiscoveredUrl(CrawlUrl $crawlUrl): array
    {
        $crawlJob = $crawlUrl->crawlJob;
        $audit = $crawlJob->audit;
        $website = $audit->website;

        $crawlUrl->update(['status' => CrawlUrlStatus::Crawling, 'attempted_at' => now()]);

        try {
            $this->validator->assertSafe($crawlUrl->url);
            $pageData = $this->crawlerClient->crawl(
                $crawlUrl->url,
                $this->screenshotAbsoluteDir($audit->id, (string) $crawlUrl->id),
            );
        } catch (\Throwable $e) {
            Log::channel('crawler')->warning('Discovered URL failed to crawl', [
                'audit_id' => $audit->id,
                'url' => $crawlUrl->url,
                'error' => $e->getMessage(),
            ]);

            $crawlUrl->update(['status' => CrawlUrlStatus::Failed, 'error_message' => Str::limit($e->getMessage(), 250)]);
            $crawlJob->increment('failed_urls');

            return [];
        }

        $crawlUrl->update(['status' => CrawlUrlStatus::Completed, 'http_status' => $pageData->httpStatus]);
        $crawlJob->increment('processed_urls');

        $this->storeAuditPage($audit, $crawlUrl, $pageData);

        return $this->expandFrontier($crawlJob, $crawlUrl, $pageData, $website->domain, $audit->max_pages);
    }

    /**
     * @return CrawlUrl[]
     */
    private function expandFrontier(CrawlJob $crawlJob, CrawlUrl $fromUrl, CrawledPageData $pageData, string $domain, int $maxPages): array
    {
        if ($fromUrl->depth + 1 > config('crawler.max_depth')) {
            return [];
        }

        $crawlJob->refresh();
        $remainingBudget = $maxPages - $crawlJob->total_urls;
        if ($remainingBudget <= 0) {
            return [];
        }

        $existingUrls = $crawlJob->urls()->pluck('url')->all();

        $candidates = collect($pageData->links)
            ->pluck('href')
            ->filter(fn ($href) => is_string($href) && $this->isSameDomain($href, $domain))
            ->map(fn ($href) => $this->normalizeUrl($href))
            ->unique()
            ->reject(fn ($url) => in_array($url, $existingUrls, true))
            ->take($remainingBudget)
            ->values();

        $created = [];
        foreach ($candidates as $url) {
            $created[] = CrawlUrl::create([
                'crawl_job_id' => $crawlJob->id,
                'url' => $url,
                'status' => CrawlUrlStatus::Pending,
                'depth' => $fromUrl->depth + 1,
                'discovered_via' => 'link',
            ]);
        }

        if ($created !== []) {
            $crawlJob->increment('total_urls', count($created));
        }

        return $created;
    }

    /**
     * @return string[]
     */
    private function discoverUrls(string $baseUrl, string $domain, int $maxPages): array
    {
        $robots = $this->robotsTxt->fetch($baseUrl);

        $sitemapSeeds = $robots['sitemaps'] !== []
            ? $robots['sitemaps']
            : [$this->sitemap->defaultSitemapUrl($baseUrl)];

        $urls = $this->sitemap->discoverUrls($sitemapSeeds, $domain);

        $normalizedHomepage = $this->normalizeUrl($baseUrl);

        $urls = array_values(array_filter(
            $urls,
            fn ($url) => $this->robotsTxt->isAllowed($robots['disallow'], parse_url($url, PHP_URL_PATH) ?? '/')
                && $this->normalizeUrl($url) !== $normalizedHomepage,
        ));

        return array_slice($urls, 0, max(0, $maxPages - 1));
    }

    private function storeAuditPage(Audit $audit, CrawlUrl $crawlUrl, CrawledPageData $data): AuditPage
    {
        $page = AuditPage::create([
            'audit_id' => $audit->id,
            'crawl_url_id' => $crawlUrl->id,
            'url' => $data->url,
            'http_status' => $data->httpStatus,
            'title' => $data->title,
            'meta_description' => $data->metaDescription,
            'canonical_url' => $data->canonicalUrl,
            'redirect_chain' => $data->redirectChain,
            'headings' => $data->headings,
            'links' => $data->links,
            'images' => $data->images,
            'forms' => $data->forms,
            'structured_data' => $data->structuredData,
            'cta_elements' => $data->ctaElements,
            'accessibility_violations' => $data->accessibilityViolations,
            'html_size_bytes' => $data->htmlSizeBytes,
            'load_time_ms' => $data->loadTimeMs,
            'crawled_at' => now(),
        ]);

        $this->storeScreenshot($audit, $page, ScreenshotViewport::Desktop, $data->screenshotDesktopPath);
        $this->storeScreenshot($audit, $page, ScreenshotViewport::Mobile, $data->screenshotMobilePath);

        return $page;
    }

    /**
     * A null path means capture failed (or wasn't requested) for this
     * viewport — screenshots are an enhancement, not required, so this
     * just leaves that viewport unavailable rather than failing the crawl.
     */
    private function storeScreenshot(Audit $audit, AuditPage $page, ScreenshotViewport $viewport, ?string $absolutePath): void
    {
        if ($absolutePath === null) {
            return;
        }

        Screenshot::create([
            'audit_id' => $audit->id,
            'audit_page_id' => $page->id,
            'viewport' => $viewport,
            'width' => $viewport->width(),
            'height' => $viewport->height(),
            'storage_path' => $this->toPublicPath($absolutePath),
        ]);
    }

    private function isSameDomain(string $url, string $domain): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return $host !== null && strtolower($host) === strtolower($domain);
    }

    private function normalizeUrl(string $url): string
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return "{$scheme}://{$host}{$path}{$query}";
    }

    private function screenshotAbsoluteDir(int $auditId, string $identifier): string
    {
        $relative = "screenshots/{$auditId}/{$identifier}";
        $disk = Storage::disk(config('crawler.screenshot_disk'));
        $disk->makeDirectory($relative);

        return $disk->path($relative);
    }

    private function toPublicPath(string $absolutePath): string
    {
        $disk = Storage::disk(config('crawler.screenshot_disk'));
        $basePath = $disk->path('');

        // Normalize BOTH sides to forward slashes before comparing. The
        // two arrive with different separators on Windows: the incoming
        // path comes from the Node crawler (always forward slashes), while
        // $disk->path('') is OS-native (backslashes). Comparing them
        // as-is never matches, so the prefix is never stripped and the
        // full absolute filesystem path gets stored — which then 403s when
        // the frontend requests it under /storage. Caught by inspecting a
        // real screenshot row after a live audit; every existing test uses
        // Storage::fake(), which is forward-slash on both sides and so
        // cannot reproduce the mismatch (see the regression test, which
        // passes a backslash base path explicitly).
        $normalizedAbsolute = str_replace('\\', '/', $absolutePath);
        $normalizedBase = rtrim(str_replace('\\', '/', $basePath), '/').'/';

        $relative = str_starts_with($normalizedAbsolute, $normalizedBase)
            ? substr($normalizedAbsolute, strlen($normalizedBase))
            : $normalizedAbsolute;

        return ltrim($relative, '/');
    }
}
