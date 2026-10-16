<?php

namespace App\Services\Report;

use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\AuditPage;
use Illuminate\Support\Collection;

/**
 * The cross-service page view: every crawled page with its issue counts
 * broken down by service and severity, plus both screenshots.
 *
 * Distinct from ServiceReportBuilder's per-service `pages` list, which is
 * scoped to one service's findings. This one answers the other direction —
 * "what is wrong with *this page*, across everything we checked" — which
 * is what the page explorer and page-detail views need.
 */
class PageExplorerBuilder
{
    public function __construct(private readonly IssueTaxonomy $taxonomy) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function build(Audit $audit): array
    {
        $audit->loadMissing(['pages.screenshots', 'pages.issues', 'pages.multiPageIssues']);

        $rows = $audit->pages->map(function (AuditPage $page) {
            $issues = $page->allIssues();

            return [
                'url' => $page->url,
                'title' => $page->title,
                'http_status' => $page->http_status,
                'load_time_ms' => $page->load_time_ms,
                'issue_count' => $issues->count(),
                // A page-level score (§47), derived from the issues found
                // on this page using the same severity weights the
                // category scores use. Deliberately *not* a Lighthouse
                // score and never presented as one: it answers "how much
                // is wrong with this page relative to the others", which
                // is the question the explorer exists to sort by.
                'score' => $this->pageScore($issues),
                'severity_summary' => $this->severitySummary($issues),
                'issues_by_service' => $this->countByService($issues),
                'screenshots' => $this->screenshotsFor($page),
            ];
        })->all();

        usort($rows, function (array $a, array $b) {
            // Worst pages first: most critical, then most high, then most
            // issues overall — so the explorer opens on what matters.
            return [$b['severity_summary']['critical'], $b['severity_summary']['high'], $b['issue_count']]
                <=> [$a['severity_summary']['critical'], $a['severity_summary']['high'], $a['issue_count']];
        });

        return $rows;
    }

    /**
     * 100 minus the weighted cost of this page's issues, floored at 0.
     *
     * Uses `audit_scoring.severity_points`, the same weights every
     * category score uses, so a page scoring 60 here means the same kind
     * of thing as a category scoring 60. A site-wide issue counts against
     * every page it affects, because it is wrong on every one of them.
     *
     * @param  Collection<int, AuditIssue>  $issues
     */
    private function pageScore($issues): int
    {
        $points = config('audit_scoring.severity_points');

        $deduction = $issues->sum(fn (AuditIssue $issue) => $points[$issue->severity->value] ?? 0);

        return max(0, 100 - (int) $deduction);
    }

    /**
     * @param  Collection<int, AuditIssue>  $issues
     * @return array<string, int>
     */
    private function severitySummary($issues): array
    {
        return [
            'critical' => $issues->where('severity.value', 'critical')->count(),
            'high' => $issues->where('severity.value', 'high')->count(),
            'medium' => $issues->where('severity.value', 'medium')->count(),
            'low' => $issues->where('severity.value', 'low')->count(),
        ];
    }

    /**
     * @param  Collection<int, AuditIssue>  $issues
     * @return array<string, int>
     */
    private function countByService($issues): array
    {
        return $issues
            ->groupBy(fn (AuditIssue $i) => $this->taxonomy->serviceFor($i->category) ?? 'other')
            ->map(fn ($group) => $group->count())
            ->all();
    }

    /**
     * @return array<string, string|null>
     */
    private function screenshotsFor(AuditPage $page): array
    {
        // Signed and short-lived (§48). Emitting the raw storage path
        // here is what made screenshots walkable: the path is derived
        // from a sequential audit id, so anyone could guess their way
        // into other people's captures.
        return app(ScreenshotUrlSigner::class)->urlsFor($page->screenshots);
    }
}
