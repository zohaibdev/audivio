<?php

namespace App\Services\AI;

use App\DTOs\ResolvedAiModel;
use App\Enums\AiTaskComplexity;
use App\Models\AiModel;
use App\Models\Audit;
use App\Services\Plan\FeatureAccessResolver;
use Illuminate\Support\Collection;

/**
 * Picks which provider *and which model* an audit is allowed to call,
 * given its owner's (or the default plan's, for anonymous audits)
 * allowed-models list and how much model the task actually needs.
 *
 * Before §53 this returned a provider only, and every call landed on
 * whatever single model that provider's row was set to. That made the
 * "smaller models for simple tasks" rule unimplementable: there was no
 * per-request model to choose. Providers now accept a model override, so
 * resolution can pick the cheapest allowed model whose tier meets the
 * task, and fall back to the provider's configured model when nothing is
 * classified.
 *
 * The plan gate is unchanged and still absolute: a model that is not in
 * `plan_ai_models` is never selectable, whatever its tier or price. Tiering
 * decides *among* what a plan already permits — it never widens it.
 */
class PlanAiModelResolver
{
    public function __construct(
        private readonly AiProviderFactory $providerFactory,
        private readonly FeatureAccessResolver $featureAccess,
    ) {}

    public function resolveFor(Audit $audit, ?AiTaskComplexity $complexity = null): ?ResolvedAiModel
    {
        $plan = $this->featureAccess->planFor($audit->user);

        if ($plan === null) {
            // No plan system configured at all — same "nothing to gate
            // against yet" reasoning as FeatureAccessResolver::featureAllowed():
            // fall back to the configured default provider rather than
            // blocking AI outright.
            return new ResolvedAiModel($this->providerFactory->make());
        }

        $allowed = $plan->allowedAiModels()
            ->with('apiProvider')
            ->get()
            ->filter(fn (AiModel $model) => $model->apiProvider !== null && $model->isUsable());

        if ($allowed->isEmpty()) {
            return null;
        }

        if ($complexity !== null) {
            $tiered = $this->cheapestMeeting($allowed, $complexity);

            if ($tiered !== null) {
                return new ResolvedAiModel(
                    provider: $this->providerFactory->make($tiered->apiProvider->slug),
                    model: $tiered->model_id,
                    tier: $tiered->tier?->value,
                );
            }
        }

        // Nothing classified highly enough, or no complexity asked for.
        // Fall back to the pre-§53 rule: an allowed model that is also the
        // one its provider is actively configured with, so the request
        // lands on a model the plan really approved.
        $live = $allowed->filter(fn (AiModel $model) => $model->model_id === $model->apiProvider->model);

        if ($live->isEmpty()) {
            return null;
        }

        $defaultSlug = config('ai.default');
        $chosen = $live->first(fn (AiModel $model) => $model->apiProvider->slug === $defaultSlug)
            ?? $live->first();

        return new ResolvedAiModel(
            provider: $this->providerFactory->make($chosen->apiProvider->slug),
            model: $chosen->model_id,
            tier: $chosen->tier?->value,
        );
    }

    /**
     * The cheapest allowed model strong enough for this task.
     *
     * "Strong enough" is one-directional on purpose: a Complex task will
     * never be handed a Simple model. Saving tokens on a judgement that
     * then comes back wrong is not a saving — it is a worse audit sold at
     * the same price.
     *
     * @param  Collection<int, AiModel>  $allowed
     */
    private function cheapestMeeting(Collection $allowed, AiTaskComplexity $required): ?AiModel
    {
        return $allowed
            ->filter(fn (AiModel $model) => $model->tier !== null && $model->tier->meets($required))
            ->sortBy([
                // Cheapest first, but a model with no rates recorded sorts
                // last rather than first: an unknown price is not a low one.
                fn (AiModel $a, AiModel $b) => ($a->blendedCostPer1k() ?? INF) <=> ($b->blendedCostPer1k() ?? INF),
                // Then the least over-powered, so a Simple task does not
                // take a flagship just because someone priced it low.
                fn (AiModel $a, AiModel $b) => $a->tier->rank() <=> $b->tier->rank(),
            ])
            ->first();
    }
}
