<?php

namespace App\Services\Plan;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;

/**
 * Turns a caller into the Entitlements every gate reads (§40).
 *
 * ## Which plan applies
 *
 * 1. **Anonymous** → the guest tier from `config/entitlements.php`.
 * 2. **Active subscription** → that plan.
 * 3. **Lapsed subscription** (cancelled, expired, or past `ends_at`) → the
 *    **default plan**, flagged `isDowngraded`. A subscription ending is a
 *    normal lifecycle event, and locking the account out of the product
 *    entirely would be a worse answer than dropping it to free.
 * 4. **No subscription row at all** → null, and callers deny. This is an
 *    anomaly, not a lifecycle state: registration always creates one, so a
 *    user without it was made by a script or a partial failure. The system
 *    refuses to guess what they paid for rather than granting free terms
 *    to an account whose history it cannot see. (Pre-existing behaviour,
 *    kept deliberately — see UsageLimitService and ActiveAuditLimiter.)
 *
 * ## Deliberately not cached
 *
 * An earlier version memoized by user id. It was wrong, and provably so: a
 * resolver instance can outlive the entitlement state it cached — the same
 * object served two requests in a row and answered the second with the
 * plan the user had during the first, so an upgrade did not take effect.
 * The same hazard exists in any long-lived process (a queue worker, Octane)
 * and for a resolver handed two different users.
 *
 * Entitlements gate limits, so a stale answer is a wrong answer to a
 * security question. Two small indexed queries per resolution is the
 * correct trade.
 */
class EntitlementResolver
{
    /**
     * The caller's entitlements, or null when a registered user has no
     * subscription at all (case 4 above) — callers treat null as "deny".
     */
    public function for(?User $user): ?Entitlements
    {
        return $this->resolve($user);
    }

    /**
     * The same thing, but never null: a user with no subscription gets a
     * fully-denying set rather than a null the caller has to handle.
     * For read-only questions (what does the UI show, how much of a report
     * is visible) where "deny everything" is the right answer and a null
     * check adds nothing.
     */
    public function forOrDeny(?User $user): Entitlements
    {
        return $this->for($user) ?? $this->denyAll();
    }

    private function resolve(?User $user): ?Entitlements
    {
        if ($user === null) {
            return Entitlements::fromGuestConfig(config('entitlements.guest'));
        }

        // Queried, not read off `$user->subscription`. Eloquent caches a
        // loaded relation on the model instance, so a User handed to this
        // resolver after its plan changed would answer with the *old*
        // plan's limits — a stale entitlement is a wrong answer to a
        // security question, and the caller has no way to know its object
        // is out of date.
        $subscription = Subscription::where('user_id', $user->id)->first();

        if ($subscription === null) {
            return null;
        }

        if ($subscription->isActive()) {
            // Same reasoning as above: read the plan by key rather than
            // through a relation that may have been cached earlier.
            $plan = Plan::find($subscription->plan_id);

            // A subscription pointing at a deactivated plan is treated the
            // same as a lapsed one: the terms it names are no longer on
            // offer, so the account falls back to the default rather than
            // keeping terms an admin has withdrawn.
            if ($plan !== null && $plan->is_active) {
                return Entitlements::fromPlan($plan);
            }
        }

        $default = $this->defaultPlan();

        return $default === null ? null : Entitlements::fromPlan($default, isDowngraded: true);
    }

    /**
     * The plan an anonymous visitor's *audit categories* resolve against
     * and a lapsed subscriber falls back to.
     */
    public function defaultPlan(): ?Plan
    {
        return Plan::where('is_default', true)->where('is_active', true)->first();
    }

    /**
     * Everything denied, for a caller the system cannot place. Not a
     * "plan" — it exists so no gate has to branch on null.
     */
    private function denyAll(): Entitlements
    {
        return Entitlements::fromGuestConfig([
            'max_websites' => 0,
            'max_pages' => 0,
            'daily_audit_limit' => 0,
            'monthly_audit_limit' => 0,
            'concurrent_audit_limit' => 0,
            'max_audit_frequency' => 'monthly',
            'report_visibility_percent' => 0,
            'issues_per_service' => 0,
            'ai_analyses_per_audit' => 0,
            'aio_prompt_limit' => 0,
            'custom_aio_prompt_limit' => 0,
            'features' => [],
        ]);
    }
}
