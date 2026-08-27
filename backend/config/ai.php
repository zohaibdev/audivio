<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default provider
    |--------------------------------------------------------------------------
    | Which AiProvider AiProviderFactory resolves when a module doesn't ask
    | for a specific one. One of: claude, openai, gemini.
    */
    'default' => env('AI_DEFAULT_PROVIDER', 'claude'),

    'request_timeout' => (int) env('AI_REQUEST_TIMEOUT', 60),

    'providers' => [
        'claude' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),
            'endpoint' => 'https://api.anthropic.com/v1/messages',
            'max_tokens' => 2048,
        ],
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-5'),
            'endpoint' => 'https://api.openai.com/v1/chat/completions',
            'max_tokens' => 2048,
        ],
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-3-pro'),
            'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models',
            'max_tokens' => 2048,
        ],
    ],

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
