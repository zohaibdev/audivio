<?php

namespace App\Services\AI;

use App\DTOs\AiResponse;
use App\Enums\AuditCategory;
use App\Models\AiAnalysis;
use App\Models\Audit;
use Illuminate\Support\Facades\Log;

/**
 * Shared entry point for every AI-driven audit module. Handles the
 * optimization rules that apply regardless of category: cache identical
 * (audit, category, context) calls instead of re-spending tokens, and
 * never let a provider failure take down the whole module — callers get
 * null and are expected to fall back to their deterministic-only result.
 */
class AiAnalysisService
{
    public function __construct(
        private readonly AiProviderFactory $providerFactory,
    ) {}

    /**
     * @param  array<string, mixed>  $context  Pre-extracted structured data only — never raw page HTML.
     */
    public function analyze(Audit $audit, AuditCategory $category, string $prompt, array $context = []): ?AiResponse
    {
        $contextHash = hash('sha256', $category->value.'|'.$prompt.'|'.json_encode($context));

        if (config('ai.cache_analyses')) {
            $cached = AiAnalysis::query()
                ->where('audit_id', $audit->id)
                ->where('category', $category->value)
                ->where('context_hash', $contextHash)
                ->whereNull('error')
                ->first();

            if ($cached) {
                return new AiResponse(
                    provider: $cached->provider,
                    content: $cached->response ?? '',
                    promptTokens: $cached->prompt_tokens,
                    completionTokens: $cached->completion_tokens,
                );
            }
        }

        $provider = $this->providerFactory->make();

        try {
            $response = $provider->analyze($prompt, $context);
        } catch (\Throwable $e) {
            Log::channel('ai')->warning('AI analysis failed', [
                'audit_id' => $audit->id,
                'category' => $category->value,
                'provider' => $provider->name(),
                'error' => $e->getMessage(),
            ]);

            AiAnalysis::updateOrCreate(
                ['audit_id' => $audit->id, 'category' => $category->value, 'context_hash' => $contextHash],
                [
                    'provider' => $provider->name(),
                    'context' => $context,
                    'prompt' => $prompt,
                    'error' => substr($e->getMessage(), 0, 250),
                ],
            );

            return null;
        }

        AiAnalysis::updateOrCreate(
            ['audit_id' => $audit->id, 'category' => $category->value, 'context_hash' => $contextHash],
            [
                'provider' => $response->provider,
                'context' => $context,
                'prompt' => $prompt,
                'response' => $response->content,
                'prompt_tokens' => $response->promptTokens,
                'completion_tokens' => $response->completionTokens,
                'error' => null,
            ],
        );

        return $response;
    }
}
