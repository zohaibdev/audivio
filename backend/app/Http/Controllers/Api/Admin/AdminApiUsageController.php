<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ApiUsageLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cross-provider usage/cost analytics from `api_usage_logs` — distinct
 * from AdminAiUsageController, which reports on `ai_analyses` (the
 * per-audit AI cache/result records). This controller answers "how is
 * the app using its external services" (ops/cost questions); that one
 * answers "what did AI find for this audit" (audit-content questions).
 */
class AdminApiUsageController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $query = $this->filtered($request);

        $totals = (clone $query)->selectRaw(
            'count(*) as total_requests, '.
            'sum(case when success = 1 then 1 else 0 end) as successful_requests, '.
            'sum(case when success = 0 then 1 else 0 end) as failed_requests, '.
            'sum(total_tokens) as total_tokens, '.
            'sum(estimated_cost) as estimated_cost, '.
            'avg(response_time_ms) as average_response_time_ms'
        )->first();

        return ApiResponse::success([
            'total_requests' => (int) $totals->total_requests,
            'successful_requests' => (int) $totals->successful_requests,
            'failed_requests' => (int) $totals->failed_requests,
            'total_tokens' => (int) ($totals->total_tokens ?? 0),
            'estimated_cost' => round((float) ($totals->estimated_cost ?? 0), 4),
            'average_response_time_ms' => $totals->average_response_time_ms !== null ? round((float) $totals->average_response_time_ms) : null,
        ]);
    }

    public function daily(Request $request): JsonResponse
    {
        $rows = $this->filtered($request)
            ->selectRaw(
                'DATE(created_at) as date, count(*) as requests, '.
                'sum(case when success = 0 then 1 else 0 end) as failures, '.
                'sum(total_tokens) as total_tokens, sum(estimated_cost) as estimated_cost'
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return ApiResponse::success($rows);
    }

    public function byProvider(Request $request): JsonResponse
    {
        $rows = $this->filtered($request)
            ->selectRaw(
                'provider, count(*) as requests, '.
                'sum(case when success = 0 then 1 else 0 end) as failures, '.
                'sum(total_tokens) as total_tokens, sum(estimated_cost) as estimated_cost, '.
                'avg(response_time_ms) as average_response_time_ms'
            )
            ->groupBy('provider')
            ->get();

        return ApiResponse::success($rows);
    }

    public function logs(Request $request): JsonResponse
    {
        $query = $this->filtered($request)->with(['user', 'audit'])->orderByDesc('id');

        return ApiResponse::success($query->paginate(25));
    }

    private function filtered(Request $request): Builder
    {
        $query = ApiUsageLog::query();

        if ($request->filled('provider')) {
            $query->where('provider', $request->string('provider'));
        }

        if ($request->filled('service')) {
            $query->where('service', $request->string('service'));
        }

        if ($request->filled('model')) {
            $query->where('model', $request->string('model'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        if ($request->filled('audit_id')) {
            $query->where('audit_id', $request->integer('audit_id'));
        }

        if ($request->filled('success')) {
            $query->where('success', $request->boolean('success'));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to'));
        }

        return $query;
    }
}
