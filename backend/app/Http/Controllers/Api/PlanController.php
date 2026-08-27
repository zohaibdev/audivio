<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;

/**
 * Public plan listing for the pricing page — plans/pricing aren't
 * sensitive, and the frontend needs real data here rather than
 * hardcoding tiers that may not match what's actually configured.
 */
class PlanController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(
            Plan::where('is_active', true)
                ->with(['auditFeatures' => fn ($q) => $q->where('features.is_enabled', true)->wherePivot('is_enabled', true)])
                ->orderBy('price_cents')
                ->get(),
        );
    }
}
