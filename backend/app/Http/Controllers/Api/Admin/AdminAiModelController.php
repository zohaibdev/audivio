<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AiModelRequest;
use App\Http\Responses\ApiResponse;
use App\Models\AiModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Centralized AI model catalog (per provider) — see AiModel and
 * PlanAiModelResolver. Distinct from ApiProvider::$model, which is the one
 * model a provider is currently actively calling.
 */
class AdminAiModelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AiModel::with('apiProvider')->orderBy('api_provider_id')->orderBy('display_name');

        if ($request->filled('provider')) {
            $query->whereHas('apiProvider', fn ($q) => $q->where('slug', $request->string('provider')));
        }

        return ApiResponse::success($query->get());
    }

    public function store(AiModelRequest $request): JsonResponse
    {
        $model = AiModel::create($request->validated());

        return ApiResponse::success($model->load('apiProvider'), status: 201);
    }

    public function update(AiModelRequest $request, AiModel $aiModel): JsonResponse
    {
        $aiModel->update($request->validated());

        return ApiResponse::success($aiModel->fresh()->load('apiProvider'));
    }

    public function destroy(AiModel $aiModel): JsonResponse
    {
        if ($aiModel->plans()->exists()) {
            return ApiResponse::error('This model is assigned to one or more plans — remove it from every plan first.', 409);
        }

        $aiModel->delete();

        return ApiResponse::success(message: 'AI model deleted.');
    }
}
