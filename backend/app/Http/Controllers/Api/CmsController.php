<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CmsPage;
use Illuminate\Http\JsonResponse;

/**
 * Public read endpoint — the frontend (once built) fetches page content
 * from here instead of anything being hardcoded, per the project's
 * "CMS content never hardcoded" rule.
 */
class CmsController extends Controller
{
    public function show(string $slug): JsonResponse
    {
        $page = CmsPage::with('sections')->where('slug', $slug)->first();

        if ($page === null) {
            return ApiResponse::error('Page not found.', 404);
        }

        return ApiResponse::success($page);
    }
}
