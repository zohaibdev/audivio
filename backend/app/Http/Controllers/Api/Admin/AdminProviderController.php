<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProviderRequest;
use App\Http\Responses\ApiResponse;
use App\Models\ApiProvider;
use App\Models\ApiUsageLog;
use App\Services\AI\AiProviderFactory;
use App\Services\Audit\PageSpeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Central admin surface for every external service this app calls
 * (Claude/OpenAI/Gemini/PageSpeed today — see App\Models\ApiProvider for
 * why adding a fifth provider is a config shape, not a migration).
 * Credentials are encrypted at rest and never leave this layer: every
 * response here is built from an explicit field list, never the model's
 * raw attributes, so a future column addition can't accidentally leak.
 */
class AdminProviderController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(
            ApiProvider::orderBy('name')->get()->map(fn (ApiProvider $provider) => $this->present($provider)),
        );
    }

    public function show(ApiProvider $apiProvider): JsonResponse
    {
        return ApiResponse::success($this->present($apiProvider));
    }

    public function update(UpdateProviderRequest $request, ApiProvider $apiProvider): JsonResponse
    {
        $data = $request->validated();
        $config = $apiProvider->config ?? [];

        foreach (['max_tokens', 'temperature', 'request_timeout', 'daily_request_limit', 'monthly_request_limit', 'mobile_enabled', 'desktop_enabled', 'cost_per_1k_input_tokens', 'cost_per_1k_output_tokens'] as $key) {
            if (array_key_exists($key, $data)) {
                $config[$key] = $data[$key];
            }
        }

        $update = ['config' => $config];

        if (array_key_exists('api_key', $data)) {
            // Normalize '' to null — encrypting an empty string still
            // produces a non-null ciphertext, which would make
            // isConfigured() think a real key exists.
            $update['api_key'] = $data['api_key'] !== '' ? $data['api_key'] : null;
        }

        if (array_key_exists('model', $data)) {
            $update['model'] = $data['model'];
        }

        $apiProvider->update($update);

        return ApiResponse::success($this->present($apiProvider->fresh()), message: 'Provider configuration saved.');
    }

    public function updateStatus(Request $request, ApiProvider $apiProvider): JsonResponse
    {
        $request->validate(['is_enabled' => ['required', 'boolean']]);

        if ($request->boolean('is_enabled') && ! $apiProvider->isConfigured()) {
            return ApiResponse::error('Add an API key before enabling this provider.', 422);
        }

        $apiProvider->update(['is_enabled' => $request->boolean('is_enabled')]);

        return ApiResponse::success($this->present($apiProvider->fresh()));
    }

    public function test(ApiProvider $apiProvider): JsonResponse
    {
        $result = match ($apiProvider->slug) {
            'claude', 'openai', 'gemini' => app(AiProviderFactory::class)->make($apiProvider->slug)->testConnection(),
            'pagespeed' => app(PageSpeedService::class)->testConnection(),
            default => ['success' => false, 'response_time_ms' => null, 'message' => 'No test implementation for this provider.'],
        };

        ApiUsageLog::create([
            'provider' => $apiProvider->slug,
            'service' => 'test_connection',
            'model' => $apiProvider->model,
            'success' => $result['success'],
            'response_time_ms' => $result['response_time_ms'],
            'error_category' => $result['success'] ? null : 'test_connection_failed',
        ]);

        return ApiResponse::success([
            'success' => $result['success'],
            'response_time_ms' => $result['response_time_ms'],
            'message' => $result['message'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ApiProvider $provider): array
    {
        $dailyLimit = $provider->getConfigValue('daily_request_limit');
        $monthlyLimit = $provider->getConfigValue('monthly_request_limit');

        $requestsToday = ApiUsageLog::where('provider', $provider->slug)->whereDate('created_at', now()->toDateString())->count();
        $requestsThisMonth = ApiUsageLog::where('provider', $provider->slug)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count();
        $failuresToday = ApiUsageLog::where('provider', $provider->slug)
            ->whereDate('created_at', now()->toDateString())
            ->where('success', false)
            ->count();

        return [
            'id' => $provider->id,
            'slug' => $provider->slug,
            'name' => $provider->name,
            'provider_type' => $provider->provider_type,
            'is_enabled' => $provider->is_enabled,
            'is_configured' => $provider->isConfigured(),
            'masked_key' => $provider->masked_key,
            'model' => $provider->model,
            'config' => $provider->config,
            'last_success_at' => $provider->last_success_at?->toIso8601String(),
            'last_failure_at' => $provider->last_failure_at?->toIso8601String(),
            'last_error' => $provider->last_error,
            'requests_today' => $requestsToday,
            'requests_this_month' => $requestsThisMonth,
            'failures_today' => $failuresToday,
            'daily_limit' => $dailyLimit,
            'monthly_limit' => $monthlyLimit,
            'daily_usage_percentage' => $dailyLimit ? min(100, (int) round(($requestsToday / max(1, $dailyLimit)) * 100)) : null,
            'monthly_usage_percentage' => $monthlyLimit ? min(100, (int) round(($requestsThisMonth / max(1, $monthlyLimit)) * 100)) : null,
        ];
    }
}
