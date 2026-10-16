<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Stuck-audit recovery
    |--------------------------------------------------------------------------
    | An audit that has made no progress for this long is treated as
    | abandoned by `audits:recover-stuck` and failed, releasing its
    | concurrent slot. Must stay comfortably above `crawler.crawl_timeout`
    | (the longest a healthy crawl may legitimately take) or recovery will
    | kill audits that are merely slow.
    |
    | The anonymous page/audit/active limits that used to live here moved
    | to `config/entitlements.php` (§40). They belonged with the rest of the
    | guest tier, not in a file about the crawler's own safety valves — and
    | keeping the guest's active-audit limit in two places was already
    | drifting: one key fed EntitlementResolver and the other fed nothing.
    */
    'stuck_after_minutes' => (int) env('AUDIT_STUCK_AFTER_MINUTES', 30),
];
