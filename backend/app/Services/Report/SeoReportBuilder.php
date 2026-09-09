<?php

namespace App\Services\Report;

use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\AuditPage;
use App\Models\AuditScore;
use App\Services\Seo\IndexabilityResolver;
use App\Services\Seo\LinkGraph;
use App\Services\Seo\RedirectAnalyzer;
use App\Services\Seo\SchemaAnalyzer;
use App\Services\Seo\SocialCardAnalyzer;
use Illuminate\Support\Collection;

/**
 * The SEO service's own detail block (§44) — the counterpart to
 * PerformanceReportBuilder and AccessibilityReportBuilder.
 *
 * Carries the four dedicated views the deep SEO audit produces:
 *
 * - **Redirect report** — every redirected URL, its hops and their types,
 *   with chains and loops called out.
 * - **Open Graph preview** — what each page is expected to look like when
 *   shared, and which tag each field came from.
 * - **Schema report** — the structured-data types found, where, and what
 *   properties are missing.
 * - **Page-by-page report** — one row per crawled page with its core SEO
 *   signals, so a reader can scan the site rather than read issue by
 *   issue.
 *
 * These are derived from stored crawl data, never re-fetched. Re-fetching
 * at report time would answer a different question than the audit asked —
 * the site as it is now, not as it was when it was audited.
 */
class SeoReportBuilder
{
    public function __construct(
        private readonly RedirectAnalyzer $redirects,
        private readonly SchemaAnalyzer $schema,
        private readonly SocialCardAnalyzer $social,
        private readonly IndexabilityResolver $indexability,
    ) {}

