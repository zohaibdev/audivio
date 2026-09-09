<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Sales recipients
    |--------------------------------------------------------------------------
    | Who gets told when a consultation request comes in (§55). Comma-
    | separated addresses. Empty means nobody is notified — the lead is
    | still recorded and still appears in the admin leads screen, so an
    | unconfigured mailer delays the follow-up rather than losing it.
    */
    'sales_recipients' => array_filter(
        explode(',', (string) env('LEADS_SALES_RECIPIENTS', ''))
    ),

    /*
    |--------------------------------------------------------------------------
    | Where consultation requests can come from
    |--------------------------------------------------------------------------
    | The CTA appears in several places in a report (§55) and the source
    | says which one, so it is possible to see which placement actually
    | earns requests rather than guessing.
    */
    'sources' => [
        'talk_to_consultant',
        'request_audit_review',
        'audit_header',
        'score_summary',
        'critical_issues',
        'report_footer',
    ],
];
