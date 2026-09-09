<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default provider
    |--------------------------------------------------------------------------
    | Which AiProvider AiProviderFactory resolves when a module doesn't ask
    | for a specific one. One of: claude, openai, gemini. Per-provider
    | credentials/model/limits are DB-backed (App\Models\ApiProvider,
    | admin-editable) — this is just which one is the default, an
    | application-architecture choice, not provider configuration.
    */
    'default' => env('AI_DEFAULT_PROVIDER', 'claude'),

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    | AI responses are cached per (audit, category, context hash) in the
    | ai_analyses table — re-running analysis on an audit that hasn't
    | changed must not re-spend tokens.
    */
    'cache_analyses' => env('AI_CACHE_ANALYSES', true),

    /*
    |--------------------------------------------------------------------------
    | Task complexity per audit category
    |--------------------------------------------------------------------------
    | Which tier of model each category's analysis actually needs (§53),
    | so a simple extraction is not billed at a flagship model's rate. A
    | category listed here is served by the cheapest enabled model whose
    | `ai_models.tier` meets the requirement; anything not listed falls
    | back to `default_complexity`.
    |
    | These are judgements about the *work*, not about any one provider's
    | line-up, which is why they live here and the tiers live on the
    | models an admin configures.
    */
    'task_complexity' => [
        // Reads the page's own structured signals and reports what is
        // present or missing — closer to extraction than to judgement.
        'accessibility' => 'simple',
        'seo' => 'simple',

        // Weighs several interacting signals and explains the result.
        'conversion' => 'standard',
        'ecommerce' => 'standard',
        'landing_page' => 'standard',
        'performance' => 'standard',

        // Open-ended reasoning: how a site reads to an answer engine, and
        // how its products compare. Downgrading these to save tokens is
        // where the saving turns into a worse answer.
        'aio' => 'complex',
        'product_auditor' => 'complex',
    ],

    'default_complexity' => env('AI_DEFAULT_COMPLEXITY', 'standard'),
];
