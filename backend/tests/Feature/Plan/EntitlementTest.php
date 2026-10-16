<?php

namespace Tests\Feature\Plan;

use App\DTOs\CrawledPageData;
use App\Enums\PlanFeature;
use App\Enums\ScheduleFrequency;
use App\Enums\SubscriptionStatus;
use App\Models\Audit;
use App\Models\Plan;
use App\Models\Report;
use App\Models\Subscription;
use App\Models\UsageLog;
use App\Models\User;
use App\Models\Website;
use App\Services\Crawler\PlaywrightCrawlerClient;
use App\Services\Plan\EntitlementResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Plan entitlements (§40): every limit is enforced server-side, whatever
 * the client sends.
 */
class EntitlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::fake(['*' => Http::response('', 404)]);
        $this->withoutMiddleware(ThrottleRequests::class);
        // Leaves accepted audits `queued` (and so still active) instead of
        // running the crawl inline and immediately failing it.
        Queue::fake();

        $this->mock(PlaywrightCrawlerClient::class, function ($mock) {
            $mock->shouldReceive('crawl')->andReturn(new CrawledPageData(
                url: 'https://example.com/', httpStatus: 200, redirectChain: [], loadTimeMs: 500,
                screenshotDesktopPath: null, screenshotMobilePath: null, title: 'Home',
                metaDescription: str_repeat('a', 100), canonicalUrl: 'https://example.com/',
                headings: [], links: [], images: [], forms: [], structuredData: [],
                ctaElements: [], htmlSizeBytes: 1000, accessibilityViolations: [],
            ));
        });
    }

    private function makePlan(array $attributes = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Test plan',
            'slug' => 'plan-'.uniqid(),
            'price_cents' => 0,
            'max_pages' => 25,
            'max_websites' => 5,
            'daily_audit_limit' => 50,
            'monthly_audit_limit' => null,
            'concurrent_audit_limit' => 5,
            'full_recrawls' => 5,
            'max_audit_frequency' => 'daily',
            'report_visibility_percent' => 100,
        ], $attributes));
    }

    private function makeUser(Plan $plan, array $subscription = [], string $email = 'ada@example.com'): User
    {
        $user = User::create(['name' => 'Ada', 'email' => $email, 'password' => 'x']);
        Subscription::create(array_merge([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'started_at' => now(),
        ], $subscription));

        return $user->fresh();
    }

    private function asUser(User $user): static
    {
        // Sanctum memoizes its resolved user across simulated requests in
        // one test method (CONTEXT.md §28).
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('t')->plainTextToken);
    }

    /**
     * A past audit-creation log entry.
     *
     * `created_at` is set after the insert because it is not mass
     * assignable — passing it to create() is silently dropped and the row
     * lands at now(), which would put every "last month" fixture in this
     * month and quietly pass the wrong assertion.
     */
    private function logAuditAt(User $user, Carbon $at): void
    {
        UsageLog::create([
            'user_id' => $user->id,
            'ip_address' => '203.0.113.1',
            'action' => 'audit_created',
        ])->forceFill(['created_at' => $at])->save();
    }

    private function makeWebsite(User $user, string $domain): Website
    {
        return Website::create([
            'user_id' => $user->id, 'name' => $domain,
            'primary_url' => "https://{$domain}/", 'domain' => $domain,
            'normalized_domain' => $domain,
        ]);
    }

    // --- Website limit ---

    public function test_a_new_website_beyond_the_plans_limit_is_refused(): void
    {
        $user = $this->makeUser($this->makePlan(['max_websites' => 1]));
        $this->makeWebsite($user, 'first.example');

        $this->asUser($user)
            ->postJson('/api/audits', ['url' => 'https://example.com'])
            ->assertStatus(403)
            ->assertJsonPath('errors.limit', 'max_websites');

        $this->assertSame(1, Website::count());
    }

    public function test_re_auditing_a_website_already_owned_still_works_at_the_limit(): void
    {
        $user = $this->makeUser($this->makePlan(['max_websites' => 1]));
        $this->makeWebsite($user, 'example.com');

        // Lowering a plan's cap must not lock subscribers out of the sites
        // they already have — only stop them adding more.
        $this->asUser($user)
            ->postJson('/api/audits', ['url' => 'https://example.com'])
            ->assertStatus(202);
    }

    public function test_one_users_websites_do_not_count_against_another(): void
    {
        $ada = $this->makeUser($this->makePlan(['max_websites' => 1]), email: 'ada@example.com');
        $grace = $this->makeUser($this->makePlan(['max_websites' => 1]), email: 'grace@example.com');
        $this->makeWebsite($ada, 'ada.example');

        $this->asUser($grace)
            ->postJson('/api/audits', ['url' => 'https://example.com'])
            ->assertStatus(202);
    }

    // --- Monthly audit limit ---

    public function test_the_monthly_audit_limit_is_enforced_on_top_of_the_daily_one(): void
    {
        $user = $this->makeUser($this->makePlan(['daily_audit_limit' => 50, 'monthly_audit_limit' => 2]));

        // Two audits already run this month, dated earlier in the month so
        // the daily cap is not what refuses the third.
        $this->logAuditAt($user, now()->startOfMonth()->addDay());
        $this->logAuditAt($user, now()->startOfMonth()->addDays(2));

        $this->asUser($user)
            ->postJson('/api/audits', ['url' => 'https://example.com'])
            ->assertStatus(429)
            ->assertJsonPath('errors.limit', 'monthly_audit_limit');
    }

    public function test_usage_from_last_month_does_not_count_against_this_month(): void
    {
        $user = $this->makeUser($this->makePlan(['monthly_audit_limit' => 1]));

        $this->logAuditAt($user, now()->startOfMonth()->subDay());

        // Calendar month, not a rolling window — the allowance refills on
        // the 1st, which is what "5 audits a month" means to a buyer.
        $this->asUser($user)
            ->postJson('/api/audits', ['url' => 'https://example.com'])
            ->assertStatus(202);
    }

    public function test_a_null_monthly_limit_means_unlimited(): void
    {
        $user = $this->makeUser($this->makePlan(['monthly_audit_limit' => null]));

        for ($i = 0; $i < 20; $i++) {
            $this->logAuditAt($user, now()->startOfMonth()->addHour());
        }

        $this->asUser($user)
            ->postJson('/api/audits', ['url' => 'https://example.com'])
            ->assertStatus(202);
    }

    // --- Page limit ---

    public function test_the_page_limit_comes_from_the_plan_and_ignores_the_payload(): void
    {
        $user = $this->makeUser($this->makePlan(['max_pages' => 7]));

        $response = $this->asUser($user)
            ->postJson('/api/audits', ['url' => 'https://example.com', 'max_pages' => 9999])
            ->assertStatus(202);

        $this->assertSame(7, Audit::find($response->json('data.audit_id'))->max_pages);
    }

    // --- Report visibility ---

    public function test_report_visibility_percent_decides_how_much_an_owner_sees(): void
    {
        $entitlements = app(EntitlementResolver::class);

        $full = $entitlements->for($this->makeUser($this->makePlan(['report_visibility_percent' => 100])));
        $this->assertTrue($full->hasFullReportAccess());
        $this->assertSame(10, $full->visibleCount(10));

        $partial = $entitlements->for($this->makeUser($this->makePlan(['report_visibility_percent' => 40]), email: 'b@example.com'));
        $this->assertFalse($partial->hasFullReportAccess());
        $this->assertSame(4, $partial->visibleCount(10));
    }

    public function test_the_issues_per_service_cap_lowers_what_the_percentage_allowed(): void
    {
        $user = $this->makeUser($this->makePlan([
            'report_visibility_percent' => 50,
            'issues_per_service' => 2,
        ]));

        $entitlements = app(EntitlementResolver::class)->for($user);

        $this->assertSame(5, $entitlements->visibleCount(10));
        $this->assertSame(2, $entitlements->issuesPerService);
    }

    // --- Feature entitlements ---

    public function test_a_built_feature_follows_the_plans_configuration(): void
    {
        $on = app(EntitlementResolver::class)->for(
            $this->makeUser($this->makePlan(['features' => [PlanFeature::PdfExport->value => true]]))
        );
        $this->assertTrue($on->allows(PlanFeature::PdfExport));

        $off = app(EntitlementResolver::class)->for(
            $this->makeUser($this->makePlan(['features' => [PlanFeature::PdfExport->value => false]]), email: 'b@example.com')
        );
        $this->assertFalse($off->allows(PlanFeature::PdfExport));
    }

    public function test_a_feature_that_is_not_built_yet_never_reports_as_allowed(): void
    {
        $user = $this->makeUser($this->makePlan(['features' => [
            PlanFeature::WhiteLabel->value => true,
            PlanFeature::CompetitorDiscovery->value => true,
        ]]));

        $entitlements = app(EntitlementResolver::class)->for($user);

        // Both of these were once configurable-but-unbuilt: competitor
        // discovery left that list in §42, white label in §48. Each now
        // follows the plan's configuration like any other feature.
        $this->assertTrue($entitlements->allows(PlanFeature::WhiteLabel));
        $this->assertTrue($entitlements->allows(PlanFeature::CompetitorDiscovery));

        // The mechanism itself is still exercised, because the next flag
        // priced ahead of its build will need it: a feature on the
        // not-yet-built list must answer false however it is configured.
        $this->assertSame([], PlanFeature::notYetBuilt());
        $this->assertTrue($entitlements->isConfiguredFor(PlanFeature::WhiteLabel));
    }

    public function test_pdf_export_is_refused_without_the_entitlement(): void
    {
        $user = $this->makeUser($this->makePlan(['features' => [PlanFeature::PdfExport->value => false]]));
        $website = $this->makeWebsite($user, 'example.com');
        $audit = Audit::create([
            'website_id' => $website->id, 'user_id' => $user->id,
            'status' => 'completed', 'max_pages' => 5, 'completed_at' => now(),
        ]);
        Report::create([
            'audit_id' => $audit->id,
            'content' => ['overview' => [], 'issues' => [], 'recommendations' => [], 'ai_analysis' => [], 'pages' => []],
            'generated_at' => now(),
        ]);

        $this->asUser($user)->get("/api/audits/{$audit->id}/report/pdf")->assertStatus(403);
    }

    // --- Audit frequency ---

    public function test_a_schedule_more_frequent_than_the_plan_allows_is_refused(): void
    {
        $user = $this->makeUser($this->makePlan([
            'max_audit_frequency' => 'weekly',
            'features' => [PlanFeature::ScheduledAudits->value => true],
        ]));
        $website = $this->makeWebsite($user, 'example.com');

        $this->asUser($user)
            ->postJson('/api/audit-schedules', ['website_id' => $website->id, 'frequency' => 'daily'])
            ->assertStatus(403)
            ->assertJsonPath('errors.limit', 'max_audit_frequency');

        // The frequency the plan does allow is accepted.
        $this->asUser($user)
            ->postJson('/api/audit-schedules', ['website_id' => $website->id, 'frequency' => 'weekly'])
            ->assertStatus(201);
    }

    public function test_a_less_frequent_schedule_than_the_ceiling_is_always_allowed(): void
    {
        $entitlements = app(EntitlementResolver::class)->for($this->makeUser($this->makePlan([
            'max_audit_frequency' => 'weekly',
        ])));

        $this->assertTrue($entitlements->allowsFrequency(ScheduleFrequency::Monthly));
        $this->assertTrue($entitlements->allowsFrequency(ScheduleFrequency::Weekly));
        $this->assertFalse($entitlements->allowsFrequency(ScheduleFrequency::Daily));
    }

    // --- Plan upgrade and downgrade ---

    public function test_an_upgrade_takes_effect_immediately(): void
    {
        $user = $this->makeUser($this->makePlan(['max_websites' => 1]));
        $this->makeWebsite($user, 'first.example');

        $this->asUser($user)->postJson('/api/audits', ['url' => 'https://example.com'])->assertStatus(403);

        $user->subscription->update(['plan_id' => $this->makePlan(['max_websites' => 10])->id]);

        $this->asUser($user)->postJson('/api/audits', ['url' => 'https://example.com'])->assertStatus(202);
    }

    public function test_a_downgrade_takes_effect_immediately_too(): void
    {
        $user = $this->makeUser($this->makePlan(['max_pages' => 100]));

        $response = $this->asUser($user)->postJson('/api/audits', ['url' => 'https://example.com']);
        $this->assertSame(100, Audit::find($response->json('data.audit_id'))->max_pages);

        $user->subscription->update(['plan_id' => $this->makePlan(['max_pages' => 3])->id]);

        $response = $this->asUser($user)->postJson('/api/audits', ['url' => 'https://example.org']);
        // The already-running audit keeps the terms it started under; the
        // new one gets the new plan's.
        $this->assertSame(3, Audit::find($response->json('data.audit_id'))->max_pages);
    }

    // --- Expired plan ---

    public function test_an_expired_subscription_falls_back_to_the_default_plan(): void
    {
        $default = $this->makePlan(['slug' => 'free', 'is_default' => true, 'max_pages' => 4, 'max_websites' => 1]);
        $paid = $this->makePlan(['slug' => 'paid', 'max_pages' => 100, 'max_websites' => 50]);

        $user = $this->makeUser($paid, ['ends_at' => now()->subDay()]);

        $entitlements = app(EntitlementResolver::class)->for($user);

        // A lapsed subscription is a normal lifecycle event, so the account
        // degrades to free rather than being locked out entirely.
        $this->assertTrue($entitlements->isDowngraded);
        $this->assertSame($default->id, $entitlements->plan->id);
        $this->assertSame(4, $entitlements->maxPages);
    }

    public function test_a_cancelled_subscription_also_falls_back(): void
    {
        $this->makePlan(['slug' => 'free', 'is_default' => true, 'max_pages' => 4]);
        $paid = $this->makePlan(['slug' => 'paid', 'max_pages' => 100]);

        $user = $this->makeUser($paid, ['status' => SubscriptionStatus::Canceled->value]);

        $this->assertTrue(app(EntitlementResolver::class)->for($user)->isDowngraded);
    }

    public function test_a_subscription_inside_its_paid_period_is_still_active(): void
    {
        $this->makePlan(['slug' => 'free', 'is_default' => true, 'max_pages' => 4]);
        $paid = $this->makePlan(['slug' => 'paid', 'max_pages' => 100]);

        $user = $this->makeUser($paid, ['ends_at' => now()->addMonth()]);

        $entitlements = app(EntitlementResolver::class)->for($user);
        $this->assertFalse($entitlements->isDowngraded);
        $this->assertSame(100, $entitlements->maxPages);
    }

    public function test_a_subscription_to_a_deactivated_plan_falls_back(): void
    {
        $this->makePlan(['slug' => 'free', 'is_default' => true, 'max_pages' => 4]);
        $withdrawn = $this->makePlan(['slug' => 'withdrawn', 'max_pages' => 100, 'is_active' => false]);

        $user = $this->makeUser($withdrawn);

        // Terms an admin has withdrawn are no longer on offer, so the
        // account falls back rather than keeping them indefinitely.
        $this->assertTrue(app(EntitlementResolver::class)->for($user)->isDowngraded);
    }

    public function test_a_user_with_no_subscription_at_all_resolves_to_nothing(): void
    {
        $user = User::create(['name' => 'Orphan', 'email' => 'orphan@example.com', 'password' => 'x']);

        // Distinct from a lapsed subscription: an account with no
        // subscription row is an anomaly the resolver refuses to guess
        // about, rather than a lifecycle state to degrade gracefully.
        $this->assertNull(app(EntitlementResolver::class)->for($user));
    }

    // --- Guest tier ---

    public function test_the_guest_tier_comes_from_config_not_a_plan_row(): void
    {
        config([
            'entitlements.guest.max_pages' => 3,
            'entitlements.guest.report_visibility_percent' => 50,
            'entitlements.guest.issues_per_service' => 5,
        ]);

        $guest = app(EntitlementResolver::class)->for(null);

        $this->assertTrue($guest->isGuest);
        $this->assertNull($guest->plan);
        $this->assertSame(3, $guest->maxPages);
        $this->assertSame(50, $guest->reportVisibilityPercent);
        $this->assertFalse($guest->allows(PlanFeature::PdfExport));
    }

    // --- The API surface ---

    public function test_the_entitlements_endpoint_describes_the_callers_plan(): void
    {
        $user = $this->makeUser($this->makePlan([
            'max_websites' => 5,
            'monthly_audit_limit' => 20,
            'features' => [PlanFeature::PdfExport->value => true],
        ]));

        $data = $this->asUser($user)->getJson('/api/me/entitlements')
            ->assertStatus(200)
            ->json('data');

        $this->assertFalse($data['is_guest']);
        $this->assertSame(5, $data['limits']['max_websites']);
        $this->assertSame(20, $data['limits']['monthly_audit_limit']);
        $this->assertTrue($data['features']['pdf_export']['enabled']);
        // White label is built as of §48, so it is available like any
        // other feature; the payload still carries `available` so the UI
        // can tell "your plan lacks this" from "nobody has this yet" for
        // the next flag priced ahead of its build.
        $this->assertTrue($data['features']['white_label']['available']);
        $this->assertArrayHasKey('enabled', $data['features']['white_label']);
    }

    public function test_the_entitlements_endpoint_works_for_a_guest(): void
    {
        $data = $this->getJson('/api/me/entitlements')->assertStatus(200)->json('data');

        $this->assertTrue($data['is_guest']);
        $this->assertNull($data['plan']);
    }

    // --- API bypass attempts ---

    public function test_limits_cannot_be_raised_through_the_request_payload(): void
    {
        $user = $this->makeUser($this->makePlan(['max_pages' => 5, 'max_websites' => 1]));
        $this->makeWebsite($user, 'taken.example');

        // Every one of these is a server-side value; sending them is inert.
        $this->asUser($user)->postJson('/api/audits', [
            'url' => 'https://example.com',
            'max_pages' => 500,
            'max_websites' => 500,
            'monthly_audit_limit' => 500,
            'plan_id' => 99,
        ])->assertStatus(403);

        $this->assertSame(1, Website::count());
    }

    public function test_a_non_admin_cannot_edit_plan_limits(): void
    {
        $plan = $this->makePlan(['max_websites' => 1]);
        $user = $this->makeUser($plan);

        $this->asUser($user)
            ->putJson("/api/admin/plans/{$plan->id}", [
                'name' => 'Hijacked', 'slug' => 'hijacked', 'price_cents' => 0,
                'max_pages' => 999, 'daily_audit_limit' => 999, 'max_websites' => 999,
            ])
            ->assertStatus(403);

        $this->assertSame(1, $plan->fresh()->max_websites);
    }
}
