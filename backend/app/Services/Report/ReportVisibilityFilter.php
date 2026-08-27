<?php

namespace App\Services\Report;

/**
 * Applies the free-vs-full report split at read time, never at
 * generation time — a Report row always stores the full content, so an
 * audit owner who later gets full access sees the full version of the
 * SAME report without regenerating it.
 *
 * Free/anonymous (per spec): overall score, category scores, "major
 * issues" (critical/high severity only, capped), "selected
 * recommendations" (capped). Full: everything, including all issues at
 * every severity, all recommendations, per-page findings, and AI
 * analysis text.
 */
class ReportVisibilityFilter
{
    private const MAJOR_SEVERITIES = ['critical', 'high'];

    /**
     * @param  array<string, mixed>  $content  The full report content from ReportBuilder::build()
     * @return array<string, mixed>
     */
    public function filter(array $content, bool $hasFullAccess): array
    {
        if ($hasFullAccess) {
            return $content + ['is_full_access' => true];
        }

        $majorIssues = collect($content['issues'])
            ->filter(fn ($issue) => in_array($issue['severity'], self::MAJOR_SEVERITIES, true));

        $visibleIssues = $majorIssues->take(config('report.anonymous_issue_limit'))
            ->map(fn ($issue) => collect($issue)->except('priority')->all())
            ->values()
            ->all();

        $visibleRecommendations = collect($content['recommendations'])
            ->take(config('report.anonymous_recommendation_limit'))
            ->map(fn ($rec) => collect($rec)->except('priority')->all())
            ->values()
            ->all();

        return [
            'is_full_access' => false,
            'overview' => $content['overview'],
            'issues' => $visibleIssues,
            'recommendations' => $visibleRecommendations,
            'hidden_issue_count' => max(0, count($content['issues']) - count($visibleIssues)),
            'hidden_recommendation_count' => max(0, count($content['recommendations']) - count($visibleRecommendations)),
        ];
    }
}
