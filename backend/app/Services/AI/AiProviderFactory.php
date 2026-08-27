<?php

namespace App\Services\AI;

use App\Contracts\AiProvider;
use App\Enums\AiProviderName;

class AiProviderFactory
{
    public function make(?string $provider = null): AiProvider
    {
        $name = AiProviderName::from($provider ?? config('ai.default'));

        return match ($name) {
            AiProviderName::Claude => new ClaudeProvider,
            AiProviderName::OpenAi => new OpenAiProvider,
            AiProviderName::Gemini => new GeminiProvider,
        };
    }
}
