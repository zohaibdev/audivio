<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Anonymous (no account) limits
    |--------------------------------------------------------------------------
    | Registered-user limits live on the `plans` table (DB-configurable,
    | see PlanSeeder) since they vary per plan. Anonymous users have no
    | account to attach a plan to, so their single global limit lives
    | here instead — still configurable via env, never hardcoded in the
    | enforcement logic itself (UsageLimitService).
    |
    | anonymous_audit_limit is a LIFETIME limit per IP address, not daily
    | (per spec: "Anonymous: 1 audit, max 5 pages").
    */
    'anonymous_max_pages' => (int) env('ANONYMOUS_MAX_PAGES', 5),
    'anonymous_audit_limit' => (int) env('ANONYMOUS_AUDIT_LIMIT', 1),
];
