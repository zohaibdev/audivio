<?php

namespace App\Services\Admin;

use App\Models\AiAnalysis;
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
        return [
            'users' => [
                'total' => User::count(),
                'admins' => User::where('is_admin', true)->count(),
            ],
            'websites' => ['total' => Website::count()],
            'audits' => [
                'total' => Audit::count(),
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
        ];
    }
}
