<?php

namespace App\Services\AI;

use App\Contracts\AiProvider;
use App\DTOs\AiResponse;
use App\Enums\AiProviderName;
use Illuminate\Support\Facades\Http;

class OpenAiProvider implements AiProvider
{
    public function name(): string
    {
        return AiProviderName::OpenAi->value;
    }

    public function analyze(string $prompt, array $context = []): AiResponse
    {
        $config = config('ai.providers.openai');

        if (empty($config['api_key'])) {
            throw new AiProviderException('OpenAI API key is not configured (OPENAI_API_KEY).');
        }

        $userMessage = $context === []
            ? $prompt
            : $prompt."\n\nContext (JSON):\n".json_encode($context, JSON_PRETTY_PRINT);

        $response = Http::withToken($config['api_key'])
            ->timeout(config('ai.request_timeout'))
            ->post($config['endpoint'], [
                'model' => $config['model'],
                'max_completion_tokens' => $config['max_tokens'],
                'messages' => [
                    ['role' => 'user', 'content' => $userMessage],
                ],
            ]);

        if (! $response->successful()) {
            throw new AiProviderException("OpenAI API error ({$response->status()}): ".$response->body());
        }

        return new AiResponse(
            provider: $this->name(),
            content: $response->json('choices.0.message.content', ''),
            raw: $response->json(),
            promptTokens: $response->json('usage.prompt_tokens'),
            completionTokens: $response->json('usage.completion_tokens'),
        );
    }
}
