<?php

namespace App\Services\AI;

use App\Contracts\AiProvider;
use App\DTOs\AiResponse;
use App\Enums\AiProviderName;
use App\Models\ApiProvider;
use App\Services\Provider\ProviderResolver;
use Illuminate\Support\Facades\Http;

class ClaudeProvider implements AiProvider
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    public function name(): string
    {
        return AiProviderName::Claude->value;
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
            'max_tokens' => $provider->getConfigValue('max_tokens', 2048),
            'messages' => [
                ['role' => 'user', 'content' => $userMessage],
            ],
        ];

        if ($provider->getConfigValue('temperature') !== null) {
            $payload['temperature'] = $provider->getConfigValue('temperature');
        }

        $startedAt = microtime(true);

        try {
            $response = Http::withHeaders([
                'x-api-key' => $provider->api_key,
                'anthropic-version' => '2023-06-01',
            ])
                ->timeout($provider->getConfigValue('request_timeout', 60))
                ->post(self::ENDPOINT, $payload);
        } catch (\Throwable $e) {
            $resolver->recordFailure($provider, $e->getMessage());

            throw new AiProviderException("Claude request failed: {$e->getMessage()}");
        }

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        if (! $response->successful()) {
            $resolver->recordFailure($provider, "HTTP {$response->status()}: {$response->body()}");

            throw new AiProviderException("Claude API error ({$response->status()}): ".$response->body(), $response->status());
        }

        $resolver->recordSuccess($provider);

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
            responseTimeMs: $elapsedMs,
            httpStatus: $response->status(),
        );
    }
}
