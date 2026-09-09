<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Disclaimer
    |--------------------------------------------------------------------------
    | Attached to every accessibility result the product emits — the API,
    | the report, the PDF, the snapshot (§43).
    |
    | This is not boilerplate to be trimmed. An automated scan can only
    | detect a subset of WCAG failures: it cannot judge whether alt text is
    | *accurate*, whether a heading describes what follows, whether a focus
    | order makes sense, or whether a page is usable with a screen reader
    | in practice. A clean automated result is therefore not evidence of
    | conformance, and this product must never be quotable as saying it is.
    |
    | The wording deliberately avoids "compliant", "compliance", "certified"
    | and any reference to legal standing under the ADA or equivalent law.
    | It states what was done, what it cannot cover, and what a real answer
    | would require.
    */
    'disclaimer' => [
        'title' => 'Automated accessibility assessment',

        'summary' => 'This is an automated accessibility assessment, not a legal compliance audit or certification.',

        'detail' => 'Automated testing detects a subset of accessibility problems — typically those with a machine-checkable rule, such as a missing label or insufficient colour contrast. It cannot judge whether alt text accurately describes an image, whether a heading describes the content beneath it, whether the focus order makes sense to someone navigating by keyboard, or whether the page can actually be completed with a screen reader. Passing every automated check is therefore not evidence of conformance with WCAG, the ADA, or any other standard or law. A conclusive assessment requires manual testing and testing with assistive technology by people who use it.',

        // Shown next to the score itself, where a number invites being
        // read as a grade.
        'score_note' => 'This score reflects the automated checks below only. It is not a conformance rating.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Evidence limits
    |--------------------------------------------------------------------------
    | How many failing elements to record per issue. The crawler already
    | caps what it collects per page (crawl.mjs); this caps what survives
    | aggregation across pages, so a contrast failure on 25 pages does not
    | store 200 near-identical snippets.
    */
    'max_elements_per_issue' => (int) env('A11Y_MAX_ELEMENTS_PER_ISSUE', 10),
    'max_pages_listed_per_issue' => (int) env('A11Y_MAX_PAGES_PER_ISSUE', 10),
];
