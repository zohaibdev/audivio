<?php

namespace App\Services\Admin;

use App\Models\AiAnalysis;
use App\Models\ApiUsageLog;
use App\Models\Audit;
use App\Models\AuditSchedule;
use App\Models\Lead;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\DB;

class AdminDashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $today = now()->toDateString();

        return [
            'users' => [
                'total' => User::count(),
                'admins' => User::where('is_admin', true)->count(),
                'new_today' => User::whereDate('created_at', $today)->count(),
            ],
            'websites' => ['total' => Website::count()],
            'audits' => [
                'total' => Audit::count(),
                'today' => Audit::whereDate('created_at', $today)->count(),
                'this_month' => Audit::whereYear('created_at', now()->year)->whereMonth('created_at', now()->month)->count(),
                'by_status' => Audit::query()
                    ->selectRaw('status, count(*) as count')
                    ->groupBy('status')
                    ->pluck('count', 'status'),
            ],
            'leads' => [
                'total' => Lead::count(),
                'by_status' => Lead::query()
                    ->selectRaw('status, count(*) as count')
                    ->groupBy('status')
                    ->pluck('count', 'status'),
            ],
            'ai_usage' => [
                'total_calls' => AiAnalysis::whereNull('error')->count(),
                'failed_calls' => AiAnalysis::whereNotNull('error')->count(),
                'total_prompt_tokens' => (int) AiAnalysis::sum('prompt_tokens'),
                'total_completion_tokens' => (int) AiAnalysis::sum('completion_tokens'),
            ],
            'failed_jobs' => ['total' => DB::table('failed_jobs')->count()],
            'audit_schedules' => [
                'total' => AuditSchedule::count(),
                'active' => AuditSchedule::where('is_active', true)->count(),
            ],
            'provider_usage_today' => $this->providerUsageToday($today),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function providerUsageToday(string $today): array
    {
        $byProvider = ApiUsageLog::query()
            ->whereDate('created_at', $today)
            ->selectRaw('provider, count(*) as requests')
            ->groupBy('provider')
            ->pluck('requests', 'provider');

        $aiProviders = ['claude', 'openai', 'gemini'];

        return [
            'requests_by_provider' => $byProvider,
            'ai_requests' => ApiUsageLog::whereDate('created_at', $today)->whereIn('provider', $aiProviders)->count(),
            'ai_tokens' => (int) ApiUsageLog::whereDate('created_at', $today)->whereIn('provider', $aiProviders)->sum('total_tokens'),
            'estimated_ai_cost' => round((float) ApiUsageLog::whereDate('created_at', $today)->whereIn('provider', $aiProviders)->sum('estimated_cost'), 4),
            'pagespeed_requests' => ApiUsageLog::whereDate('created_at', $today)->where('provider', 'pagespeed')->count(),
        ];
    }
}
