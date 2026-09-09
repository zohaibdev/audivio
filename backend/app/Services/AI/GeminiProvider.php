<?php

namespace App\Services\AI;

use App\Contracts\AiProvider;
use App\DTOs\AiResponse;
use App\Enums\AiProviderName;
use App\Models\ApiProvider;
use App\Services\Provider\ProviderResolver;
use Illuminate\Support\Facades\Http;

class GeminiProvider implements AiProvider
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models';

    public function name(): string
    {
        return AiProviderName::Gemini->value;
    }

    public function analyze(string $prompt, array $context = [], ?string $model = null): AiResponse
    {
        $resolver = new ProviderResolver;
        $provider = $resolver->resolve($this->name());

        $userMessage = $context === []
            ? $prompt
            : $prompt."\n\nContext (JSON):\n".json_encode($context, JSON_PRETTY_PRINT);

        return $this->performRequest($resolver, $provider, $userMessage, $model);
    }

    public function testConnection(): array
    {
        $resolver = new ProviderResolver;

        try {
            $provider = $resolver->resolveConfigured($this->name());
            $response = $this->performRequest($resolver, $provider, 'Respond with the single word: OK.');

            return ['success' => true, 'response_time_ms' => $response->responseTimeMs, 'message' => 'Connected successfully.'];
        } catch (AiProviderException $e) {
            // The failed call's own elapsed time, not null — see
            // AiProviderException (§56).
            return ['success' => false, 'response_time_ms' => $e->responseTimeMs, 'message' => $e->getMessage()];
        }
    }

    private function performRequest(ProviderResolver $resolver, ApiProvider $provider, string $userMessage, ?string $model = null): AiResponse
    {
        // The plan-approved model for this task, falling back to the one
        // the provider row is configured with (§53).
        $model ??= $provider->model;
        $generationConfig = [
            'maxOutputTokens' => $provider->getConfigValue('max_tokens', 2048),
        ];

        if ($provider->getConfigValue('temperature') !== null) {
            $generationConfig['temperature'] = $provider->getConfigValue('temperature');
        }

        $url = self::ENDPOINT."/{$model}:generateContent";

        $startedAt = microtime(true);

        try {
            $response = Http::timeout($provider->getConfigValue('request_timeout', 60))
                ->post($url.'?key='.$provider->api_key, [
                    'contents' => [
                        ['parts' => [['text' => $userMessage]]],
                    ],
                    'generationConfig' => $generationConfig,
                ]);
        } catch (\Throwable $e) {
            $resolver->recordFailure($provider, $e->getMessage());

            throw new AiProviderException(
                "Gemini request failed: {$e->getMessage()}",
                responseTimeMs: (int) round((microtime(true) - $startedAt) * 1000),
            );
        }

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        if (! $response->successful()) {
            $resolver->recordFailure($provider, "HTTP {$response->status()}: {$response->body()}");

            throw new AiProviderException(
                "Gemini API error ({$response->status()}): ".$response->body(),
                $response->status(),
                $elapsedMs,
            );
        }

        $resolver->recordSuccess($provider);

        $text = collect($response->json('candidates.0.content.parts', []))
            ->pluck('text')
            ->implode('');

        return new AiResponse(
            provider: $this->name(),
            content: $text,
            raw: $response->json(),
            promptTokens: $response->json('usageMetadata.promptTokenCount'),
            completionTokens: $response->json('usageMetadata.candidatesTokenCount'),
            responseTimeMs: $elapsedMs,
            httpStatus: $response->status(),
        );
    }
}
