<?php

namespace App\Services\Report;

/**
 * The free-vs-full split for the per-service reports (§35), mirroring
 * ReportVisibilityFilter's rules for the legacy combined report so the two
 * views can't disagree about what an anonymous visitor is entitled to.
 *
 * Free/anonymous keeps what proves the audit is real — every service's
 * score, status and severity counts — but withholds the detail that makes
 * it actionable: full issue lists, per-page findings, screenshots, and AI
 * analysis text. Counts of what's hidden are always returned, so the UI
 * can say "12 more issues" honestly rather than pretending nothing else
 * was found.
 */
class ServiceReportVisibilityFilter
{
    private const MAJOR_SEVERITIES = ['critical', 'high'];

    /**
     * @param  array<string, array<string, mixed>>  $reports  keyed by service
     * @return array<string, array<string, mixed>>
     */
    public function filterAll(array $reports, bool $hasFullAccess): array
    {
        return collect($reports)
            ->map(fn (array $report) => $this->filter($report, $hasFullAccess))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public function filter(array $report, bool $hasFullAccess): array
    {
        if ($hasFullAccess) {
            return $report + ['is_full_access' => true];
        }

        $allIssues = collect($report['issues']);

        $visibleIssues = $allIssues
            ->filter(fn (array $i) => in_array($i['severity'], self::MAJOR_SEVERITIES, true))
            ->take(config('report.anonymous_issue_limit'))
            ->map(fn (array $i) => $this->redactIssue($i))
            ->values()
            ->all();

        $visibleRecommendations = collect($report['recommendations'])
            ->take(config('report.anonymous_recommendation_limit'))
            ->map(fn (array $r) => collect($r)->only(['title', 'category', 'severity', 'problem', 'recommended_action'])->all())
            ->values()
            ->all();

        return [
            'is_full_access' => false,
            'service' => $report['service'],
            'name' => $report['name'],
            'score' => $report['score'],
            'is_applicable' => $report['is_applicable'],
            'not_applicable_reason' => $report['not_applicable_reason'],
            // Confidence and coverage are shown to everyone: withholding
            // that a score is incomplete would misrepresent the audit,
            // which is not the same thing as withholding paid detail.
            'confidence' => $report['confidence'],
            'coverage_note' => $report['coverage_note'],
            'status' => $report['status'],
            'what_this_means' => $report['what_this_means'],
            'severity_summary' => $report['severity_summary'],
            // Category *names and counts* stay visible — they show the
            // shape of what was found without giving away the findings.
            'categories' => collect($report['categories'])
                ->map(fn (array $c) => collect($c)->except('issues')->all())
                ->all(),
            'issues' => $visibleIssues,
            'recommendations' => $visibleRecommendations,
            'pages' => [],
            'ai' => ['used' => $report['ai']['used'], 'available' => $report['ai']['available'], 'analysis' => null, 'error' => null],
            // Headline scores and Core Web Vitals stay visible (they are
            // the proof the audit measured something real), but the
            // per-page metric table and resource breakdown are full-access
            // detail, same as page findings above.
            'performance' => isset($report['performance']) && $report['performance'] !== null
                ? [
                    'mobile' => $report['performance']['mobile'],
                    'desktop' => $report['performance']['desktop'],
                    'resources' => ['available' => false, 'measured_pages' => 0, 'by_type' => [], 'totals' => []],
                    'pages' => [],
                ]
                : null,
            'hidden_issue_count' => max(0, $allIssues->count() - count($visibleIssues)),
            'hidden_recommendation_count' => max(0, count($report['recommendations']) - count($visibleRecommendations)),
            'hidden_page_count' => count($report['pages']),
        ];
    }

    /**
     * Strips the parts of an issue that constitute the paid detail —
     * exact evidence, the full affected-URL list, and the fix itself —
     * while keeping enough for the visitor to see the finding is real.
     *
     * @param  array<string, mixed>  $issue
     * @return array<string, mixed>
     */
    private function redactIssue(array $issue): array
    {
        return [
            'code' => $issue['code'],
            'category' => $issue['category'],
            'severity' => $issue['severity'],
            'title' => $issue['title'],
            'message' => $issue['message'],
            'affected_page_count' => $issue['affected_page_count'],
            'is_site_wide' => $issue['is_site_wide'],
            'detected_by' => $issue['detected_by'],
        ];
    }
}
