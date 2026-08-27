<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\UsageLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUsageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = UsageLog::with('user', 'website', 'audit')->orderByDesc('id');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        if ($request->filled('action')) {
            $query->where('action', $request->string('action'));
        }

        return ApiResponse::success($query->paginate(25));
    }
}
