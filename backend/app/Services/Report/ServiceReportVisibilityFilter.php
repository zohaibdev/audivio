<?php

namespace App\Services\Report;

use App\Services\Plan\Entitlements;

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
    public function filterAll(array $reports, Entitlements $entitlements): array
    {
        return collect($reports)
            ->map(fn (array $report) => $this->filter($report, $entitlements))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public function filter(array $report, Entitlements $entitlements): array
    {
        // Ownership decides *whose* entitlements arrive here; they decide
        // how much survives. See ReportVisibilityFilter for why the two
        // must not be combined into one "or".
        if ($entitlements->hasFullReportAccess()) {
            return $report + ['is_full_access' => true];
        }

        $allIssues = collect($report['issues']);

        $majorIssues = $allIssues
            ->filter(fn (array $i) => in_array($i['severity'], self::MAJOR_SEVERITIES, true))
            ->values();

        // `issues_per_service` is named for exactly this view: it caps what
        // one service shows, so a plan set to 5 shows 5 per service rather
        // than 5 across the whole report (section 40).
        $visibleIssues = $majorIssues
            ->take($this->allowance($entitlements, $majorIssues->count()))
            ->map(fn (array $i) => $this->redactIssue($i))
            ->values()
            ->all();

        $allRecommendations = collect($report['recommendations']);

        $visibleRecommendations = $allRecommendations
            ->take($this->allowance($entitlements, $allRecommendations->count()))
            ->map(fn (array $r) => collect($r)->only(['title', 'category', 'severity', 'problem', 'recommended_action'])->all())
            ->values()
            ->all();

        return [
            'is_full_access' => false,
            'visibility_percent' => $entitlements->reportVisibilityPercent,
            'service' => $report['service'],
            'name' => $report['name'],
            'score' => $report['score'],
            'is_applicable' => $report['is_applicable'],
            // Kept at every tier: a service that silently vanishes from a
            // free report reads as one the site passed (§46).
            'not_applicable_reason' => $report['not_applicable_reason'],
            'not_applicable_evidence' => $report['not_applicable_evidence'] ?? [],
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
                    // Scores and Core Web Vitals stay (they are the proof
                    // something real was measured); the opportunity list
                    // and its savings are full-access detail. The savings
                    // *total* stays, so the free view can say how much is
                    // on the table without itemising it.
                    'mobile' => $this->redactStrategy($report['performance']['mobile']),
                    'desktop' => $this->redactStrategy($report['performance']['desktop']),
                    'resources' => ['available' => false, 'measured_pages' => 0, 'by_type' => [], 'totals' => []],
                    'categories' => $report['performance']['categories'] ?? [],
                    'pages' => [],
                ]
                : null,
            // The accessibility summary, coverage and disclaimer are shown
            // at every tier (§43). The per-check detail is paid, but a
            // score presented without the caveat that it is an automated
            // assessment is precisely the misreading the disclaimer
            // exists to prevent — and free viewers, who see the score and
            // little else, are the ones most likely to make it.
            'accessibility' => isset($report['accessibility']) && $report['accessibility'] !== null
                ? [
                    'summary' => $report['accessibility']['summary'],
                    'coverage' => $report['accessibility']['coverage'],
                    // Area names and counts only — same rule as
                    // `categories` above: the shape of what was found,
                    // without the findings.
                    'checks' => collect($report['accessibility']['checks'])
                        ->map(fn (array $c) => collect($c)->only(['check', 'label', 'issue_count', 'element_count', 'passed'])->all())
                        ->all(),
                    'wcag_criteria' => [],
                    'disclaimer' => $report['accessibility']['disclaimer'],
                ]
                : null,
            'hidden_issue_count' => max(0, $allIssues->count() - count($visibleIssues)),
            'hidden_recommendation_count' => max(0, count($report['recommendations']) - count($visibleRecommendations)),
            'hidden_page_count' => count($report['pages']),
        ];
    }

    /**
     * One strategy block with the itemised findings withheld.
     *
     * The counts and the savings total stay: withholding those would make
     * the free report understate the problem, which is a different thing
     * from withholding paid detail. The `field` block also stays intact —
     * it explains why INP is absent, and a reader who cannot see that
     * explanation would read the gap as "this site has no INP problem".
     *
     * @param  array<string, mixed>  $strategy
     * @return array<string, mixed>
     */
    private function redactStrategy(array $strategy): array
    {
        if (($strategy['available'] ?? false) !== true) {
            return $strategy;
        }

        return array_merge($strategy, [
            'opportunity_count' => count($strategy['opportunities'] ?? []),
            'diagnostic_count' => count($strategy['diagnostics'] ?? []),
            'opportunities' => [],
            'diagnostics' => [],
        ]);
    }

    /**
     * How many of $total items this viewer sees: the plan's visibility
     * percentage, then its per-service cap, whichever is smaller.
     */
    private function allowance(Entitlements $entitlements, int $total): int
    {
        $allowed = $entitlements->visibleCount($total);

        if ($entitlements->issuesPerService !== null) {
            $allowed = min($allowed, $entitlements->issuesPerService);
        }

        return $allowed;
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
            // Kept: this is the *reason*, not the fix. A free viewer who
            // can see a finding but not why it matters has been given a
            // complaint rather than an explanation (§47).
            'why_it_matters' => $issue['why_it_matters'] ?? null,
            'affected_page_count' => $issue['affected_page_count'],
            'is_site_wide' => $issue['is_site_wide'],
            'detected_by' => $issue['detected_by'],
        ];
    }
}
