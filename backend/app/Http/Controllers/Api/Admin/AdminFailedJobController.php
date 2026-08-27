<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * No Eloquent model for failed_jobs — it's Laravel's own queue
 * bookkeeping table, not an application model. Retrying a failed job
 * (php artisan queue:retry) is deliberately not exposed here yet — it's
 * an operational action with more failure modes than a simple list/
 * delete pair; add it if a real need for remote retry shows up.
 */
class AdminFailedJobController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(
            DB::table('failed_jobs')->orderByDesc('id')->paginate(25),
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = DB::table('failed_jobs')->where('id', $id)->delete();

        if ($deleted === 0) {
            return ApiResponse::error('Failed job not found.', 404);
        }

        return ApiResponse::success(message: 'Failed job removed.');
    }
}
