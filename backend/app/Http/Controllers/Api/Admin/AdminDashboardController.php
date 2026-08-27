<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Admin\AdminDashboardService;
use Illuminate\Http\JsonResponse;

class AdminDashboardController extends Controller
{
    public function index(AdminDashboardService $dashboard): JsonResponse
    {
        return ApiResponse::success($dashboard->summary());
    }
}
