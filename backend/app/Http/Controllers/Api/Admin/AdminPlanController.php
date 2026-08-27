<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlanRequest;
use App\Http\Requests\Admin\UpdatePlanAiModelsRequest;
use App\Http\Requests\Admin\UpdatePlanFeaturesRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Feature;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminPlanController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success($this->withEditorRelations(Plan::orderBy('price_cents'))->get());
    }

    public function store(PlanRequest $request): JsonResponse
    {
        $plan = DB::transaction(function () use ($request) {
            if ($request->boolean('is_default')) {
                Plan::where('is_default', true)->update(['is_default' => false]);
            }

            return Plan::create($request->validated());
        });

        return ApiResponse::success($this->present($plan), status: 201);
    }

    public function update(PlanRequest $request, Plan $plan): JsonResponse
    {
        DB::transaction(function () use ($request, $plan) {
            if ($request->boolean('is_default')) {
                Plan::where('is_default', true)->where('id', '!=', $plan->id)->update(['is_default' => false]);
            }

            $plan->update($request->validated());
        });

        return ApiResponse::success($this->present($plan->fresh()));
    }

    public function destroy(Plan $plan): JsonResponse
    {
        try {
            $plan->delete();
        } catch (QueryException) {
            return ApiResponse::error('This plan has active subscriptions and cannot be deleted.', 409);
        }

        return ApiResponse::success(message: 'Plan deleted.');
    }

    /**
     * Bulk-set every audit feature's per-plan enable state in one request —
     * the admin UI's "Audit Features" tab submits the whole checkbox grid
     * at once rather than one PATCH per checkbox.
     */
    public function updateFeatures(UpdatePlanFeaturesRequest $request, Plan $plan): JsonResponse
    {
        $keyToId = Feature::pluck('id', 'key');

        $sync = [];
        foreach ($request->validated('features') as $entry) {
            $featureId = $keyToId[$entry['key']] ?? null;
            if ($featureId !== null) {
                $sync[$featureId] = ['is_enabled' => $entry['is_enabled']];
            }
        }

        $plan->auditFeatures()->sync($sync);

        return ApiResponse::success($this->present($plan->fresh()));
    }

    /**
     * Bulk-replace the plan's allowed AI model set (multi-select from the
     * admin UI's "AI Models" tab).
     */
    public function updateAiModels(UpdatePlanAiModelsRequest $request, Plan $plan): JsonResponse
    {
        $plan->allowedAiModels()->sync($request->validated('ai_model_ids'));

        return ApiResponse::success($this->present($plan->fresh()));
    }

    private function withEditorRelations(Builder $query): Builder
    {
        return $query->withCount('subscriptions')
            ->with(['auditFeatures', 'allowedAiModels.apiProvider']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Plan $plan): array
    {
        $plan->loadCount('subscriptions')->load(['auditFeatures', 'allowedAiModels.apiProvider']);

        return $plan->toArray();
    }
}
