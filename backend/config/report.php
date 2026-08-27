<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Visibility
    |--------------------------------------------------------------------------
    | The stored report content is always the FULL report — visibility is
    | applied at read time (ReportVisibilityFilter), not baked into what's
    | generated, so an audit owner who later gets full access sees the
    | full version of the same report without regenerating it.
    |
    | The spec's free-vs-full split is field-based (free: overall score,
    | category scores, "major issues", "selected recommendations" — full:
    | everything, including page-level findings and AI analysis), not a
    | flat percentage — "~60% of report visible" describes the practical
    | effect of that split, not a formula. anonymous_issue_limit and
    | anonymous_recommendation_limit cap how many of the free-eligible
    | (major-severity) issues and recommendations an anonymous viewer sees.
    */
    'anonymous_issue_limit' => (int) env('REPORT_ANONYMOUS_ISSUE_LIMIT', 5),
    'anonymous_recommendation_limit' => (int) env('REPORT_ANONYMOUS_RECOMMENDATION_LIMIT', 3),
];
