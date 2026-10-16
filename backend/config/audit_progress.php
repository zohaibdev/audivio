<?php

/*
|--------------------------------------------------------------------------
| Audit progress reporting (§51)
|--------------------------------------------------------------------------
| The estimate is measured from each audit's own crawl rate, not from
| these values — see AuditProgressCalculator. What is configurable here is
| the one part that cannot be measured mid-run.
*/

return [
    /*
    | A rough per-service cost for the analysis phase, used only to
    | project the tail after the crawl finishes. Analysis is not
    | per-page — it is a roughly fixed cost per module — so it cannot be
    | derived from the page rate the way the crawl can.
    |
    | Deliberately a single conservative number rather than a per-module
    | table: modules that call an AI provider vary far more with the
    | provider's latency than with anything we could tabulate, and a
    | table would look more precise than it is.
    */
    'seconds_per_service' => (int) env('AUDIT_PROGRESS_SECONDS_PER_SERVICE', 8),

    /*
    | Above this page count, the report tells the user plainly that a
    | larger site takes longer, rather than leaving them to infer it from
    | a bar that is not moving.
    */
    'large_site_pages' => (int) env('AUDIT_PROGRESS_LARGE_SITE_PAGES', 10),
];
