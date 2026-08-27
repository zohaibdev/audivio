<?php

namespace Tests\Unit\Services\Plan;

use App\Enums\AuditCategory;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Plan\FeatureAccessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureAccessResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): FeatureAccessResolver
    {
        return app(FeatureAccessResolver::class);
    }

    public function test_anonymous_visitor_resolves_to_the_active_default_plan(): void
    {
        Plan::create(['name' => 'Free', 'slug' => 'free', 'max_pages' => 25, 'daily_audit_limit' => 1, 'is_default' => true, 'is_active' => true]);
        Plan::create(['name' => 'Pro', 'slug' => 'pro', 'max_pages' => 100, 'daily_audit_limit' => 10]);

        $plan = $this->resolver()->planFor(null);

        $this->assertSame('free', $plan->slug);
    }

    public function test_an_inactive_default_plan_is_not_resolved(): void
    {
        Plan::create(['name' => 'Retired Free', 'slug' => 'retired', 'max_pages' => 25, 'daily_audit_limit' => 1, 'is_default' => true, 'is_active' => false]);

        $this->assertNull($this->resolver()->planFor(null));
    }

    public function test_a_registered_user_resolves_to_their_own_subscribed_plan_not_the_default(): void
    {
        Plan::create(['name' => 'Free', 'slug' => 'free', 'max_pages' => 25, 'daily_audit_limit' => 1, 'is_default' => true, 'is_active' => true]);
        $pro = Plan::create(['name' => 'Pro', 'slug' => 'pro', 'max_pages' => 100, 'daily_audit_limit' => 10]);
        $user = User::create(['name' => 'User', 'email' => 'user@example.com', 'password' => 'x']);
        Subscription::create(['user_id' => $user->id, 'plan_id' => $pro->id, 'started_at' => now()]);

        $this->assertSame('pro', $this->resolver()->planFor($user)->slug);
    }

    public function test_no_plan_at_all_in_the_system_is_treated_as_unrestricted(): void
    {
        // No PlanSeeder run — a fresh/test DB with zero plans. Gating must
        // not deny every audit module in this bootstrap state (§31).
        $this->assertTrue($this->resolver()->featureAllowed(null, 'seo'));
        $this->assertTrue($this->resolver()->categoryAllowed(null, AuditCategory::Seo));
    }

    public function test_ecommerce_feature_key_gates_both_ecommerce_and_landing_page_categories(): void
    {
        $plan = Plan::create(['name' => 'Free', 'slug' => 'free', 'max_pages' => 25, 'daily_audit_limit' => 1, 'is_default' => true, 'is_active' => true]);
        $feature = Feature::create(['key' => 'ecommerce', 'name' => 'Ecommerce', 'is_enabled' => true]);
        $plan->auditFeatures()->attach($feature->id, ['is_enabled' => true]);

        $this->assertTrue($this->resolver()->categoryAllowed(null, AuditCategory::Ecommerce));
        $this->assertTrue($this->resolver()->categoryAllowed(null, AuditCategory::LandingPage));
    }

    public function test_overall_is_never_gated(): void
    {
        Plan::create(['name' => 'Free', 'slug' => 'free', 'max_pages' => 25, 'daily_audit_limit' => 1, 'is_default' => true, 'is_active' => true]);

        $this->assertTrue($this->resolver()->categoryAllowed(null, AuditCategory::Overall));
    }

    public function test_allowed_categories_excludes_overall_and_reflects_the_plans_features(): void
    {
        $plan = Plan::create(['name' => 'Free', 'slug' => 'free', 'max_pages' => 25, 'daily_audit_limit' => 1, 'is_default' => true, 'is_active' => true]);
        $seo = Feature::create(['key' => 'seo', 'name' => 'SEO', 'is_enabled' => true]);
        $plan->auditFeatures()->attach($seo->id, ['is_enabled' => true]);

        $allowed = $this->resolver()->allowedCategories(null);

        $this->assertContains(AuditCategory::Seo, $allowed);
        $this->assertNotContains(AuditCategory::Overall, $allowed);
        $this->assertNotContains(AuditCategory::Performance, $allowed);
    }
}
