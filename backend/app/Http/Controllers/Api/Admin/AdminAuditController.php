<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Audit;
use Illuminate\Http\JsonResponse;

class AdminAuditController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(
            Audit::with('website')->orderByDesc('id')->paginate(25),
        );
    }

    public function show(Audit $audit): JsonResponse
    {
        return ApiResponse::success(
            $audit->load('website', 'scores', 'issues', 'crawlJob'),
        );
    }
}
