<?php

namespace App\Services\Seo;

use App\DTOs\AuditIssueData;
use App\Enums\IssueSeverity;
use App\Models\Audit;
use App\Models\AuditPage;

/**
 * SEO findings that only exist across pages (§44) — duplicates, orphans,
 * broken links, redirect chains, and the two site-level files (robots.txt
 * and the sitemap).
 *
 * These are site-wide by construction, so they carry their own
 * `affected_pages` and bypass `PerPageIssueAggregator` entirely.
 */
class SiteSeoChecks
{
    public function __construct(
        private readonly RedirectAnalyzer $redirects,
        private readonly SchemaAnalyzer $schema,
    ) {}

    /**
     * @return AuditIssueData[]
     */
    public function run(Audit $audit, LinkGraph $links): array
    {
        return array_merge(
            $this->checkRobotsTxt($audit),
            $this->checkSitemap($audit),
            $this->checkDuplicateTitles($audit),
            $this->checkDuplicateMetaDescriptions($audit),
            $this->checkBrokenLinks($links),
            $this->checkRedirects($audit),
            $this->checkLinksIntoRedirects($links),
            $this->checkNofollowInternalLinks($links),
            $this->checkOrphanPages($links),
            $this->checkSiteWideSchema($audit),
        );
    }

    /** @return AuditIssueData[] */
    private function checkRobotsTxt(Audit $audit): array
    {
        $signals = $audit->siteSignals;

        // No signals row means the crawl never reached discovery (or
        // predates §44). Silence is correct: we have nothing to report.
        if ($signals === null || $signals->robots_txt_found === null) {
            return [];
        }

        if ($signals->robots_txt_found) {
            return [];
        }

        return [new AuditIssueData(
            severity: IssueSeverity::Low,
            code: 'seo.missing_robots_txt',
            message: 'The site has no robots.txt file.',
            recommendation: 'Add a robots.txt at the site root. A site works without one, but it is where you declare your sitemap and control which paths crawlers spend their budget on.',
            meta: ['robots_url' => $signals->robots_txt_url],
        )];
    }

    /** @return AuditIssueData[] */
    private function checkSitemap(Audit $audit): array
    {
        $signals = $audit->siteSignals;

        if ($signals === null || $signals->sitemap_found === null) {
            return [];
        }

        if (! $signals->sitemap_found) {
            return [new AuditIssueData(
                severity: IssueSeverity::Medium,
                code: 'seo.missing_sitemap',
                message: 'No XML sitemap was found for this site.',
                recommendation: 'Publish an XML sitemap and declare it in robots.txt. It is how a search engine learns about pages that are not reachable within a few clicks of the homepage.',
                meta: ['robots_sitemaps' => $signals->robots_sitemaps ?? []],
            )];
        }

        if ($signals->sitemap_declared_in_robots === false) {
            return [new AuditIssueData(
                severity: IssueSeverity::Low,
                code: 'seo.sitemap_not_declared',
                message: 'A sitemap exists but is not declared in robots.txt.',
                recommendation: 'Add a "Sitemap:" line to robots.txt. The sitemap was only found because it sits at the conventional /sitemap.xml path — a crawler that does not guess will never see it.',
                meta: ['sitemap_url_count' => $signals->sitemap_url_count],
            )];
        }

        return [];
    }

    /** @return AuditIssueData[] */
    private function checkDuplicateTitles(Audit $audit): array
    {
        return $this->duplicateValueIssues(
            $audit,
            fn (AuditPage $page) => $page->title,
            'seo.duplicate_title',
            'title',
            'Give each page a unique, descriptive title. Pages sharing a title compete with each other for the same searches.',
        );
    }

    /** @return AuditIssueData[] */
    private function checkDuplicateMetaDescriptions(Audit $audit): array
    {
        return $this->duplicateValueIssues(
            $audit,
            fn (AuditPage $page) => $page->meta_description,
            'seo.duplicate_meta_description',
            'meta description',
            'Write a distinct description for each page. A shared one gives search engines no reason to prefer one of your pages over another.',
        );
    }

