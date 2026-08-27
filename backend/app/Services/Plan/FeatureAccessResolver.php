<?php

namespace App\Services\Plan;

use App\Enums\AuditCategory;
use App\Models\Plan;
use App\Models\User;

/**
 * The single place that answers "is this audit category / feature key
 * allowed for this user (or anonymous visitor)". Used by
 * AuditModuleRegistry (which modules run), the /api/me/features endpoint,
 * and anywhere else that needs the same answer — do not re-derive this
 * logic elsewhere.
 */
class FeatureAccessResolver
{
    /**
     * AuditCategory value => features.key. Two categories (ecommerce,
     * landing_page) share the "ecommerce" feature key per the product
     * spec's "AI Ecommerce / Landing Auditor" grouping — disabling it
     * skips both modules together. AuditCategory::Overall is a derived
     * rollup, never itself gated. `product_auditor` has no AuditCategory
     * yet (no audit module exists for it — see CONTEXT.md §29); it's
     * registered so admins can toggle its future availability per plan.
     */
    private const CATEGORY_FEATURE_MAP = [
        'performance' => 'performance',
        'accessibility' => 'accessibility',
        'seo' => 'seo',
        'aio' => 'aio',
        'conversion' => 'cro',
        'ecommerce' => 'ecommerce',
        'landing_page' => 'ecommerce',
    ];

    public function planFor(?User $user): ?Plan
    {
        return $user?->plan() ?? Plan::where('is_default', true)->where('is_active', true)->first();
    }

    public function categoryAllowed(?User $user, AuditCategory $category): bool
    {
        $featureKey = self::CATEGORY_FEATURE_MAP[$category->value] ?? null;

        if ($featureKey === null) {
            return true;
        }

        return $this->featureAllowed($user, $featureKey);
    }

    public function featureAllowed(?User $user, string $featureKey): bool
    {
        $plan = $this->planFor($user);

        // No plan exists at all — not "this plan restricts the feature."
        // Nothing to gate against yet (a fresh/test DB with no PlanSeeder
        // run, or a registered user's subscription somehow missing, which
        // UsageLimitService::canCreateAudit() already blocks at audit
        // creation, making this branch unreachable for that case in
        // practice) — treat as unrestricted rather than denying every
        // audit module outright. Once a default plan exists (the normal
        // production state), gating always applies, including to
        // anonymous visitors, who resolve to that default plan.
        if ($plan === null) {
            return true;
        }

        return $plan->hasAuditFeature($featureKey);
    }

    /**
     * @return AuditCategory[]
     */
    public function allowedCategories(?User $user): array
    {
        return array_values(array_filter(
            AuditCategory::cases(),
            fn (AuditCategory $category) => $category !== AuditCategory::Overall && $this->categoryAllowed($user, $category),
        ));
    }
}
