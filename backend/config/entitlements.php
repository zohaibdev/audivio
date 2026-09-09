<?php

use App\Enums\PlanFeature;

return [
    /*
    |--------------------------------------------------------------------------
    | Guest (anonymous) entitlements
    |--------------------------------------------------------------------------
    | A guest has no account, so no `plans` row to read limits from. This is
    | that missing row — the same shape a plan resolves to, so
    | EntitlementResolver returns one type whether or not anyone is logged
    | in and no gate has to special-case "anonymous".
    |
    | Deliberately not merged into `plans` as a "guest plan": a plans row is
    | admin-editable and appears in pricing listings, and neither is right
    | for the anonymous tier.
    |
    | `max_websites` is 0 because guests have no website listing to cap.
    | Anonymous audits still create a `websites` row for bookkeeping (§37);
    | that row is invisible and is not counted against anything.
    */
    'guest' => [
        'max_websites' => 0,
        'max_pages' => (int) env('ANONYMOUS_MAX_PAGES', 5),
        'daily_audit_limit' => (int) env('ANONYMOUS_AUDIT_LIMIT', 1),
        'monthly_audit_limit' => (int) env('ANONYMOUS_AUDIT_LIMIT', 1),
        'concurrent_audit_limit' => (int) env('ANONYMOUS_ACTIVE_LIMIT', 1),
        'max_audit_frequency' => 'monthly',
        'report_visibility_percent' => (int) env('GUEST_REPORT_VISIBILITY_PERCENT', 50),
        'issues_per_service' => (int) env('GUEST_ISSUES_PER_SERVICE', 5),
        'ai_analyses_per_audit' => (int) env('GUEST_AI_ANALYSES_PER_AUDIT', 2),
        'aio_prompt_limit' => (int) env('GUEST_AIO_PROMPT_LIMIT', 1),
        // A guest has no website record to attach a custom prompt to.
        'custom_aio_prompt_limit' => 0,

        // Every on/off entitlement is off for a guest. Listed explicitly
        // rather than defaulted, so adding a PlanFeature case forces a
        // decision here instead of silently granting it.
        'features' => [
            PlanFeature::PdfExport->value => false,
            PlanFeature::ScheduledAudits->value => false,
            PlanFeature::AuditHistory->value => false,
            PlanFeature::ReportSharing->value => false,
            PlanFeature::WhiteLabel->value => false,
            PlanFeature::CustomAioPrompts->value => false,
            PlanFeature::CompetitorDiscovery->value => false,
        ],
    ],
];
