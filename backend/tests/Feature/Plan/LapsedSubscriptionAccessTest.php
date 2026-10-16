<?php

namespace Tests\Feature\Plan;

use App\Enums\AuditCategory;
use App\Enums\ScheduleFrequency;
use App\Models\Audit;
use App\Models\AuditSchedule;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;
use App\Services\Plan\FeatureAccessResolver;
use App\Services\Schedule\AuditSchedulerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * What a lapsed subscriber still gets (§40).
 *
 * Every path that answers "how much is this account entitled to" has to
 * go through EntitlementResolver. Two did not, and both failed the same
 * way: they read `$user->plan()`, a bare relation that says nothing about
 * whether the subscription is still active or the plan still on offer.
 *
 * Both were in places nobody watches — a feature resolver consulted deep
 * inside audit setup, and a background scheduler — so the account kept
 * paid terms indefinitely with nothing in any report to show it.
 */
class LapsedSubscriptionAccessTest extends TestCase
{
    use RefreshDatabase;

    private Plan $freePlan;

    private Plan $paidPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freePlan = Plan::create([
            'name' => 'Free', 'slug' => 'free', 'price_cents' => 0,
            'max_pages' => 5, 'daily_audit_limit' => 1,
            'report_visibility_percent' => 30, 'is_default' => true, 'is_active' => true,
        ]);

        $this->paidPlan = Plan::create([
            'name' => 'Pro', 'slug' => 'pro', 'price_cents' => 4900,
            'max_pages' => 100, 'daily_audit_limit' => 20, 'full_recrawls' => 5,
            'report_visibility_percent' => 100, 'is_active' => true,
        ]);
    }

    private function userWith(Plan $plan, array $subscription = []): User
    {
        $user = User::create([
            'name' => 'Subscriber',
            'email' => 'sub'.uniqid().'@example.com',
            'password' => 'x',
        ]);

        Subscription::create(array_merge([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'started_at' => now()->subMonths(6),
        ], $subscription));

        return $user->fresh();
    }

    // --- Feature access ---

    public function test_an_expired_subscription_loses_its_plans_feature_access(): void
    {
        $lapsed = $this->userWith($this->paidPlan, ['ends_at' => now()->subDay()]);

        // The resolver must answer from the default plan, not from the
        // plan the expired subscription still points at.
        $this->assertSame(
            $this->freePlan->id,
            app(FeatureAccessResolver::class)->planFor($lapsed)?->id,
        );
    }

    public function test_an_active_subscription_keeps_its_plan(): void
    {
        $active = $this->userWith($this->paidPlan, ['ends_at' => now()->addMonth()]);

        $this->assertSame(
            $this->paidPlan->id,
            app(FeatureAccessResolver::class)->planFor($active)?->id,
        );
    }

    public function test_a_subscription_to_a_withdrawn_plan_falls_back_too(): void
    {
        $user = $this->userWith($this->paidPlan);
        $this->paidPlan->update(['is_active' => false]);

        // Terms an admin has withdrawn are not terms the account keeps.
        $this->assertSame(
            $this->freePlan->id,
            app(FeatureAccessResolver::class)->planFor($user->fresh())?->id,
        );
    }

    public function test_a_guest_still_resolves_to_the_default_plan(): void
    {
        $this->assertSame(
            $this->freePlan->id,
            app(FeatureAccessResolver::class)->planFor(null)?->id,
        );
    }

    public function test_category_gating_follows_the_resolved_plan(): void
    {
        $resolver = app(FeatureAccessResolver::class);
        $lapsed = $this->userWith($this->paidPlan, ['ends_at' => now()->subDay()]);

        // Whatever the answer is, it must be the same answer the default
        // plan gives — the lapsed account is on the default plan now.
        $this->assertSame(
            $resolver->categoryAllowed(null, AuditCategory::Aio),
            $resolver->categoryAllowed($lapsed, AuditCategory::Aio),
        );
    }

    // --- Scheduled audits ---

    public function test_a_lapsed_subscribers_scheduled_audit_uses_the_fallback_page_limit(): void
    {
        $user = $this->userWith($this->paidPlan, ['ends_at' => now()->subDay()]);

        $website = Website::create([
            'user_id' => $user->id, 'primary_url' => 'https://example.com/',
            'domain' => 'example.com', 'normalized_domain' => 'example.com',
        ]);

        AuditSchedule::create([
            'user_id' => $user->id,
            'website_id' => $website->id,
            'frequency' => ScheduleFrequency::Daily,
            'is_active' => true,
            'next_run_at' => now()->subMinute(),
        ]);

        // The audit row is what this asserts on; running the crawl
        // pipeline inline would make the test slow and network-dependent
        // for no extra coverage.
        Queue::fake();
        app(AuditSchedulerService::class)->runDue();

        $audit = Audit::where('user_id', $user->id)->first();

        // 5, the default plan's limit — not 100, the plan the expired
        // subscription still names. A schedule fires forever, so getting
        // this wrong hands out paid crawl budget indefinitely.
        $this->assertNotNull($audit, 'the due schedule should still run');
        $this->assertSame($this->freePlan->max_pages, $audit->max_pages);
    }

    public function test_an_active_subscribers_scheduled_audit_keeps_the_paid_page_limit(): void
    {
        $user = $this->userWith($this->paidPlan, ['ends_at' => now()->addMonth()]);

        $website = Website::create([
            'user_id' => $user->id, 'primary_url' => 'https://example.com/',
            'domain' => 'example.com', 'normalized_domain' => 'example.com',
        ]);

        AuditSchedule::create([
            'user_id' => $user->id,
            'website_id' => $website->id,
            'frequency' => ScheduleFrequency::Daily,
            'is_active' => true,
            'next_run_at' => now()->subMinute(),
        ]);

        // The audit row is what this asserts on; running the crawl
        // pipeline inline would make the test slow and network-dependent
        // for no extra coverage.
        Queue::fake();
        app(AuditSchedulerService::class)->runDue();

        $this->assertSame(
            $this->paidPlan->max_pages,
            Audit::where('user_id', $user->id)->first()?->max_pages,
        );
    }
}
