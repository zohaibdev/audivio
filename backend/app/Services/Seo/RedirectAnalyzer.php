<?php

namespace App\Services\Seo;

use App\Models\Audit;
use App\Models\AuditPage;

/**
 * The redirect report (§44): every page that was reached through one or
 * more redirects, what kind each hop was, and which of them are chains or
 * loops.
 *
 * A redirect is not a defect. A *chain* of them costs a hop on every
 * request and dilutes the signal passed along; a *loop* is a page nobody
 * can reach at all. Those are different findings and are reported
 * separately.
 *
 * Hops recorded before §44 are bare URL strings with no status. Those
 * still produce a chain — the hop count is the part that matters — but
 * their `status` and `type` read as unknown rather than being guessed at.
 * A 301 and a 302 mean opposite things to a search engine, and inventing
 * one would be worse than admitting the crawl did not record it.
 */
class RedirectAnalyzer
{
    /**
     * Every redirected page, one entry per destination.
     *
     * @return array<int, array<string, mixed>>
     */
    public function report(Audit $audit): array
    {
        $entries = [];

        foreach ($audit->pages as $page) {
            $hops = $this->hops($page);

            if ($hops === []) {
                continue;
            }

            $entries[] = [
                'final_url' => $page->url,
                'audit_page_id' => $page->id,
                'hop_count' => count($hops),
                'hops' => $hops,
                'is_chain' => count($hops) > 1,
                'is_loop' => $this->isLoop($page, $hops),
                'has_temporary_redirect' => collect($hops)->contains(fn (array $h) => $h['type'] === 'temporary'),
                'final_status' => $page->http_status,
            ];
        }

        return $entries;
    }

    /**
     * Normalized hops for one page: `{url, status, type}` per hop.
     *
     * @return array<int, array{url: string, status: int|null, type: string}>
     */
    public function hops(AuditPage $page): array
    {
        $chain = $page->redirect_chain;

        if (! is_array($chain)) {
            return [];
        }

        $hops = [];

        foreach ($chain as $hop) {
            // Pre-§44 shape: a bare URL string.
            if (is_string($hop)) {
                $hops[] = ['url' => $hop, 'status' => null, 'type' => 'unknown'];

                continue;
            }

            if (! is_array($hop) || ! isset($hop['url'])) {
                continue;
            }

            $status = isset($hop['status']) && is_numeric($hop['status']) ? (int) $hop['status'] : null;

            $hops[] = [
                'url' => (string) $hop['url'],
                'status' => $status,
                'type' => $this->typeFor($status),
            ];
        }

        return $hops;
    }

    /**
     * A loop is a chain that revisits a URL it has already been through,
     * including the destination it finally landed on.
     *
     * In practice the browser gives up before completing one, so this
     * catches the repetition the crawl did see rather than claiming to
     * have followed a loop to its end.
     *
     * @param  array<int, array{url: string, status: int|null, type: string}>  $hops
     */
    public function isLoop(AuditPage $page, array $hops): bool
    {
        $seen = [];

        foreach ($hops as $hop) {
            $normalized = $this->normalize($hop['url']);

            if (isset($seen[$normalized])) {
                return true;
            }

            $seen[$normalized] = true;
        }

        return isset($seen[$this->normalize($page->url)]);
    }

    /**
     * 301/308 pass their ranking signal on and tell search engines the
     * move is permanent; 302/303/307 explicitly do not. A permanent move
     * served as a temporary redirect is one of the most common and most
     * expensive SEO mistakes there is.
     */
    private function typeFor(?int $status): string
    {
        return match ($status) {
            null => 'unknown',
            301, 308 => 'permanent',
            302, 303, 307 => 'temporary',
            default => 'other',
        };
    }

    /**
     * Keeps the scheme, unlike the link graph's normalization.
     *
     * `http://example.com/x` redirecting to `https://example.com/x` is the
     * single most common legitimate redirect on the web. Collapsing the
     * two would make it look like a URL redirecting to itself, and this
     * class would report a critical redirect loop on almost every site
     * that has HTTPS.
     */
    private function normalize(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return rtrim(strtolower($url), '/');
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $path = rtrim($parts['path'] ?? '', '/');
        $query = isset($parts['query']) ? "?{$parts['query']}" : '';

        return "{$scheme}://{$host}{$path}{$query}";
    }
}
