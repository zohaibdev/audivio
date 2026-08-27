<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Feature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The 7-row audit-feature registry (see FeatureSeeder). Rows aren't
 * admin-creatable — each corresponds to a real AuditCategory wired in
 * FeatureAccessResolver, so an admin-invented key would have no audit
 * logic behind it — only global enable/disable is exposed here. Per-plan
 * enable/disable lives on AdminPlanController::updateFeatures().
 */
class AdminFeatureController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(Feature::orderBy('name')->get());
    }

    public function updateStatus(Request $request, Feature $feature): JsonResponse
    {
        $request->validate(['is_enabled' => ['required', 'boolean']]);

        $feature->update(['is_enabled' => $request->boolean('is_enabled')]);

        return ApiResponse::success($feature->fresh());
    }
}
