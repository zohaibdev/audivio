<?php

namespace App\Services\Seo;

use App\Models\AuditPage;

/**
 * Whether a crawled page can appear in search results, and why not when
 * it cannot (§44).
 *
 * Four things can exclude a page, and an owner can usually see only the
 * first: a `noindex` in the HTML, a `noindex` in the `X-Robots-Tag`
 * response header, an error status, or a canonical tag pointing somewhere
 * else. The last two are the ones that surprise people — a page that
 * renders perfectly for a visitor can be invisible to a search engine.
 *
 * A `Disallow` in robots.txt is deliberately **not** treated as
 * non-indexable here. It blocks crawling, not indexing, and a disallowed
 * URL can still be indexed from external links. It is reported as its own
 * finding instead of being folded into this verdict.
 */
class IndexabilityResolver
{
    /**
     * @return array{is_indexable: bool|null, reasons: string[], directives: array<string, string|null>}
     */
    public function resolve(AuditPage $page): array
    {
        $meta = $this->directive($page->meta_robots);
        $googlebot = $this->directive($page->meta_googlebot);
        $header = $this->directive($this->headerDirective($page));

        $reasons = [];

        if ($page->http_status !== null && $page->http_status >= 400) {
            $reasons[] = "The page returns HTTP {$page->http_status}, so there is nothing for a search engine to index.";
        }

        if ($this->hasDirective($meta, 'noindex')) {
            $reasons[] = 'The page carries a <meta name="robots"> tag containing "noindex".';
        }

        if ($this->hasDirective($googlebot, 'noindex')) {
            $reasons[] = 'The page carries a <meta name="googlebot"> tag containing "noindex", which excludes it from Google specifically.';
        }

        if ($this->hasDirective($header, 'noindex')) {
            // The one nobody finds by viewing source.
            $reasons[] = 'The server sends an X-Robots-Tag response header containing "noindex". This does not appear anywhere in the page markup.';
        }

        if ($this->canonicalPointsElsewhere($page)) {
            $reasons[] = "The canonical tag points to {$page->canonical_url}, so this URL asks search engines to index that page instead.";
        }

        return [
            'is_indexable' => $reasons === [],
            'reasons' => $reasons,
            'directives' => [
                'meta_robots' => $page->meta_robots,
                'meta_googlebot' => $page->meta_googlebot,
                'x_robots_tag' => $this->headerDirective($page),
            ],
        ];
    }

    /**
     * A canonical is only a problem when it names a *different* page.
     * Self-referencing canonicals are correct and extremely common, and
     * flagging them would bury the real cases.
     */
    public function canonicalPointsElsewhere(AuditPage $page): bool
    {
        if (empty($page->canonical_url)) {
            return false;
        }

        return $this->normalize($page->canonical_url) !== $this->normalize($page->url);
    }

    public function hasDirective(?string $directive, string $needle): bool
    {
        if ($directive === null) {
            return false;
        }

        foreach (explode(',', $directive) as $part) {
            if (strtolower(trim($part)) === $needle) {
                return true;
            }
        }

        return false;
    }

    private function headerDirective(AuditPage $page): ?string
    {
        $headers = $page->response_headers;

        return is_array($headers) && isset($headers['x-robots-tag'])
            ? (string) $headers['x-robots-tag']
            : null;
    }

    private function directive(?string $value): ?string
    {
        return $value === null ? null : strtolower(trim($value));
    }

    /**
     * Compares URLs the way a search engine treats them for canonical
     * purposes: scheme and host case-folded, a trailing slash on the path
     * ignored, fragment dropped. Query strings are kept — `?page=2` is a
     * different page, not a formatting difference.
     */
    private function normalize(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return rtrim($url, '/');
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $path = rtrim($parts['path'] ?? '', '/');
        $query = isset($parts['query']) ? "?{$parts['query']}" : '';

        return "{$scheme}://{$host}{$path}{$query}";
    }
}
