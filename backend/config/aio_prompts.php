<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default AIO prompts
    |--------------------------------------------------------------------------
    | The ten checks every AIO audit runs (§42). Config rather than a table
    | because these are not user data — they are part of what the product
    | *is*, they change with the code that parses their output, and
    | `audit_versions.prompt` stamps which set produced a given result.
    |
    | Each entry is one focused question. Ten narrow prompts beat one broad
    | prompt because the model answers a specific question well and a
    | sprawling one vaguely — and because a failure isolates to one check
    | instead of losing the whole category.
    |
    | `needs_context` marks prompts that are only meaningful once the
    | business profile is filled in. They are skipped, not asked blindly,
    | when onboarding is incomplete: asking "does this page serve the
    | stated goal" with no stated goal invites the model to invent one.
    */
    'defaults' => [
        [
            'key' => 'heading_clarity',
            'question' => 'Do the headings state clear, self-contained topics that a machine reader could use to identify what each section is about, without relying on surrounding context?',
            'needs_context' => false,
        ],
        [
            'key' => 'structured_data',
            'question' => 'Does the structured data present unambiguously identify the entities, facts and page types on each page? Note specific missing or mismatched schema types.',
            'needs_context' => false,
        ],
        [
            'key' => 'title_and_summary',
            'question' => 'Do page titles and meta descriptions accurately and specifically summarise what the page contains, or are they generic, duplicated, or misleading?',
            'needs_context' => false,
        ],
        [
            'key' => 'extractable_sections',
            'question' => 'Is the content organised into clearly delineated, self-contained sections that an extractor could quote independently without losing meaning?',
            'needs_context' => false,
        ],
        [
            'key' => 'question_answering',
            'question' => 'Does the content directly answer the questions a reader would actually ask, in a form that could be quoted as an answer, rather than burying answers in marketing prose?',
            'needs_context' => false,
        ],
        [
            'key' => 'entity_consistency',
            'question' => 'Are the organisation, product and service names used consistently across pages, so a machine reader resolves them to one entity rather than several?',
            'needs_context' => false,
        ],
        [
            'key' => 'factual_specificity',
            'question' => 'Does the content state concrete, checkable facts (what is offered, to whom, where, on what terms), or does it rely on unquantified claims that cannot be extracted as facts?',
            'needs_context' => false,
        ],
        [
            'key' => 'goal_alignment',
            'question' => 'Given what this business says it wants to be found for, does the content actually make that subject explicit and prominent, or is it implied rather than stated?',
            'needs_context' => true,
        ],
        [
            'key' => 'offering_coverage',
            'question' => 'Given the products or services this business says it offers, are each of them described on the site in a way a machine reader could identify and distinguish?',
            'needs_context' => true,
        ],
        [
            'key' => 'audience_and_locale',
            'question' => 'Given the stated business category and country, is it explicit on the site who the offering is for and where it is available, or must a reader infer it?',
            'needs_context' => true,
        ],
    ],
];
