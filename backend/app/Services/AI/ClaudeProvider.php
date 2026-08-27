<?php

namespace App\Services\AI;

use App\Contracts\AiProvider;
use App\DTOs\AiResponse;
use App\Enums\AiProviderName;
use Illuminate\Support\Facades\Http;

class ClaudeProvider implements AiProvider
{
    public function name(): string
    {
        return AiProviderName::Claude->value;
    }

    public function analyze(string $prompt, array $context = []): AiResponse
    {
        $config = config('ai.providers.claude');

        if (empty($config['api_key'])) {
            throw new AiProviderException('Claude API key is not configured (ANTHROPIC_API_KEY).');
        }

        $userMessage = $context === []
            ? $prompt
            : $prompt."\n\nContext (JSON):\n".json_encode($context, JSON_PRETTY_PRINT);

        $response = Http::withHeaders([
            'x-api-key' => $config['api_key'],
            'anthropic-version' => '2023-06-01',
        ])
            ->timeout(config('ai.request_timeout'))
            ->post($config['endpoint'], [
                'model' => $config['model'],
                'max_tokens' => $config['max_tokens'],
                'messages' => [
                    ['role' => 'user', 'content' => $userMessage],
                ],
            ]);

        if (! $response->successful()) {
            throw new AiProviderException("Claude API error ({$response->status()}): ".$response->body());
        }

        $text = collect($response->json('content', []))
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');

        return new AiResponse(
            provider: $this->name(),
            content: $text,
            raw: $response->json(),
            promptTokens: $response->json('usage.input_tokens'),
            completionTokens: $response->json('usage.output_tokens'),
        );
    }
}
