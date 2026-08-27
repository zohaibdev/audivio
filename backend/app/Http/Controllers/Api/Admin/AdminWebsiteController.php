<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Website;
use Illuminate\Http\JsonResponse;

class AdminWebsiteController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(
            Website::withCount('audits')->orderByDesc('id')->paginate(25),
        );
    }
}
