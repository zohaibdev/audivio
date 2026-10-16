<?php

namespace App\Services\Report;

use App\Services\Plan\Entitlements;

/**
 * Applies the restricted-vs-full report split at read time, never at
 * generation time — a Report row always stores the full content, so an
 * audit owner who later gets full access sees the full version of the
 * SAME report without regenerating it.
 *
 * Restricted (per spec): overall score, category scores, "major issues"
 * (critical/high severity only, capped), "selected recommendations"
 * (capped). Full: everything, including all issues at every severity, all
 * recommendations, per-page findings, and AI analysis text.
 *
 * **How much** survives the split is now the viewer's entitlement (§40),
 * not a fixed config number: `report_visibility_percent` scales the
 * findings a plan shows, and `issues_per_service` caps them. The *shape*
 * of the split is still field-based — a percentage never reveals AI
 * analysis or page-level findings, however high it is set, short of 100.
 */
class ReportVisibilityFilter
{
    private const MAJOR_SEVERITIES = ['critical', 'high'];

    /**
     * Apply $entitlements to a stored report.
     *
     * **Ownership decides whose entitlements apply; the entitlements decide
     * how much.** The caller (AuditController) resolves that: an owner is
     * filtered by their own plan, and anyone else by the guest tier, so a
     * viewer's own generous plan can never unlock a report that is not
     * theirs. Passing the *viewer's* entitlements unconditionally was
     * exactly that hole — a non-owner on a 100%-visibility plan read
     * someone else's full report.
     *
     * @param  array<string, mixed>  $content  the full report from ReportBuilder::build()
     * @return array<string, mixed>
     */
    public function filter(array $content, Entitlements $entitlements): array
    {
        if ($entitlements->hasFullReportAccess()) {
            return $content + ['is_full_access' => true];
        }

        $majorIssues = collect($content['issues'])
            ->filter(fn ($issue) => in_array($issue['severity'], self::MAJOR_SEVERITIES, true))
            ->values();

        $visibleIssues = $majorIssues
            ->take($this->allowance($entitlements, $majorIssues->count()))
            ->map(fn ($issue) => collect($issue)->except('priority')->all())
            ->values()
            ->all();

        $allRecommendations = collect($content['recommendations']);

        $visibleRecommendations = $allRecommendations
            ->take($this->allowance($entitlements, $allRecommendations->count()))
            ->map(fn ($rec) => collect($rec)->except('priority')->all())
            ->values()
            ->all();

        return [
            'is_full_access' => false,
            'visibility_percent' => $entitlements->reportVisibilityPercent,
            'overview' => $content['overview'],
            // Kept at every tier: the restricted view still shows the
            // accessibility score, and a score without its caveat is the
            // one thing this report must never hand out (§43).
            'disclaimers' => $content['disclaimers'] ?? [],
            'issues' => $visibleIssues,
            'recommendations' => $visibleRecommendations,
            // Counted against the *whole* report, not against the
            // major-severity subset, so "12 more issues" is honest about
            // everything being withheld rather than only the part that was
            // eligible for the restricted view.
            'hidden_issue_count' => max(0, count($content['issues']) - count($visibleIssues)),
            'hidden_recommendation_count' => max(0, count($content['recommendations']) - count($visibleRecommendations)),
        ];
    }

    /**
     * How many of $total items this viewer sees: the plan's percentage,
     * then its per-service cap, whichever is smaller.
     */
    private function allowance(Entitlements $entitlements, int $total): int
    {
        $allowed = $entitlements->visibleCount($total);

        if ($entitlements->issuesPerService !== null) {
            $allowed = min($allowed, $entitlements->issuesPerService);
        }

        return $allowed;
    }
}
