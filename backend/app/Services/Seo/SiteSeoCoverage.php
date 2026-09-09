<?php

namespace App\Services\Seo;

use App\Models\Audit;
use App\Models\AuditPage;

/**
 * What the SEO audit was actually able to check (§44).
 *
 * Every named check either ran or did not, and the difference matters:
 * a check that never ran — because the crawl predates the field it reads,
 * or because robots.txt could not be fetched — produces no findings, and
 * so looks exactly like a check that passed. This makes that distinction
 * explicit rather than leaving the reader to infer a clean bill of health
 * from silence.
 */
class SiteSeoCoverage
{
    /**
     * The checks this audit reports on, in reading order.
     */
    public const CHECKS = [
        'title' => 'Title',
        'meta_description' => 'Meta description',
        'canonical' => 'Canonical',
        'robots' => 'Robots directives',
        'sitemap' => 'Sitemap',
        'broken_links' => 'Broken links',
        'redirects' => 'Redirects',
        'redirect_chains' => 'Redirect chains',
        'redirect_loops' => 'Redirect loops',
        'http_status' => 'HTTP status',
        'og_tags' => 'Open Graph tags',
        'og_image' => 'Open Graph image',
        'twitter_cards' => 'Twitter/X cards',
        'schema_organization' => 'Organization schema',
        'schema_localbusiness' => 'LocalBusiness schema',
        'schema_product' => 'Product schema',
        'schema_article' => 'Article schema',
        'schema_breadcrumb' => 'Breadcrumb schema',
        'schema_faq' => 'FAQ schema',
        'indexability' => 'Indexability',
        'h1' => 'H1',
        'heading_hierarchy' => 'Heading hierarchy',
        'images' => 'Images',
        'internal_links' => 'Internal links',
    ];

    /**
     * @return array<string, mixed>
     */
    public function summarize(Audit $audit, LinkGraph $links): array
    {
        $pages = $audit->pages;
        $signals = $audit->siteSignals;

        // Social tags and robots directives arrived with §44; a page
        // crawled before it has null, not an empty map.
        $socialCaptured = $pages->contains(fn (AuditPage $p) => $p->open_graph !== null || $p->twitter_card !== null);
        $robotsCaptured = $pages->contains(fn (AuditPage $p) => $p->response_headers !== null)
            || $pages->contains(fn (AuditPage $p) => $p->meta_robots !== null);

        $ran = [
            'title' => true,
            'meta_description' => true,
            'canonical' => true,
            'robots' => $robotsCaptured,
            'sitemap' => $signals !== null,
            'broken_links' => true,
            'redirects' => true,
            'redirect_chains' => true,
            'redirect_loops' => true,
            'http_status' => true,
            'og_tags' => $socialCaptured,
            'og_image' => $socialCaptured,
            'twitter_cards' => $socialCaptured,
            'schema_organization' => true,
            'schema_localbusiness' => true,
            'schema_product' => true,
            'schema_article' => true,
            'schema_breadcrumb' => true,
            'schema_faq' => true,
            // Indexability reads the robots directives plus the canonical
            // and status, so it degrades with them rather than being off.
            'indexability' => true,
            'h1' => true,
            'heading_hierarchy' => true,
            'images' => true,
            'internal_links' => true,
        ];

        return [
            'pages_analyzed' => $pages->count(),
            'checks' => collect(self::CHECKS)
                ->map(fn (string $label, string $key) => [
                    'check' => $key,
                    'label' => $label,
                    'ran' => $ran[$key] ?? false,
                    'not_run_reason' => ($ran[$key] ?? false) ? null : $this->reasonFor($key),
                ])
                ->values()
                ->all(),
            'internal_links' => count($links->internalLinks()),
            'external_links' => count($links->externalLinks()),
            'robots_txt_found' => $signals?->robots_txt_found,
            'sitemap_found' => $signals?->sitemap_found,
            'sitemap_url_count' => $signals?->sitemap_url_count,
            // The bound on structured-data analysis, stated where the
            // coverage is stated rather than buried in an issue.
            'structured_data_note' => 'Structured data analysis reads JSON-LD only. Microdata and RDFa are not extracted.',
        ];
    }

    private function reasonFor(string $check): string
    {
        return match ($check) {
            'og_tags', 'og_image', 'twitter_cards' => 'This audit was crawled before social card tags were captured. Re-run the audit to include them.',
            'robots' => 'No robots directives were captured for this crawl. Re-run the audit to include them.',
            'sitemap' => 'The crawl recorded no sitemap result, so this check had nothing to read.',
            default => 'This check did not run for this audit.',
        };
    }
}
