<?php

namespace App\Services\Share;

use App\Models\ReportShare;

/**
 * Applies a share's permissions on top of an already-built report (§50).
 *
 * Deliberately the *second* filter, never the only one. The order is:
 *
 *   build → the owner's plan visibility (§40/§48) → the share's permissions
 *
 * A share can only ever narrow what the owner themselves can see. Running
 * share permissions alone would let a link expose more of a report than
 * the account that created it is entitled to — the recipient would be
 * reading past a plan limit through a URL the owner pasted into an email.
 */
class SharePermissionFilter
{
    /**
     * @param  array<string, array<string, mixed>>  $services  keyed by service
     * @return array<string, array<string, mixed>>
     */
    public function filterServices(array $services, ReportShare $share): array
    {
        return collect($services)
            ->map(fn (array $report) => $this->filterService($report, $share))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function filterService(array $report, ReportShare $share): array
    {
        if (! $share->allow_recommendations) {
            // Emptied rather than removed: consumers read the key, and a
            // missing one crashes them while an empty one renders as
            // "nothing here". The count stays so the page can say the
            // recommendations exist but are not included in this share.
            $report['withheld_recommendation_count'] = count($report['recommendations'] ?? []);
            $report['recommendations'] = [];
        }

        if (! $share->allow_screenshots) {
            $report['pages'] = $this->stripScreenshots($report['pages'] ?? []);

            if (isset($report['performance']['pages'])) {
                $report['performance']['pages'] = $this->stripScreenshots($report['performance']['pages']);
            }
        }

        return $report;
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @return array<int, array<string, mixed>>
     */
    private function stripScreenshots(array $pages): array
    {
        return array_map(function (array $page) {
            if (array_key_exists('screenshots', $page)) {
                // Nulled, not removed, for the same reason as above — and
                // because a null reads as "not available", which is
                // exactly what it is for this viewer.
                $page['screenshots'] = ['desktop' => null, 'mobile' => null];
            }

            return $page;
        }, $pages);
    }

    /**
     * The page-explorer rows, which carry screenshots of their own.
     *
     * @param  array<int, array<string, mixed>>  $pages
     * @return array<int, array<string, mixed>>
     */
    public function filterExplorerPages(array $pages, ReportShare $share): array
    {
        return $share->allow_screenshots ? $pages : $this->stripScreenshots($pages);
    }
}
