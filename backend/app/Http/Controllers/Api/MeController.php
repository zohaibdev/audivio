<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Feature;
use App\Models\Plan;
use App\Services\Plan\FeatureAccessResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Plan-derived self-service endpoints. Optional auth (like AuditController's
 * store()/show()) rather than auth:sanctum-gated — an anonymous visitor is
 * on the default plan and the public audit-submission page needs to show
 * them the same locked/unlocked feature state a logged-in user would see.
 *
 * GET /api/user already eager-loads subscription.plan (§8), so there is no
 * /api/me/plan here — that would duplicate an existing endpoint.
 */
class MeController extends Controller
{
    public function __construct(private readonly FeatureAccessResolver $featureAccess) {}

    public function features(Request $request): JsonResponse
    {
        $user = $request->user('sanctum');
        $plan = $this->featureAccess->planFor($user);

        $otherActivePlans = Plan::where('is_active', true)
            ->when($plan, fn ($q) => $q->where('id', '!=', $plan->id))
            ->with('auditFeatures')
            ->get();

        $features = Feature::where('is_enabled', true)->orderBy('name')->get()->map(function (Feature $feature) use ($plan, $otherActivePlans) {
            $enabled = (bool) $plan?->hasAuditFeature($feature->key);

            return [
                'key' => $feature->key,
                'name' => $feature->name,
                'description' => $feature->description,
                'enabled' => $enabled,
                'plans_with_access' => $enabled ? [] : $otherActivePlans
                    ->filter(fn (Plan $candidate) => $candidate->hasAuditFeature($feature->key))
                    ->map(fn (Plan $candidate) => ['id' => $candidate->id, 'name' => $candidate->name, 'slug' => $candidate->slug])
                    ->values(),
            ];
        });

        return ApiResponse::success($features->values());
    }

    public function aiModels(Request $request): JsonResponse
    {
        $user = $request->user('sanctum');
        $plan = $this->featureAccess->planFor($user);

        if ($plan === null) {
            return ApiResponse::success([]);
        }

        $models = $plan->allowedAiModels()->with('apiProvider')->get()
            ->filter(fn ($model) => $model->isUsable())
            ->map(fn ($model) => [
                'id' => $model->id,
                'provider' => $model->apiProvider->slug,
                'provider_name' => $model->apiProvider->name,
                'model_id' => $model->model_id,
                'display_name' => $model->display_name,
                'description' => $model->description,
                'context_window' => $model->context_window,
                'capabilities' => $model->capabilities,
            ])
            ->values();

        return ApiResponse::success($models);
    }
}