    /**
     * @param  Collection<int, AuditIssue>  $issues
     * @return array<string, mixed>
     */
    public function build(Audit $audit, AuditScore $score, Collection $issues): array
    {
        $audit->loadMissing(['pages', 'website', 'siteSignals']);

        $breakdown = is_array($score->breakdown) ? $score->breakdown : [];
        $links = new LinkGraph($audit, $audit->website->domain);

        return [
            'coverage' => $breakdown['coverage'] ?? null,
            'redirects' => $this->redirectReport($audit),
            'social' => $this->socialReport($audit),
            'schema' => $this->schemaReport($audit),
            'pages' => $this->pageByPageReport($audit, $links),
            'site' => $this->siteReport($audit, $links),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function redirectReport(Audit $audit): array
    {
        $entries = $this->redirects->report($audit);

        return [
            'total' => count($entries),
            'chains' => count(array_filter($entries, fn (array $e) => $e['is_chain'] && ! $e['is_loop'])),
            'loops' => count(array_filter($entries, fn (array $e) => $e['is_loop'])),
            'temporary' => count(array_filter($entries, fn (array $e) => $e['has_temporary_redirect'])),
            'entries' => $entries,
            // Only URLs the crawl actually followed appear here. A
            // redirect on a page outside the crawl budget is not in this
            // report and is not claimed to be absent.
            'scope_note' => 'Covers redirects encountered while crawling this audit\'s pages.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function socialReport(Audit $audit): array
    {
        $pages = $audit->pages
            ->filter(fn (AuditPage $p) => $p->open_graph !== null || $p->twitter_card !== null)
            ->map(fn (AuditPage $p) => $this->social->analyze($p))
            ->values();

        return [
            // Null, not zero: an audit crawled before social tags were
            // captured has no answer here, and "0 pages have Open Graph
            // tags" would be a false one.
            'analyzed_page_count' => $pages->isEmpty() && $audit->pages->isNotEmpty() ? null : $pages->count(),
            'pages_with_open_graph' => $pages->where('has_open_graph', true)->count(),
            'pages_with_og_image' => $pages->where('has_og_image', true)->count(),
            'pages_with_twitter_card' => $pages->where('has_twitter_card_type', true)->count(),
            'previews' => $pages->all(),
            'preview_note' => 'Previews are derived from the tags each page serves, following the same fallbacks the platforms use. Platforms cache shared links, so a live share may differ until their cache refreshes.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaReport(Audit $audit): array
    {
        $definitions = config('seo_schema.types');
        $byType = [];
        $pageRows = [];

        foreach ($audit->pages as $page) {
            $types = $this->schema->typesOn($page);
            $problems = $this->schema->validate($page);

            foreach ($types as $type) {
                $byType[$type][] = $page->url;
            }

            if ($types === [] && $problems === []) {
                continue;
            }

            $pageRows[] = [
                'url' => $page->url,
                'types' => $types,
                'problems' => $problems,
            ];
        }

        $found = collect($definitions)
            ->map(fn (array $definition, string $type) => [
                'type' => $type,
                'label' => $definition['label'],
                'why' => $definition['why'],
                'present' => isset($byType[$type]),
                'page_count' => count($byType[$type] ?? []),
                'pages' => array_slice($byType[$type] ?? [], 0, config('seo_schema.max_pages_listed_per_issue')),
            ])
            ->values()
            ->all();

        return [
            'types' => $found,
            'pages' => $pageRows,
            'note' => 'Only JSON-LD structured data is read. Microdata and RDFa markup are not extracted by the crawler, so a site using those will show as having no structured data here.',
        ];
    }

    /**
     * One row per crawled page — the scannable view of the whole site.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pageByPageReport(Audit $audit, LinkGraph $links): array
    {
        $inbound = $links->inboundCounts();

        return $audit->pages->map(function (AuditPage $page) use ($links, $inbound) {
            $indexability = $this->indexability->resolve($page);
            $images = collect($page->images ?? []);

            return [
                'url' => $page->url,
                'audit_page_id' => $page->id,
                'http_status' => $page->http_status,
                'title' => $page->title,
                'title_length' => $page->title !== null ? mb_strlen($page->title) : null,
                'meta_description' => $page->meta_description,
                'meta_description_length' => $page->meta_description !== null ? mb_strlen($page->meta_description) : null,
                'canonical_url' => $page->canonical_url,
                'canonical_points_elsewhere' => $this->indexability->canonicalPointsElsewhere($page),
                'is_indexable' => $indexability['is_indexable'],
                'indexability_reasons' => $indexability['reasons'],
                'meta_robots' => $page->meta_robots,
                'h1_count' => collect($page->headings ?? [])->where('level', 1)->count(),
                'heading_count' => count($page->headings ?? []),
                'image_count' => $images->count(),
                'images_missing_alt' => $images->filter(fn ($i) => ! isset($i['alt']) || trim((string) $i['alt']) === '')->count(),
                'internal_links_out' => $links->internalLinkCountFor($page),
                'internal_links_in' => $inbound[$page->url] ?? 0,
                'redirect_hop_count' => count($this->redirects->hops($page)),
                'has_open_graph' => $page->open_graph === null ? null : $page->open_graph !== [],
                'schema_types' => $this->schema->typesOn($page),
                'load_time_ms' => $page->load_time_ms,
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function siteReport(Audit $audit, LinkGraph $links): array
    {
        $signals = $audit->siteSignals;
        $orphans = $links->orphanPages();

        return [
            'robots_txt' => [
                // Three states, kept apart: found, absent, or unknown
                // because the fetch itself failed.
                'status' => match ($signals?->robots_txt_found) {
                    true => 'found',
                    false => 'not_found',
                    default => 'unknown',
                },
                'url' => $signals?->robots_txt_url,
                'disallow_count' => $signals?->robots_disallow_count,
                'declared_sitemaps' => $signals?->robots_sitemaps ?? [],
            ],
            'sitemap' => [
                'status' => match ($signals?->sitemap_found) {
                    true => 'found',
                    false => 'not_found',
                    default => 'unknown',
                },
                'url_count' => $signals?->sitemap_url_count,
                'declared_in_robots' => $signals?->sitemap_declared_in_robots,
            ],
            'links' => [
                'internal' => count($links->internalLinks()),
                'external' => count($links->externalLinks()),
                'broken_internal' => count($links->brokenInternalLinks()),
                'nofollow_internal' => count($links->nofollowInternalLinks()),
                'into_redirects' => count($links->linksIntoRedirects()),
                'orphan_pages' => $orphans->count(),
                'orphan_page_urls' => $orphans->pluck('url')->take(10)->all(),
            ],
        ];
    }
}
