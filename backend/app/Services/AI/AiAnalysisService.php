<?php

namespace App\Services\AI;

use App\DTOs\AiResponse;
use App\Enums\AuditCategory;
use App\Models\AiAnalysis;
use App\Models\ApiProvider;
use App\Models\ApiUsageLog;
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
        private readonly PlanAiModelResolver $planModelResolver,
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

        $provider = $this->planModelResolver->resolveFor($audit);

        if ($provider === null) {
            Log::channel('ai')->info('AI analysis skipped: no AI model allowed by the current plan', [
                'audit_id' => $audit->id,
                'category' => $category->value,
            ]);

            // No provider was ever selected, so there's nothing to
            // attribute an api_usage_logs row to (that table's `provider`
            // column is required and means "a specific provider was
            // called") — but the *category* must still show as visibly
            // AI-unavailable rather than silently having no record at
            // all, so the report can say so instead of just omitting the
            // AI-derived findings without explanation.
            AiAnalysis::updateOrCreate(
                ['audit_id' => $audit->id, 'category' => $category->value, 'context_hash' => $contextHash],
                [
                    'provider' => 'unavailable',
                    'context' => $context,
                    'prompt' => $prompt,
                    'error' => 'No AI model configured/enabled is allowed by the current plan.',
                ],
            );

            return null;
        }

        if ($this->exceedsConfiguredLimits($provider->name())) {
            Log::channel('ai')->warning('AI analysis skipped: provider request limit reached', [
                'audit_id' => $audit->id,
                'category' => $category->value,
                'provider' => $provider->name(),
            ]);

            $this->logUsage($provider->name(), $category, $audit, success: false, errorCategory: 'limit_exceeded');

            return null;
        }

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

            $httpStatus = $e instanceof AiProviderException ? $e->httpStatus : null;
            $this->logUsage(
                $provider->name(),
                $category,
                $audit,
                success: false,
                httpStatus: $httpStatus,
                errorCategory: $httpStatus !== null ? 'http_error' : 'exception',
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

        $this->logUsage(
            $provider->name(),
            $category,
            $audit,
            success: true,
            httpStatus: $response->httpStatus,
            responseTimeMs: $response->responseTimeMs,
            promptTokens: $response->promptTokens,
            completionTokens: $response->completionTokens,
        );

        return $response;
    }

    private function exceedsConfiguredLimits(string $providerSlug): bool
    {
        $provider = ApiProvider::where('slug', $providerSlug)->first();

        if ($provider === null) {
            return false;
        }

        $dailyLimit = $provider->getConfigValue('daily_request_limit');
        $monthlyLimit = $provider->getConfigValue('monthly_request_limit');

        if ($dailyLimit !== null) {
            $today = ApiUsageLog::where('provider', $providerSlug)->whereDate('created_at', now()->toDateString())->count();
            if ($today >= $dailyLimit) {
                return true;
            }
        }

        if ($monthlyLimit !== null) {
            $thisMonth = ApiUsageLog::where('provider', $providerSlug)
                ->whereYear('created_at', now()->year)
                ->whereMonth('created_at', now()->month)
                ->count();
            if ($thisMonth >= $monthlyLimit) {
                return true;
            }
        }

        return false;
    }

    private function logUsage(
        string $providerSlug,
        AuditCategory $category,
        Audit $audit,
        bool $success,
        ?int $httpStatus = null,
        ?int $responseTimeMs = null,
        ?int $promptTokens = null,
        ?int $completionTokens = null,
        ?string $errorCategory = null,
    ): void {
        $provider = ApiProvider::where('slug', $providerSlug)->first();
        $totalTokens = ($promptTokens !== null || $completionTokens !== null)
            ? ($promptTokens ?? 0) + ($completionTokens ?? 0)
            : null;

        ApiUsageLog::create([
            'provider' => $providerSlug,
            'service' => 'ai_analysis',
            'request_type' => $category->value,
            'audit_id' => $audit->id,
            'user_id' => $audit->user_id,
            'model' => $provider?->model,
            'success' => $success,
            'http_status' => $httpStatus,
            'response_time_ms' => $responseTimeMs,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
            'estimated_cost' => $provider?->estimateCost($promptTokens, $completionTokens),
            'error_category' => $errorCategory,
        ]);
    }
}
