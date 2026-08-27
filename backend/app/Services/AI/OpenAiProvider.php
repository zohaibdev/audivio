<?php

namespace App\Services\AI;

use App\Contracts\AiProvider;
use App\DTOs\AiResponse;
use App\Enums\AiProviderName;
use App\Models\ApiProvider;
use App\Services\Provider\ProviderResolver;
use Illuminate\Support\Facades\Http;

class OpenAiProvider implements AiProvider
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    public function name(): string
    {
        return AiProviderName::OpenAi->value;
    }

    public function analyze(string $prompt, array $context = []): AiResponse
    {
        $resolver = new ProviderResolver;
        $provider = $resolver->resolve($this->name());

        $userMessage = $context === []
            ? $prompt
            : $prompt."\n\nContext (JSON):\n".json_encode($context, JSON_PRETTY_PRINT);

        return $this->performRequest($resolver, $provider, $userMessage);
    }

    public function testConnection(): array
    {
        $resolver = new ProviderResolver;

        try {
            $provider = $resolver->resolveConfigured($this->name());
            $response = $this->performRequest($resolver, $provider, 'Respond with the single word: OK.');

            return ['success' => true, 'response_time_ms' => $response->responseTimeMs, 'message' => 'Connected successfully.'];
        } catch (AiProviderException $e) {
            return ['success' => false, 'response_time_ms' => null, 'message' => $e->getMessage()];
        }
    }

    private function performRequest(ProviderResolver $resolver, ApiProvider $provider, string $userMessage): AiResponse
    {
        $payload = [
            'model' => $provider->model,
            'max_completion_tokens' => $provider->getConfigValue('max_tokens', 2048),
            'messages' => [
                ['role' => 'user', 'content' => $userMessage],
            ],
        ];

        if ($provider->getConfigValue('temperature') !== null) {
            $payload['temperature'] = $provider->getConfigValue('temperature');
        }

        $startedAt = microtime(true);

        try {
            $response = Http::withToken($provider->api_key)
                ->timeout($provider->getConfigValue('request_timeout', 60))
                ->post(self::ENDPOINT, $payload);
        } catch (\Throwable $e) {
            $resolver->recordFailure($provider, $e->getMessage());

            throw new AiProviderException("OpenAI request failed: {$e->getMessage()}");
        }

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        if (! $response->successful()) {
            $resolver->recordFailure($provider, "HTTP {$response->status()}: {$response->body()}");

            throw new AiProviderException("OpenAI API error ({$response->status()}): ".$response->body(), $response->status());
        }

        $resolver->recordSuccess($provider);

        return new AiResponse(
            provider: $this->name(),
            content: $response->json('choices.0.message.content', ''),
            raw: $response->json(),
            promptTokens: $response->json('usage.prompt_tokens'),
            completionTokens: $response->json('usage.completion_tokens'),
            responseTimeMs: $elapsedMs,
            httpStatus: $response->status(),
        );
    }
}
