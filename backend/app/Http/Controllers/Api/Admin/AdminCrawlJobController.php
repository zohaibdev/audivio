<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CrawlJob;
use Illuminate\Http\JsonResponse;

class AdminCrawlJobController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(
            CrawlJob::with('audit.website')->orderByDesc('id')->paginate(25),
        );
    }
}