    /**
     * @param  callable(AuditPage): ?string  $valueOf
     * @return AuditIssueData[]
     */
    private function duplicateValueIssues(Audit $audit, callable $valueOf, string $code, string $label, string $recommendation): array
    {
        $limit = (int) config('audit_scoring.seo.max_items_listed_per_issue');
        $groups = [];

        foreach ($audit->pages as $page) {
            $value = $valueOf($page);

            if ($value === null || trim($value) === '') {
                continue;
            }

            $groups[trim($value)][] = $page;
        }

        $issues = [];

        foreach ($groups as $value => $pages) {
            if (count($pages) < 2) {
                continue;
            }

            $urls = array_map(fn (AuditPage $p) => $p->url, $pages);

            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Medium,
                code: $code,
                message: count($pages)." pages share the same {$label}: \"".mb_strimwidth($value, 0, 80, '…').'"',
                recommendation: $recommendation,
                meta: [
                    'value' => $value,
                    'affected_pages' => array_slice($urls, 0, $limit),
                    'affected_page_count' => count($urls),
                ],
                affectedPageIds: array_map(fn (AuditPage $p) => $p->id, $pages),
            );
        }

        return $issues;
    }

    /** @return AuditIssueData[] */
    private function checkBrokenLinks(LinkGraph $links): array
    {
        $broken = $links->brokenInternalLinks();

        if ($broken === []) {
            return [];
        }

        $limit = (int) config('audit_scoring.seo.max_items_listed_per_issue');
        $sourcePages = array_values(array_unique(array_column($broken, 'from')));

        return [new AuditIssueData(
            severity: IssueSeverity::High,
            code: 'seo.broken_internal_links',
            message: count($broken).' internal link(s) point to pages that return an error.',
            recommendation: 'Fix or remove these links. Every one of them sends a visitor — and a crawler following your own navigation — to a dead end.',
            meta: [
                'broken_links' => array_slice(array_map(fn (array $l) => [
                    'from' => $l['from'],
                    'to' => $l['href'],
                    'status' => $l['status'],
                    'anchor_text' => $l['text'],
                ], $broken), 0, $limit),
                'total_broken' => count($broken),
                'affected_pages' => array_slice($sourcePages, 0, $limit),
                'affected_page_count' => count($sourcePages),
            ],
        )];
    }

    /**
     * Redirect chains and loops, from the same report the dedicated
     * redirect view renders.
     *
     * @return AuditIssueData[]
     */
    private function checkRedirects(Audit $audit): array
    {
        $limit = (int) config('audit_scoring.seo.max_items_listed_per_issue');
        $maxHops = (int) config('audit_scoring.seo.max_redirect_hops');
        $report = $this->redirects->report($audit);

        if ($report === []) {
            return [];
        }

        $issues = [];

        $loops = array_values(array_filter($report, fn (array $entry) => $entry['is_loop']));

        if ($loops !== []) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Critical,
                code: 'seo.redirect_loop',
                message: count($loops).' URL(s) redirect in a loop.',
                recommendation: 'Break the loop. A URL that redirects back to itself is unreachable for visitors and search engines alike.',
                meta: [
                    'redirects' => array_slice(array_map(fn (array $e) => [
                        'url' => $e['final_url'],
                        'hops' => array_column($e['hops'], 'url'),
                    ], $loops), 0, $limit),
                    'affected_pages' => array_slice(array_column($loops, 'final_url'), 0, $limit),
                    'affected_page_count' => count($loops),
                ],
                affectedPageIds: array_column($loops, 'audit_page_id'),
            );
        }

        $chains = array_values(array_filter(
            $report,
            fn (array $entry) => ! $entry['is_loop'] && $entry['hop_count'] > $maxHops,
        ));

        if ($chains !== []) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Medium,
                code: 'seo.redirect_chain',
                message: count($chains).' URL(s) are reached through a chain of redirects.',
                recommendation: 'Point the first URL straight at the final destination. Each extra hop costs load time and dilutes the ranking signal passed along.',
                meta: [
                    'redirects' => array_slice(array_map(fn (array $e) => [
                        'url' => $e['final_url'],
                        'hop_count' => $e['hop_count'],
                        'hops' => $e['hops'],
                    ], $chains), 0, $limit),
                    'affected_pages' => array_slice(array_column($chains, 'final_url'), 0, $limit),
                    'affected_page_count' => count($chains),
                ],
                affectedPageIds: array_column($chains, 'audit_page_id'),
            );
        }

        // A permanent move served as a temporary redirect tells search
        // engines to keep the old URL indexed. Reported only where the
        // status was actually recorded — never inferred.
        $temporary = array_values(array_filter(
            $report,
            fn (array $entry) => $entry['has_temporary_redirect'] && ! $entry['is_loop'],
        ));

        if ($temporary !== []) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Low,
                code: 'seo.temporary_redirect',
                message: count($temporary).' URL(s) use a temporary (302/307) redirect.',
                recommendation: 'Use a 301 for moves that are permanent. A 302 tells search engines to keep indexing the old URL, so the new one never inherits the ranking.',
                meta: [
                    'redirects' => array_slice(array_map(fn (array $e) => [
                        'url' => $e['final_url'],
                        'hops' => $e['hops'],
                    ], $temporary), 0, $limit),
                    'affected_pages' => array_slice(array_column($temporary, 'final_url'), 0, $limit),
                    'affected_page_count' => count($temporary),
                ],
                affectedPageIds: array_column($temporary, 'audit_page_id'),
            );
        }

        return $issues;
    }

    /** @return AuditIssueData[] */
    private function checkLinksIntoRedirects(LinkGraph $links): array
    {
        $found = $links->linksIntoRedirects();

        if ($found === []) {
            return [];
        }

        $limit = (int) config('audit_scoring.seo.max_items_listed_per_issue');
        $sourcePages = array_values(array_unique(array_column($found, 'from')));

        return [new AuditIssueData(
            severity: IssueSeverity::Low,
            code: 'seo.internal_links_to_redirects',
            message: count($found).' internal link(s) point at URLs that redirect.',
            recommendation: 'Update these links to point at the final URL. These are links on your own pages, so the extra hop is entirely avoidable.',
            meta: [
                'links' => array_slice(array_map(fn (array $l) => [
                    'from' => $l['from'],
                    'to' => $l['href'],
                    'final_url' => $l['final_url'],
                ], $found), 0, $limit),
                'affected_pages' => array_slice($sourcePages, 0, $limit),
                'affected_page_count' => count($sourcePages),
            ],
        )];
    }

    /** @return AuditIssueData[] */
    private function checkNofollowInternalLinks(LinkGraph $links): array
    {
        $found = $links->nofollowInternalLinks();

        if ($found === []) {
            return [];
        }

        $limit = (int) config('audit_scoring.seo.max_items_listed_per_issue');
        $sourcePages = array_values(array_unique(array_column($found, 'from')));

        return [new AuditIssueData(
            severity: IssueSeverity::Low,
            code: 'seo.nofollow_internal_links',
            message: count($found).' internal link(s) are marked rel="nofollow".',
            recommendation: 'Remove rel="nofollow" from links to your own pages. It tells search engines not to follow a route into your own content, which is almost never what a site intends.',
            meta: [
                'links' => array_slice(array_map(fn (array $l) => [
                    'from' => $l['from'],
                    'to' => $l['href'],
                ], $found), 0, $limit),
                'affected_pages' => array_slice($sourcePages, 0, $limit),
                'affected_page_count' => count($sourcePages),
            ],
        )];
    }

    /** @return AuditIssueData[] */
    private function checkOrphanPages(LinkGraph $links): array
    {
        $orphans = $links->orphanPages();

        if ($orphans->isEmpty()) {
            return [];
        }

        $limit = (int) config('audit_scoring.seo.max_items_listed_per_issue');

        return [new AuditIssueData(
            severity: IssueSeverity::Medium,
            code: 'seo.orphan_pages',
            message: $orphans->count().' crawled page(s) have no internal links pointing to them.',
            recommendation: 'Link to these pages from somewhere in your navigation or content. A page a crawler can only find through the sitemap reads as unimportant to it.',
            meta: [
                'affected_pages' => $orphans->pluck('url')->take($limit)->all(),
                'affected_page_count' => $orphans->count(),
            ],
            affectedPageIds: $orphans->pluck('id')->all(),
        )];
    }

    /**
     * Types expected once per site rather than once per page.
     *
     * @return AuditIssueData[]
     */
    private function checkSiteWideSchema(Audit $audit): array
    {
        $expected = config('seo_schema.site_wide_types', []);
        $definitions = config('seo_schema.types');

        $declared = [];
        foreach ($audit->pages as $page) {
            foreach ($this->schema->typesOn($page) as $type) {
                $declared[$type] = true;
            }
        }

        $issues = [];

        foreach ($expected as $type) {
            if (isset($declared[$type])) {
                continue;
            }

            $definition = $definitions[$type] ?? null;

            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Medium,
                code: "seo.missing_schema.{$type}",
                message: "No {$type} structured data was found anywhere on the crawled pages.",
                recommendation: ($definition['why'] ?? '')." Add {$type} JSON-LD, usually on the homepage.",
                meta: [
                    'schema_type' => $type,
                    'declared_types' => array_keys($declared),
                    // Said plainly, because it bounds the finding: this
                    // reads JSON-LD only, and a site using microdata would
                    // be reported as having none.
                    'note' => 'Only JSON-LD structured data is analyzed. Microdata and RDFa markup are not read.',
                ],
            );
        }

        return $issues;
    }
}
