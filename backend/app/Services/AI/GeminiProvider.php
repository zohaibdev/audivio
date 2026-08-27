<?php

namespace App\Services\AI;

use App\Contracts\AiProvider;
use App\DTOs\AiResponse;
use App\Enums\AiProviderName;
use Illuminate\Support\Facades\Http;

class GeminiProvider implements AiProvider
{
    public function name(): string
    {
        return AiProviderName::Gemini->value;
    }

    public function analyze(string $prompt, array $context = []): AiResponse
    {
        $config = config('ai.providers.gemini');

        if (empty($config['api_key'])) {
            throw new AiProviderException('Gemini API key is not configured (GEMINI_API_KEY).');
        }

        $userMessage = $context === []
            ? $prompt
            : $prompt."\n\nContext (JSON):\n".json_encode($context, JSON_PRETTY_PRINT);

        $url = "{$config['endpoint']}/{$config['model']}:generateContent";

        $response = Http::timeout(config('ai.request_timeout'))
            ->post($url.'?key='.$config['api_key'], [
                'contents' => [
                    ['parts' => [['text' => $userMessage]]],
                ],
                'generationConfig' => [
                    'maxOutputTokens' => $config['max_tokens'],
                ],
            ]);

        if (! $response->successful()) {
            throw new AiProviderException("Gemini API error ({$response->status()}): ".$response->body());
        }

        $text = collect($response->json('candidates.0.content.parts', []))
            ->pluck('text')
            ->implode('');

        return new AiResponse(
            provider: $this->name(),
            content: $text,
            raw: $response->json(),
            promptTokens: $response->json('usageMetadata.promptTokenCount'),
            completionTokens: $response->json('usageMetadata.candidatesTokenCount'),
        );
    }
}
