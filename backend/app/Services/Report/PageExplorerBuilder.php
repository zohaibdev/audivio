<?php

namespace App\Services\Report;

use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\AuditPage;
use App\Models\Screenshot;
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
        $byViewport = $page->screenshots->keyBy(fn (Screenshot $s) => $s->viewport->value);

        return [
            'desktop' => $byViewport->get('desktop')?->storage_path,
            'mobile' => $byViewport->get('mobile')?->storage_path,
        ];
    }
}
