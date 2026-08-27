<?php

namespace App\Services\User;

use App\Models\Audit;
use App\Models\UsageLog;
use App\Models\User;
use App\Models\Website;

/**
 * Enforces the free_audit limits from the spec: anonymous (IP-tracked,
 * lifetime limit), registered (per-plan daily limit, both DB-configurable
 * via the `plans` table — never hardcoded per user tier).
 */
class UsageLimitService
{
    private const ACTION_AUDIT_CREATED = 'audit_created';

    public function canCreateAudit(?User $user, string $ipAddress): bool
    {
        if ($user === null) {
            return ! UsageLog::where('ip_address', $ipAddress)
                ->where('action', self::ACTION_AUDIT_CREATED)
                ->exists();
        }

        $plan = $user->plan();
        if ($plan === null) {
            return false;
        }

        $todayCount = UsageLog::where('user_id', $user->id)
            ->where('action', self::ACTION_AUDIT_CREATED)
            ->whereDate('created_at', now()->toDateString())
            ->count();

        return $todayCount < $plan->daily_audit_limit;
    }

    public function maxPagesFor(?User $user): int
    {
        if ($user === null) {
            return config('audit_limits.anonymous_max_pages');
        }

        return $user->plan()?->max_pages ?? config('audit_limits.anonymous_max_pages');
    }

    public function recordAuditCreated(?User $user, string $ipAddress, Website $website, Audit $audit): void
    {
        UsageLog::create([
            'user_id' => $user?->id,
            'ip_address' => $ipAddress,
            'action' => self::ACTION_AUDIT_CREATED,
            'website_id' => $website->id,
            'audit_id' => $audit->id,
        ]);
    }
}
