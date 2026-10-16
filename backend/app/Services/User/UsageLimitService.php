<?php

namespace App\Services\User;

use App\Models\Audit;
use App\Models\UsageLog;
use App\Models\User;
use App\Models\Website;
use App\Services\Plan\EntitlementResolver;
use App\Services\Plan\PlanLimitExceeded;

/**
 * Volume limits: how many audits a caller may run per day and per month,
 * and how many websites they may keep.
 *
 * Every number comes from EntitlementResolver (§40) rather than being read
 * off `$user->plan()` here — that indirection is what makes an expired
 * subscription degrade to the default plan and a guest resolve to the
 * config tier without this class knowing about either case.
 *
 * Distinct from ActiveAuditLimiter, which caps what is *in flight* at one
 * instant. These cap totals over a period.
 */
class UsageLimitService
{
    private const ACTION_AUDIT_CREATED = 'audit_created';

    public function __construct(
        private readonly EntitlementResolver $entitlements,
    ) {}

    public function canCreateAudit(?User $user, string $ipAddress): bool
    {
        return $this->auditRefusalReason($user, $ipAddress) === null;
    }

    /**
     * Why this caller may not create an audit, or null if they may.
     *
     * Returns the reason rather than a bare boolean so the API can say
     * which allowance ran out — "you have used your 5 audits this month"
     * is actionable in a way that "limit reached" is not.
     */
    public function auditRefusalReason(?User $user, string $ipAddress): ?PlanLimitExceeded
    {
        $entitlements = $this->entitlements->for($user);

        if ($entitlements === null) {
            // A registered user with no subscription row at all — an
            // anomaly the resolver refuses to guess about (§40).
            return new PlanLimitExceeded(
                'Your account has no active plan. Contact support to restore it.',
                limit: 'subscription',
            );
        }

        if ($user === null) {
            // Anonymous: a lifetime cap keyed on IP, not a daily one (per
            // spec, "1 audit"), so this asks "ever" rather than "today".
            $used = UsageLog::where('ip_address', $ipAddress)
                ->where('action', self::ACTION_AUDIT_CREATED)
                ->count();

            return $used < $entitlements->dailyAuditLimit
                ? null
                : new PlanLimitExceeded(
                    'You have already used your free audit. Sign up to run more.',
                    limit: 'anonymous_audit_limit',
                    allowed: $entitlements->dailyAuditLimit,
                );
        }

        $today = UsageLog::where('user_id', $user->id)
            ->where('action', self::ACTION_AUDIT_CREATED)
            ->whereDate('created_at', now()->toDateString())
            ->count();

        if ($today >= $entitlements->dailyAuditLimit) {
            return new PlanLimitExceeded(
                'You have reached your daily audit limit for your current plan.',
                limit: 'daily_audit_limit',
                allowed: $entitlements->dailyAuditLimit,
            );
        }

        if ($entitlements->monthlyAuditLimit !== null) {
            // Calendar month, not a rolling 30 days: a plan sold as "20
            // audits a month" is understood to refill on the 1st, and a
            // rolling window would make the allowance impossible to reason
            // about from the dashboard.
            $thisMonth = UsageLog::where('user_id', $user->id)
                ->where('action', self::ACTION_AUDIT_CREATED)
                ->where('created_at', '>=', now()->startOfMonth())
                ->count();

            if ($thisMonth >= $entitlements->monthlyAuditLimit) {
                return PlanLimitExceeded::monthlyAudits($entitlements->monthlyAuditLimit);
            }
        }

        return null;
    }

    /**
     * A user the resolver cannot place (no subscription row) falls back to
     * the guest page limit rather than zero. Audit creation already blocks
     * them, so this only matters on a path that got past that check — and
     * a crawl bounded at the guest limit is a better failure than one
     * bounded at nothing.
     */
    public function maxPagesFor(?User $user): int
    {
        return $this->entitlements->for($user)?->maxPages
            ?? (int) config('entitlements.guest.max_pages');
    }

    /**
     * Why $user may not end up owning one more website, or null if they may.
     *
     * Guests are never blocked here: their `max_websites` is 0 because they
     * have no website listing, but an anonymous audit still creates a
     * bookkeeping `websites` row (§37) that nothing counts or shows.
     *
     * (A `canCreateWebsite()` wrapper sat above this until §56 — a second
     * way to ask the same question that nothing ever called. Two entry
     * points to one limit is how the two drift apart.)
     */
    public function websiteRefusalReason(?User $user): ?PlanLimitExceeded
    {
        if ($user === null) {
            return null;
        }

        $entitlements = $this->entitlements->forOrDeny($user);

        $owned = Website::where('user_id', $user->id)->count();

        return $owned < $entitlements->maxWebsites
            ? null
            : PlanLimitExceeded::websites($entitlements->maxWebsites);
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
