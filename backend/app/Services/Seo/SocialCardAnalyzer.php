<?php

namespace App\Services\Seo;

use App\Models\AuditPage;

/**
 * Open Graph and Twitter/X card analysis, and the preview that shows an
 * owner what their page looks like when someone shares it (§44).
 *
 * The preview is built from the same fallback rules the platforms use:
 * Twitter falls back to the Open Graph tags when its own are absent, and
 * both fall back to the `<title>` and meta description when there is no
 * Open Graph either. Rendering a preview from `og:title` alone would show
 * a blank card for a page that actually shares fine.
 *
 * The preview is a **prediction, not a capture.** It is what the tags say
 * should appear; the platforms cache aggressively and apply their own
 * rules on top, so the report labels it as derived from the page's tags.
 */
class SocialCardAnalyzer
{
    /** Tags Open Graph treats as required for any shared page. */
    private const OG_REQUIRED = ['og:title', 'og:description', 'og:image', 'og:url'];

    /**
     * @return array<string, mixed>
     */
    public function analyze(AuditPage $page): array
    {
        $og = is_array($page->open_graph) ? $page->open_graph : [];
        $twitter = is_array($page->twitter_card) ? $page->twitter_card : [];

        $missingOg = array_values(array_filter(
            self::OG_REQUIRED,
            fn (string $tag) => ! $this->filled($og[$tag] ?? null),
        ));

        return [
            'url' => $page->url,
            'has_open_graph' => $og !== [],
            'has_twitter_card' => $twitter !== [],
            'missing_og_tags' => $missingOg,
            'has_og_image' => $this->filled($og['og:image'] ?? null),
            // `twitter:card` is the one tag with no Open Graph fallback:
            // without it X renders a plain link regardless of what else
            // the page declares.
            'has_twitter_card_type' => $this->filled($twitter['twitter:card'] ?? null),
            'og_type' => $og['og:type'] ?? null,
            'preview' => $this->preview($page, $og, $twitter),
        ];
    }

    /**
     * What a share of this page is expected to render, and where each
     * field came from — so an owner can see that a title they never wrote
     * for social is being borrowed from the `<title>` tag.
     *
     * @param  array<string, mixed>  $og
     * @param  array<string, mixed>  $twitter
     * @return array<string, mixed>
     */
    public function preview(AuditPage $page, array $og, array $twitter): array
    {
        return [
            'title' => $this->resolve([
                ['twitter:title', $twitter['twitter:title'] ?? null, false],
                ['og:title', $og['og:title'] ?? null, false],
                ['<title>', $page->title, true],
            ]),
            'description' => $this->resolve([
                ['twitter:description', $twitter['twitter:description'] ?? null, false],
                ['og:description', $og['og:description'] ?? null, false],
                ['meta description', $page->meta_description, true],
            ]),
            'image' => $this->resolve([
                ['twitter:image', $twitter['twitter:image'] ?? null, false],
                ['og:image', $og['og:image'] ?? null, false],
            ]),
            'site_name' => $this->resolve([
                ['og:site_name', $og['og:site_name'] ?? null, false],
            ]),
            'url' => $this->resolve([
                ['og:url', $og['og:url'] ?? null, false],
                ['page URL', $page->url, true],
            ]),
            'card_type' => $twitter['twitter:card'] ?? null,
        ];
    }

    /**
     * First filled candidate wins, and the answer says which one it was.
     * A null `value` with a null `source` is an honest "this will be
     * blank" — not an empty string standing in for content.
     *
     * `is_fallback` marks a value borrowed from a tag that was never
     * written for sharing — the `<title>`, the meta description, the page
     * URL. `og:title` standing in for an absent `twitter:title` is not a
     * fallback in that sense: it is the tag the author wrote for exactly
     * this purpose, and flagging it would send owners to duplicate every
     * Open Graph tag under a twitter: prefix for no benefit.
     *
     * @param  array<int, array{0: string, 1: mixed, 2: bool}>  $candidates  [label, value, isBorrowed]
     * @return array{value: string|null, source: string|null, is_fallback: bool}
     */
    private function resolve(array $candidates): array
    {
        foreach ($candidates as [$source, $value, $isBorrowed]) {
            if ($this->filled($value)) {
                return [
                    'value' => (string) $value,
                    'source' => $source,
                    'is_fallback' => $isBorrowed,
                ];
            }
        }

        return ['value' => null, 'source' => null, 'is_fallback' => false];
    }

    private function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
