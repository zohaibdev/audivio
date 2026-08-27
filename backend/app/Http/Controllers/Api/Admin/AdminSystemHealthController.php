<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Only reports what's actually checkable from within a single request —
 * a real DB round-trip and a real cache round-trip. Queue/Redis/Horizon
 * connectivity isn't included: this app has no live worker-heartbeat
 * mechanism, so reporting a status for those would mean guessing rather
 * than checking (see CONTEXT.md §23 for what's honestly available here
 * vs what would need real infrastructure to answer).
 */
class AdminSystemHealthController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'queue_connection' => config('queue.default'),
        ]);
    }

    /**
     * @return array{status: string, message: ?string}
     */
    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();

            return ['status' => 'ok', 'message' => null];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{status: string, message: ?string}
     */
    private function checkCache(): array
    {
        try {
            $key = 'system_health_check';
            Cache::put($key, true, 5);

            return Cache::get($key) === true
                ? ['status' => 'ok', 'message' => null]
                : ['status' => 'error', 'message' => 'Cache round-trip did not return the expected value.'];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}
