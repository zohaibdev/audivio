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
];
