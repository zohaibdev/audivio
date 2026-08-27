<?php

namespace App\Services\AI;

use App\Contracts\AiProvider;
use App\Models\AiModel;
use App\Models\Audit;
use App\Services\Plan\FeatureAccessResolver;

/**
 * Picks which AI provider an audit is allowed to call, given its owner's
 * (or the default plan's, for anonymous audits) allowed-models list.
 *
 * Provider classes have no concept of "call model X for this one request"
 * — each always calls whatever ApiProvider::$model is currently configured
 * (§23). So "the plan allows model X" only actually matters when X is also
 * the provider's current active model; otherwise the plan's approval is
 * moot because nothing would ever call that model anyway. This is why
 * resolution filters down to allowed AiModel rows whose model_id matches
 * their provider's live $model, rather than picking any allowed row and
 * hoping the provider will honor it.
 */
class PlanAiModelResolver
{
    public function __construct(
        private readonly AiProviderFactory $providerFactory,
        private readonly FeatureAccessResolver $featureAccess,
    ) {}

    public function resolveFor(Audit $audit): ?AiProvider
    {
        $plan = $this->featureAccess->planFor($audit->user);

        if ($plan === null) {
            // No plan system configured at all — same "nothing to gate
            // against yet" reasoning as FeatureAccessResolver::featureAllowed():
            // fall back to the pre-existing "just use the configured
            // default provider" behavior rather than blocking AI outright.
            return $this->providerFactory->make();
        }

        $usable = $plan->allowedAiModels()
            ->with('apiProvider')
            ->get()
            ->filter(fn (AiModel $model) => $model->apiProvider !== null
                && $model->model_id === $model->apiProvider->model
                && $model->isUsable());

        if ($usable->isEmpty()) {
            return null;
        }

        $defaultSlug = config('ai.default');
        $chosen = $usable->first(fn (AiModel $model) => $model->apiProvider->slug === $defaultSlug)
            ?? $usable->first();

        return $this->providerFactory->make($chosen->apiProvider->slug);
    }
}
