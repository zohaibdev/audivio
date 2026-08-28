<?php

namespace App\Services\Audit;

use App\DTOs\AuditIssueData;

/**
 * Collapses per-page AuditIssueData findings that share the same `code`
 * into one issue: a single occurrence keeps its own page-specific
 * `audit_page_id` and meta; two or more occurrences of the same code
 * become one site-wide issue (`audit_page_id = null`) with
 * `meta.affected_pages`/`affected_page_count`, mirroring the pattern
 * AccessibilityAuditModule already established for axe violations
 * (CONTEXT.md §11/§31).
 *
 * This exists because SeoAuditModule/PerformanceAuditModule used to raise
 * one issue per page per check — on a 25-page site where every page is
 * missing a meta description, that's 25 near-identical "high" severity
 * deductions (250 points) instead of one real finding, which floors the
 * category score at 0 regardless of how the site actually looks. Each
 * check method must produce a message ending in ": {$page->url}" and put
 * that same URL in `meta['url']` — this is what lets aggregation strip the
 * page-specific suffix and merge per-page `meta` into `instances`.
 */
class PerPageIssueAggregator
{
    /**
     * @param  AuditIssueData[]  $perPageIssues  one entry per page finding — codes may repeat
     * @return AuditIssueData[]
     */
    public static function aggregate(array $perPageIssues): array
    {
        $byCode = [];
        foreach ($perPageIssues as $issue) {
            $byCode[$issue->code][] = $issue;
        }

        $result = [];
        foreach ($byCode as $code => $instances) {
            if (count($instances) === 1) {
                $result[] = $instances[0];

                continue;
            }

            $first = $instances[0];
            $pages = array_values(array_unique(array_filter(array_map(
                fn (AuditIssueData $i) => $i->meta['url'] ?? null,
                $instances,
            ))));
            $pageIds = array_values(array_unique(array_filter(array_map(
                fn (AuditIssueData $i) => $i->auditPageId,
                $instances,
            ))));

            $result[] = new AuditIssueData(
                severity: $first->severity,
                code: $code,
                message: self::withPageCount($first->message, count($instances)),
                recommendation: $first->recommendation,
                auditPageId: null,
                meta: [
                    'affected_pages' => array_slice($pages, 0, 10),
                    'affected_page_count' => count($pages),
                    'instances' => array_slice(array_map(fn (AuditIssueData $i) => $i->meta, $instances), 0, 10),
                ],
                affectedPageIds: $pageIds,
            );
        }

        return $result;
    }

    private static function withPageCount(string $singlePageMessage, int $count): string
    {
        $base = preg_replace('/:\s*https?:\/\/\S+$/', '', $singlePageMessage);

        return "{$base} ({$count} pages affected)";
    }
}
