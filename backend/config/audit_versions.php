<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Result-producing component versions
    |--------------------------------------------------------------------------
    | Stamped onto every audit snapshot (§38). A score is only comparable
    | to an older score if the things that produced it have not changed —
    | without these, a change in scoring weights is indistinguishable from
    | a change in the audited site.
    |
    | Bump the relevant one whenever a change would alter the result for an
    | unchanged website:
    |
    | - crawler:  what gets fetched or extracted (crawl.mjs, extract.mjs,
    |             the orchestrator's discovery rules).
    | - scoring:  config/audit_scoring.php weights/severities, or
    |             ScoreCalculator's maths.
    | - prompt:   the AI prompt templates, which change what the models say
    |             about identical input.
    */
    'crawler' => env('AUDIT_CRAWLER_VERSION', '1.0.0'),
    'scoring' => env('AUDIT_SCORING_VERSION', '1.0.0'),
    'prompt' => env('AUDIT_PROMPT_VERSION', '1.0.0'),
];
