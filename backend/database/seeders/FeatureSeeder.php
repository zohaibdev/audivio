<?php

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\Plan;
use Illuminate\Database\Seeder;

class FeatureSeeder extends Seeder
{
    /**
     * The 7 admin-facing audit features from the product spec. Every
     * existing plan gets all 7 enabled by default (see
     * PlanFeatureBackfillSeeder-equivalent logic below) — most audits ran
     * unconditionally before this feature registry existed, so seeding
     * anything less than "everything on" would silently remove behavior
     * every current plan already had.
     */
    private const FEATURES = [
        ['key' => 'performance', 'name' => 'Page Speed / Performance', 'description' => 'Website performance and page speed analysis'],
        ['key' => 'accessibility', 'name' => 'Accessibility / ADA', 'description' => 'Accessibility and ADA compliance analysis'],
        ['key' => 'cro', 'name' => 'Conversion Rate (CRO)', 'description' => 'Conversion rate optimization analysis'],
        ['key' => 'seo', 'name' => 'SEO', 'description' => 'Search engine optimization analysis'],
        ['key' => 'aio', 'name' => 'AI Search Readiness (AIO)', 'description' => 'AI search and AI visibility/readiness analysis'],
        ['key' => 'ecommerce', 'name' => 'AI Ecommerce / Landing Auditor', 'description' => 'AI-powered ecommerce and landing page analysis'],
        ['key' => 'product_auditor', 'name' => 'AI Product Auditor', 'description' => 'AI-powered product page analysis'],
    ];

    public function run(): void
    {
        foreach (self::FEATURES as $feature) {
            $row = Feature::updateOrCreate(['key' => $feature['key']], [
                'name' => $feature['name'],
                'description' => $feature['description'],
                'is_enabled' => true,
            ]);

            foreach (Plan::all() as $plan) {
                $plan->auditFeatures()->syncWithoutDetaching([$row->id => ['is_enabled' => true]]);
            }
        }
    }
}
