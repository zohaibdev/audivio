<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Seeds only the default free plan — the spec's paid tiers ("higher
     * page limits, full reports, PDF export, audit history, scheduled
     * audits, advanced AI analysis, daily audits") don't specify concrete
     * numbers, and "complex billing" is an explicit MVP exclusion. Admin
     * CRUD for plans (Phase 7) is how paid tiers get added when pricing
     * is actually decided, not invented here.
     */
    public function run(): void
    {
        Plan::updateOrCreate(
            ['slug' => 'free'],
            [
                'name' => 'Free',
                'price_cents' => 0,
                'max_pages' => 25,
                'daily_audit_limit' => 1,
                'monthly_audit_limit' => 5,
                // One audit in flight at a time on the free plan (§39).
                // Paid tiers raise this through admin plan CRUD; it is a
                // plain column so no code change is needed to do so.
                'concurrent_audit_limit' => 1,
                'full_recrawls' => 1,
                // The free tier's entitlements (§40). Paid tiers are
                // created through admin plan CRUD once pricing is decided
                // — still not invented here (see the note above).
                'max_websites' => 1,
                'max_audit_frequency' => 'monthly',
                // Signing up unlocks the full report today; that stays
                // true, and the knob exists for tiers priced later (§40).
                'report_visibility_percent' => 100,
                'issues_per_service' => null,
                'ai_analyses_per_audit' => 3,
                'aio_prompt_limit' => 1,
                // The spec's free tier: one custom AIO prompt (section 42).
                'custom_aio_prompt_limit' => 1,
                'is_default' => true,
                'features' => ['audit_history' => false, 'pdf_export' => false, 'scheduled_audits' => false],
            ],
        );
    }
}
