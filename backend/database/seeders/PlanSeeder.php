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
                'full_recrawls' => 1,
                'is_default' => true,
                'features' => ['audit_history' => false, 'pdf_export' => false, 'scheduled_audits' => false],
            ],
        );
    }
}
