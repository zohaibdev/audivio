<?php

namespace App\Services\Seo;

use App\Models\Audit;
use App\Models\AuditPage;
use Illuminate\Support\Collection;

/**
 * The site's internal link structure as the crawl saw it (§44).
 *
 * Built once per audit and handed to every check that needs it, because
 * each of them — broken links, orphan pages, nofollow, links into
 * redirects — is a different question about the same graph, and building
 * it four times over a 25-page crawl is four times the work for the same
 * answer.
 *
 * Everything here is scoped to what was actually crawled. A link to a
 * page outside the crawl budget is not a broken link, and this never
 * claims otherwise: unknown targets are counted as unknown.
 */
class LinkGraph
{
    /** @var array<string, AuditPage> normalized URL => page */
    private array $pagesByUrl = [];

    /** @var array<string, string[]> normalized target URL => source page URLs */
    private array $inbound = [];

    /** @var array<int, array<string, mixed>> */
    private array $internalLinks = [];

    /** @var array<int, array<string, mixed>> */
    private array $externalLinks = [];

    public function __construct(
        private readonly Audit $audit,
        private readonly string $domain,
    ) {
        $this->build();
    }

    private function build(): void
    {
        foreach ($this->audit->pages as $page) {
            $this->pagesByUrl[$this->normalize($page->url)] = $page;
        }

        foreach ($this->audit->pages as $page) {
            foreach ($page->links ?? [] as $link) {
                $href = $link['href'] ?? null;

                if (! is_string($href) || $href === '') {
                    continue;
                }

                $host = parse_url($href, PHP_URL_HOST);

                if ($host === null || $host === false) {
                    continue;
                }

                $record = [
                    'from' => $page->url,
                    'from_page_id' => $page->id,
                    'href' => $href,
                    'text' => $link['text'] ?? '',
                    'rel' => $link['rel'] ?? null,
                    'is_nofollow' => $this->isNofollow($link['rel'] ?? null),
                ];

                if ($this->isSameSite($host)) {
                    $this->internalLinks[] = $record;
                    $target = $this->normalize($href);
                    $this->inbound[$target][] = $page->url;
                } else {
                    $this->externalLinks[] = $record;
                }
            }
        }
    }

    /**
     * Links pointing at a crawled page that returned an error status.
     *
     * Only crawled targets can be judged: asserting a link is broken
     * without having fetched it would be a guess presented as a finding.
     *
     * @return array<int, array<string, mixed>>
     */
    public function brokenInternalLinks(): array
    {
        $broken = [];

        foreach ($this->internalLinks as $link) {
            $target = $this->pagesByUrl[$this->normalize($link['href'])] ?? null;

            if ($target !== null && $target->http_status !== null && $target->http_status >= 400) {
                $broken[] = $link + ['status' => $target->http_status];
            }
        }

        return $broken;
    }

    /**
     * Internal links whose target was crawled and turned out to redirect.
     * Not an error, but every one of them is a hop a visitor and a crawler
     * pay for on a link the site controls and could point straight at the
     * destination.
     *
     * @return array<int, array<string, mixed>>
     */
    public function linksIntoRedirects(): array
    {
        $found = [];

        foreach ($this->internalLinks as $link) {
            $target = $this->pagesByUrl[$this->normalize($link['href'])] ?? null;

            if ($target !== null && count($target->redirect_chain ?? []) > 0) {
                $found[] = $link + ['final_url' => $target->url];
            }
        }

        return $found;
    }

    /**
     * Internal links marked `rel="nofollow"`. On a site's own pages this
     * is almost always accidental — it tells search engines not to follow
     * a link into the site's own content.
     *
     * @return array<int, array<string, mixed>>
     */
    public function nofollowInternalLinks(): array
    {
        return array_values(array_filter($this->internalLinks, fn (array $link) => $link['is_nofollow']));
    }

    /**
     * Crawled pages that nothing else in the crawl links to.
     *
     * The entry point is excluded — nothing internal links to a homepage
     * from above it. A page reachable only through the sitemap is **not**
     * excluded: "the sitemap knows about it" is exactly the situation this
     * check exists to surface, since a crawler that finds a page only
     * there has no signal about how important it is.
     *
     * @return Collection<int, AuditPage>
     */
    public function orphanPages(): Collection
    {
        $entryUrl = $this->normalize($this->audit->website->primary_url);

        return $this->audit->pages->filter(function (AuditPage $page) use ($entryUrl) {
            $normalized = $this->normalize($page->url);

            if ($normalized === $entryUrl) {
                return false;
            }

            $sources = $this->inbound[$normalized] ?? [];

            // A page that only links to itself is still an orphan.
            return collect($sources)->reject(fn (string $from) => $this->normalize($from) === $normalized)->isEmpty();
        })->values();
    }

    /**
     * How many internal links point at each crawled page, keyed by URL.
     *
     * @return array<string, int>
     */
    public function inboundCounts(): array
    {
        $counts = [];

        foreach ($this->audit->pages as $page) {
            $normalized = $this->normalize($page->url);
            $counts[$page->url] = count(array_unique($this->inbound[$normalized] ?? []));
        }

        return $counts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function internalLinks(): array
    {
        return $this->internalLinks;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function externalLinks(): array
    {
        return $this->externalLinks;
    }

    public function internalLinkCountFor(AuditPage $page): int
    {
        return count(array_filter($this->internalLinks, fn (array $l) => $l['from_page_id'] === $page->id));
    }

    /**
     * `www.example.com` and `example.com` are the same site for link
     * purposes; treating them as different would report a site's own
     * navigation as external links.
     */
    private function isSameSite(string $host): bool
    {
        $host = strtolower(ltrim($host, '.'));
        $domain = strtolower($this->domain);

        return $host === $domain
            || $host === "www.{$domain}"
            || "www.{$host}" === $domain
            || str_ends_with($host, ".{$domain}");
    }

    private function isNofollow(mixed $rel): bool
    {
        if (! is_string($rel)) {
            return false;
        }

        foreach (preg_split('/\s+/', strtolower($rel)) as $token) {
            if ($token === 'nofollow') {
                return true;
            }
        }

        return false;
    }

    /**
     * Fragment dropped, trailing slash ignored, host case-folded, and
     * the scheme left out entirely: a site that links to itself over both
     * http and https is linking to one page, and keeping the scheme would
     * invent an orphan and a duplicate out of that. `/about` and
     * `/about#team` collapse for the same reason.
     */
    private function normalize(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return rtrim(strtolower($url), '/');
        }

        $host = strtolower($parts['host']);
        $path = rtrim($parts['path'] ?? '', '/');
        $query = isset($parts['query']) ? "?{$parts['query']}" : '';

        return "{$host}{$path}{$query}";
    }
}
