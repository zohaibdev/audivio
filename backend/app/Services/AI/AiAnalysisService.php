<?php

namespace App\Services\AI;

use App\DTOs\AiResponse;
use App\Enums\AiTaskComplexity;
use App\Enums\AuditCategory;
use App\Models\AiAnalysis;
use App\Models\ApiProvider;
use App\Models\ApiUsageLog;
use App\Models\Audit;
use App\Services\Audit\AuditLifecycle;
use App\Services\Plan\EntitlementResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

        // The plan's AI budget for one audit (§40), checked after the cache
        // lookup above so a cached answer is always free — re-reading a
        // result already paid for must not consume more allowance.
        if ($this->exceedsPlanAiBudget($audit, $category, $contextHash, $prompt, $context)) {
            return null;
        }

        // How much model this category's work actually needs (§53), so a
        // simple extraction is not billed at a flagship model's rate.
        $resolved = $this->planModelResolver->resolveFor($audit, $this->complexityFor($category));

        if ($resolved === null) {
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

        $provider = $resolved->provider;
        $model = $resolved->model;

        if ($this->exceedsConfiguredLimits($provider->name())) {
            Log::channel('ai')->warning('AI analysis skipped: provider request limit reached', [
                'audit_id' => $audit->id,
                'category' => $category->value,
                'provider' => $provider->name(),
            ]);

            $this->logUsage($provider->name(), $category, $audit, success: false, errorCategory: 'limit_exceeded', model: $model);

            return null;
        }

        try {
            $response = $provider->analyze($prompt, $context, $model);
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
                    'model' => $model,
                    'prompt_version' => $this->promptVersion(),
                    'context' => $context,
                    'prompt' => $prompt,
                    'error' => Str::limit($e->getMessage(), AuditLifecycle::MAX_ERROR_MESSAGE),
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
                model: $model,
            );

            return null;
        }

        AiAnalysis::updateOrCreate(
            ['audit_id' => $audit->id, 'category' => $category->value, 'context_hash' => $contextHash],
            [
                'provider' => $response->provider,
                'model' => $model,
                'prompt_version' => $this->promptVersion(),
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
            model: $model,
        );

        return $response;
    }

    /**
     * Whether this audit has spent the AI allowance its owner's plan
     * grants.
     *
     * Two separate budgets, both from Entitlements (§40):
     * `ai_analyses_per_audit` bounds every AI call the audit makes, and
     * `aio_prompt_limit` bounds the AIO category's own prompts within
     * that — a plan can sell "AI analysis" broadly while still metering
     * the expensive AIO work.
     *
     * A refusal is recorded as an `ai_analyses` row with an error, exactly
     * as an unavailable provider is, so the report says the category was
     * not analyzed rather than silently omitting AI findings.
     *
     * @param  array<string, mixed>  $context
     */
    private function exceedsPlanAiBudget(
        Audit $audit,
        AuditCategory $category,
        string $contextHash,
        string $prompt,
        array $context,
    ): bool {
        $entitlements = app(EntitlementResolver::class)->forOrDeny($audit->user);

        $spent = fn (?AuditCategory $only) => AiAnalysis::where('audit_id', $audit->id)
            ->when($only !== null, fn ($q) => $q->where('category', $only->value))
            ->whereNull('error')
            ->count();

        $exceeded = match (true) {
            $entitlements->aiAnalysesPerAudit !== null
                && $spent(null) >= $entitlements->aiAnalysesPerAudit => 'ai_analyses_per_audit',

            $category === AuditCategory::Aio
                && $entitlements->aioPromptLimit !== null
                && $spent(AuditCategory::Aio) >= $entitlements->aioPromptLimit => 'aio_prompt_limit',

            default => null,
        };

        if ($exceeded === null) {
            return false;
        }

        Log::channel('ai')->info('AI analysis skipped: plan AI budget spent', [
            'audit_id' => $audit->id,
            'category' => $category->value,
            'limit' => $exceeded,
        ]);

        AiAnalysis::updateOrCreate(
            ['audit_id' => $audit->id, 'category' => $category->value, 'context_hash' => $contextHash],
            [
                'provider' => 'unavailable',
                'context' => $context,
                'prompt' => $prompt,
                'error' => "This audit has used the AI analysis included in its plan ({$exceeded}).",
            ],
        );

        return true;
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

    /**
     * How much model this category's analysis needs, from config so the
     * judgement is editable without a deploy (§53).
     */
    private function complexityFor(AuditCategory $category): AiTaskComplexity
    {
        $configured = config("ai.task_complexity.{$category->value}")
            ?? config('ai.default_complexity', 'standard');

        // An unrecognised value must not silently become the cheapest
        // option, so it lands on Standard rather than Simple.
        return AiTaskComplexity::tryFromValue((string) $configured) ?? AiTaskComplexity::Standard;
    }

    /**
     * Which prompt revision produced this call, so a cost or a bad answer
     * can be traced back to the wording that caused it.
     */
    private function promptVersion(): ?string
    {
        $version = config('audit_versions.prompt');

        return $version === null ? null : (string) $version;
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
        ?string $model = null,
    ): void {
        $provider = ApiProvider::where('slug', $providerSlug)->first();

        // The model actually called, not the one the provider row happens
        // to be set to. Since §53 those can differ, and a usage chart that
        // reported the configured model would be describing requests that
        // never happened.
        $model ??= $provider?->model;
        $totalTokens = ($promptTokens !== null || $completionTokens !== null)
            ? ($promptTokens ?? 0) + ($completionTokens ?? 0)
            : null;

        ApiUsageLog::create([
            'provider' => $providerSlug,
            'service' => 'ai_analysis',
            'request_type' => $category->value,
            'audit_id' => $audit->id,
            'user_id' => $audit->user_id,
            'model' => $model,
            'prompt_version' => $this->promptVersion(),
            'success' => $success,
            'http_status' => $httpStatus,
            'response_time_ms' => $responseTimeMs,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
            'estimated_cost' => $provider?->estimateCost($promptTokens, $completionTokens, $model),
            'error_category' => $errorCategory,
        ]);
    }
}
