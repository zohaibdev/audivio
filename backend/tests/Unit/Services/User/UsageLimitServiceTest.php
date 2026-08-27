<?php

namespace Tests\Unit\Services\User;

use App\Models\Audit;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageLog;
use App\Models\User;
use App\Models\Website;
use App\Services\User\UsageLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsageLimitServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserWithPlan(int $dailyAuditLimit = 1, int $maxPages = 25): User
    {
        $plan = Plan::create(['name' => 'Free', 'slug' => 'free-'.uniqid(), 'max_pages' => $maxPages, 'daily_audit_limit' => $dailyAuditLimit, 'is_default' => true]);
        $user = User::create(['name' => 'Test', 'email' => uniqid().'@example.com', 'password' => 'x']);
        Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'started_at' => now()]);

        return $user;
    }

    public function test_anonymous_with_no_prior_usage_can_create_an_audit(): void
    {
        $this->assertTrue((new UsageLimitService)->canCreateAudit(null, '203.0.113.1'));
    }

    public function test_anonymous_with_a_prior_audit_from_the_same_ip_cannot_create_another(): void
    {
        UsageLog::create(['ip_address' => '203.0.113.1', 'action' => 'audit_created']);

        $this->assertFalse((new UsageLimitService)->canCreateAudit(null, '203.0.113.1'));
    }

    public function test_anonymous_limit_is_scoped_per_ip(): void
    {
        UsageLog::create(['ip_address' => '203.0.113.1', 'action' => 'audit_created']);

        $this->assertTrue((new UsageLimitService)->canCreateAudit(null, '203.0.113.2'));
    }

    public function test_registered_user_under_their_daily_limit_can_create_an_audit(): void
    {
        $user = $this->makeUserWithPlan(dailyAuditLimit: 2);
        UsageLog::create(['user_id' => $user->id, 'action' => 'audit_created']);

        $this->assertTrue((new UsageLimitService)->canCreateAudit($user, '203.0.113.1'));
    }

    public function test_registered_user_at_their_daily_limit_cannot_create_another(): void
    {
        $user = $this->makeUserWithPlan(dailyAuditLimit: 1);
        UsageLog::create(['user_id' => $user->id, 'action' => 'audit_created']);

        $this->assertFalse((new UsageLimitService)->canCreateAudit($user, '203.0.113.1'));
    }

    public function test_registered_users_limit_resets_on_a_new_day(): void
    {
        $user = $this->makeUserWithPlan(dailyAuditLimit: 1);
        $log = UsageLog::create(['user_id' => $user->id, 'action' => 'audit_created']);
        $log->forceFill(['created_at' => now()->subDay()])->save();

        $this->assertTrue((new UsageLimitService)->canCreateAudit($user, '203.0.113.1'));
    }

    public function test_registered_user_with_no_subscription_cannot_create_an_audit(): void
    {
        $user = User::create(['name' => 'Test', 'email' => 'test@example.com', 'password' => 'x']);

        $this->assertFalse((new UsageLimitService)->canCreateAudit($user, '203.0.113.1'));
    }

    public function test_a_users_own_usage_does_not_affect_a_different_users_limit(): void
    {
        $userA = $this->makeUserWithPlan(dailyAuditLimit: 1);
        $userB = $this->makeUserWithPlan(dailyAuditLimit: 1);
        UsageLog::create(['user_id' => $userA->id, 'action' => 'audit_created']);

        $this->assertTrue((new UsageLimitService)->canCreateAudit($userB, '203.0.113.1'));
    }

    public function test_max_pages_for_anonymous_uses_config(): void
    {
        config(['audit_limits.anonymous_max_pages' => 5]);

        $this->assertSame(5, (new UsageLimitService)->maxPagesFor(null));
    }

    public function test_max_pages_for_registered_user_uses_their_plan(): void
    {
        $user = $this->makeUserWithPlan(maxPages: 25);

        $this->assertSame(25, (new UsageLimitService)->maxPagesFor($user));
    }

    public function test_max_pages_for_registered_user_with_no_subscription_falls_back_to_anonymous_limit(): void
    {
        config(['audit_limits.anonymous_max_pages' => 5]);
        $user = User::create(['name' => 'Test', 'email' => 'test@example.com', 'password' => 'x']);

        $this->assertSame(5, (new UsageLimitService)->maxPagesFor($user));
    }

    public function test_record_audit_created_persists_a_usage_log(): void
    {
        $user = $this->makeUserWithPlan();
        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'status' => 'pending', 'max_pages' => 5]);

        (new UsageLimitService)->recordAuditCreated($user, '203.0.113.1', $website, $audit);

        $this->assertDatabaseHas('usage_logs', [
            'user_id' => $user->id,
            'ip_address' => '203.0.113.1',
            'action' => 'audit_created',
            'website_id' => $website->id,
            'audit_id' => $audit->id,
        ]);
    }
}
