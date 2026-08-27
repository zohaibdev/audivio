<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlanRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Plan;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminPlanController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(Plan::orderBy('price_cents')->get());
    }

    public function store(PlanRequest $request): JsonResponse
    {
        $plan = DB::transaction(function () use ($request) {
            if ($request->boolean('is_default')) {
                Plan::where('is_default', true)->update(['is_default' => false]);
            }

            return Plan::create($request->validated());
        });

        return ApiResponse::success($plan, status: 201);
    }

    public function update(PlanRequest $request, Plan $plan): JsonResponse
    {
        DB::transaction(function () use ($request, $plan) {
            if ($request->boolean('is_default')) {
                Plan::where('is_default', true)->where('id', '!=', $plan->id)->update(['is_default' => false]);
            }

            $plan->update($request->validated());
        });

        return ApiResponse::success($plan->fresh());
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
}
