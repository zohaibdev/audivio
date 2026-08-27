<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service "my websites" listing — distinct from
 * Api\Admin\AdminWebsiteController, which lists every website across
 * every user. Requires auth:sanctum (unlike audit creation/status,
 * which are anonymous-friendly): an anonymous visitor has no persistent
 * identity to list websites against.
 */
class WebsiteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $request->user()->websites()
                ->withCount('audits')
                ->with('latestAudit.scores')
                ->orderByDesc('id')
                ->paginate(25),
        );
    }
}
