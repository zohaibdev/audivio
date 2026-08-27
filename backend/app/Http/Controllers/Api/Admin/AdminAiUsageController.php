<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AiAnalysis;
use Illuminate\Http\JsonResponse;

class AdminAiUsageController extends Controller
{
    public function index(): JsonResponse
    {
        $byProvider = AiAnalysis::query()
            ->selectRaw('provider, count(*) as calls, sum(prompt_tokens) as prompt_tokens, sum(completion_tokens) as completion_tokens')
            ->groupBy('provider')
            ->get();

        $byCategory = AiAnalysis::query()
            ->selectRaw('category, count(*) as calls, sum(case when error is not null then 1 else 0 end) as failures')
            ->groupBy('category')
            ->get();

        return ApiResponse::success([
            'by_provider' => $byProvider,
            'by_category' => $byCategory,
            'recent' => AiAnalysis::with('audit')->orderByDesc('id')->limit(50)->get(),
        ]);
    }
}
