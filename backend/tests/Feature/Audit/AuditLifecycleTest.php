<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditStatus;
use App\Models\Audit;
use App\Models\AuditScore;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageLog;
use App\Models\User;
use App\Models\Website;
use App\Services\Audit\ActiveAuditLimiter;
use App\Services\Audit\AuditLifecycle;
use App\Services\Audit\AuditModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Concurrent-audit limits, slot release, and cancellation.
 *
 * The limit is enforced by *deriving* "in flight" from `audits.status`
 * rather than tracking a counter, so most of these tests are really
 * asserting one property: an audit holds a slot exactly while its status
 * is non-terminal, on every path that can reach a terminal state.
 */
class AuditLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The concurrency limit is the subject here; the per-minute
        // request throttle would otherwise cut the multi-request tests
        // short before they reach it.
        $this->withoutMiddleware(ThrottleRequests::class);

        // Without this the `sync` driver runs CrawlWebsiteJob inline, the
        // crawl immediately fails (no browser in tests), and the audit
        // reaches `failed` before the next request is made — releasing the
        // very slot these tests are checking is held. Faking the queue
        // leaves each accepted audit `queued`, which is what a real
        // deployment looks like the instant after acceptance.
        Queue::fake();
    }

    private function makeUser(int $concurrent = 1, string $email = 'ada@example.com'): User
    {
        $plan = Plan::create([
            'name' => "Plan {$email}",
            'slug' => 'plan-'.md5($email),
            'price_cents' => 0,
            'max_pages' => 25,
            // High on purpose: the daily cap is a different limit with its
            // own tests, and would otherwise mask the one under test.
            'daily_audit_limit' => 100,
            'monthly_audit_limit' => null,
            'max_websites' => 50,
            'concurrent_audit_limit' => $concurrent,
            'is_default' => false,
        ]);

        $user = User::create(['name' => 'Ada', 'email' => $email, 'password' => 'x']);
        Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'started_at' => now()]);

        return $user;
    }

    private function asUser(User $user): static
    {
        // Sanctum's RequestGuard memoizes its resolved user across
        // simulated requests in one test method (CONTEXT.md §28).
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('t')->plainTextToken);
    }

    private function makeWebsite(?User $user, string $domain = 'example.com'): Website
    {
        return Website::create([
            'user_id' => $user?->id,
            'name' => $domain,
            'primary_url' => "https://{$domain}/",
            'domain' => $domain,
            'normalized_domain' => $domain,
        ]);
    }

    private function makeAudit(?User $user, AuditStatus $status, ?Website $website = null): Audit
    {
        $website ??= $this->makeWebsite($user);

        return Audit::create([
            'website_id' => $website->id,
            'user_id' => $user?->id,
            'status' => $status,
            'max_pages' => 25,
            'progress_at' => now(),
        ]);
    }

    // --- The state machine itself ---

    public function test_every_non_terminal_state_holds_a_slot_and_every_terminal_state_releases_it(): void
    {
        foreach (AuditStatus::cases() as $status) {
            $expectedActive = ! in_array($status, [
                AuditStatus::Completed,
                AuditStatus::Failed,
                AuditStatus::Cancelled,
            ], true);

            $this->assertSame(
                $expectedActive,
                $status->isActive(),
                "{$status->value} reports the wrong active state",
            );
            $this->assertSame(! $expectedActive, $status->isTerminal());
        }
    }

    public function test_a_terminal_audit_cannot_be_transitioned_again(): void
    {
        $user = $this->makeUser();
        $audit = $this->makeAudit($user, AuditStatus::Cancelled);

        $lifecycle = app(AuditLifecycle::class);

        // A worker finishing after a cancellation must not resurrect the
        // audit and overwrite a real user decision.
        $this->assertFalse($lifecycle->markCompleted($audit));
        $this->assertFalse($lifecycle->markFailed($audit, 'too late'));
        $this->assertSame(AuditStatus::Cancelled, $audit->fresh()->status);
    }

    public function test_a_terminal_transition_stamps_terminated_at(): void
    {
        $user = $this->makeUser();

        foreach ([
            'markCompleted' => AuditStatus::Completed,
            'markCancelled' => AuditStatus::Cancelled,
        ] as $method => $expected) {
            $audit = $this->makeAudit($user, AuditStatus::Crawling, $this->makeWebsite($user, "{$method}.example"));

            app(AuditLifecycle::class)->{$method}($audit);

            $audit->refresh();
            $this->assertSame($expected, $audit->status);
            $this->assertNotNull($audit->terminated_at, "{$method} left terminated_at null");
        }
    }

    public function test_failing_an_audit_records_the_stage_it_died_in(): void
    {
        $user = $this->makeUser();
        $audit = $this->makeAudit($user, AuditStatus::Crawling);

        app(AuditLifecycle::class)->markFailed($audit, 'Playwright exploded', 'crawling');

        $audit->refresh();
        $this->assertSame(AuditStatus::Failed, $audit->status);
        $this->assertSame('crawling', $audit->failed_stage);
        $this->assertStringContainsString('Playwright exploded', $audit->error_message);
    }

    // --- Active-audit limits ---

    public function test_a_plan_allowing_one_active_audit_refuses_a_second(): void
    {
        $user = $this->makeUser(concurrent: 1);
        $this->makeAudit($user, AuditStatus::Crawling);

        $this->asUser($user)
            ->postJson('/api/audits', ['url' => 'https://example.com'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'You already have an audit running. Wait for it to finish, or cancel it, before starting another.');

        $this->assertSame(1, Audit::count());
    }

    public function test_a_plan_allowing_several_active_audits_permits_up_to_its_limit(): void
    {
        $user = $this->makeUser(concurrent: 3);

        // Two already running; the plan allows three, so one more fits.
        $this->makeAudit($user, AuditStatus::Crawling, $this->makeWebsite($user, 'one.example'));
        $this->makeAudit($user, AuditStatus::Analyzing, $this->makeWebsite($user, 'two.example'));

        $this->asUser($user)
            ->postJson('/api/audits', ['url' => 'https://example.com'])
            ->assertStatus(202);

        $this->assertSame(3, Audit::count());

        // The fourth does not.
        $this->asUser($user)
            ->postJson('/api/audits', ['url' => 'https://example.org'])
            ->assertStatus(429);

        $this->assertSame(3, Audit::count());
    }

    public function test_each_active_state_counts_toward_the_limit(): void
    {
        $limiter = app(ActiveAuditLimiter::class);

        foreach (AuditStatus::active() as $status) {
            $user = $this->makeUser(concurrent: 1, email: "{$status->value}@example.com");
            $this->makeAudit($user, $status);

            $this->assertFalse(
                $limiter->hasCapacity($user, null),
                "An audit in {$status->value} did not consume a slot",
            );
        }
    }

    public function test_a_user_with_no_subscription_has_no_slots_at_all(): void
    {
        $user = User::create(['name' => 'Orphan', 'email' => 'orphan@example.com', 'password' => 'x']);

        // Consistent with UsageLimitService, where a planless user cannot
        // create audits at all — not a special case invented here.
        $this->assertSame(0, app(ActiveAuditLimiter::class)->limitFor($user));
    }

    public function test_one_users_running_audits_do_not_consume_another_users_slots(): void
    {
        $ada = $this->makeUser(concurrent: 1, email: 'ada@example.com');
        $grace = $this->makeUser(concurrent: 1, email: 'grace@example.com');

        $this->makeAudit($ada, AuditStatus::Crawling);

        $this->asUser($grace)
            ->postJson('/api/audits', ['url' => 'https://example.com'])
            ->assertStatus(202);
    }

    public function test_the_anonymous_active_limit_comes_from_config(): void
    {
        config(['entitlements.guest.concurrent_audit_limit' => 2]);

        $this->assertSame(2, app(ActiveAuditLimiter::class)->limitFor(null));
    }

    public function test_a_guests_running_audit_consumes_their_slot(): void
    {
        config(['entitlements.guest.concurrent_audit_limit' => 1]);

        $website = $this->makeWebsite(null);
        $audit = $this->makeAudit(null, AuditStatus::Crawling, $website);

        // A guest's audits are tied to them through usage_logs, since the
        // audit row itself carries no IP address.
        UsageLog::create([
            'user_id' => null,
            'ip_address' => '203.0.113.9',
            'action' => 'audit_created',
            'website_id' => $website->id,
            'audit_id' => $audit->id,
        ]);

        $limiter = app(ActiveAuditLimiter::class);
        $this->assertSame(1, $limiter->activeCount(null, '203.0.113.9'));
        $this->assertFalse($limiter->hasCapacity(null, '203.0.113.9'));
        // A different guest is unaffected.
        $this->assertTrue($limiter->hasCapacity(null, '198.51.100.4'));
    }

    // --- Slot release on each terminal path ---

    public function test_a_completed_audit_releases_its_slot(): void
    {
        $this->assertSlotReleasedAfter(fn (Audit $audit) => app(AuditLifecycle::class)->markCompleted($audit));
    }

    public function test_a_failed_audit_releases_its_slot(): void
    {
        $this->assertSlotReleasedAfter(fn (Audit $audit) => app(AuditLifecycle::class)->markFailed($audit, 'boom', 'crawling'));
    }

    public function test_a_cancelled_audit_releases_its_slot(): void
    {
        $this->assertSlotReleasedAfter(fn (Audit $audit) => app(AuditLifecycle::class)->markCancelled($audit));
    }

    private function assertSlotReleasedAfter(\Closure $terminate): void
    {
        $user = $this->makeUser(concurrent: 1);
        $audit = $this->makeAudit($user, AuditStatus::Crawling);
        $limiter = app(ActiveAuditLimiter::class);

        $this->assertFalse($limiter->hasCapacity($user, null), 'The running audit should hold the only slot');

        $terminate($audit);

        // No release step to call: the slot frees because the status is
        // terminal, which is the whole point of deriving it.
        $this->assertTrue($limiter->hasCapacity($user, null), 'The slot was not released');

        $this->asUser($user)
            ->postJson('/api/audits', ['url' => 'https://example.com'])
            ->assertStatus(202);
    }

    // --- Concurrency and races ---

    public function test_the_slot_check_and_the_insert_happen_under_one_lock(): void
    {
        $user = $this->makeUser(concurrent: 1);

        // Hold the lock the limiter uses. A request that ignored locking
        // would happily create the audit; one that takes the lock cannot
        // get it and refuses immediately. This is what proves the guard is
        // actually acquired rather than merely present in the code — and
        // that the acquire is non-blocking, since the test would otherwise
        // sit here until the lock's TTL expired.
        $lock = Cache::lock("active-audits:user:{$user->id}", 30);
        $this->assertTrue($lock->get());

        try {
            $this->asUser($user)
                ->postJson('/api/audits', ['url' => 'https://example.com'])
                ->assertStatus(429);
        } finally {
            $lock->release();
        }

        $this->assertSame(0, Audit::count());

        // Released, the same request succeeds — so the refusal above was
        // the lock, not some unrelated rejection.
        $this->asUser($user)
            ->postJson('/api/audits', ['url' => 'https://example.com'])
            ->assertStatus(202);
    }

    public function test_the_guarded_callback_never_runs_when_the_owner_is_at_capacity(): void
    {
        $user = $this->makeUser(concurrent: 1);
        $this->makeAudit($user, AuditStatus::Queued);

        $ran = false;
        $result = app(ActiveAuditLimiter::class)->withSlot($user, null, function () use (&$ran) {
            $ran = true;

            return 'created';
        });

        // Not "created then rolled back" — the creation must never happen.
        $this->assertFalse($ran);
        $this->assertNull($result);
    }

    public function test_a_burst_of_requests_creates_no_more_audits_than_the_plan_allows(): void
    {
        $user = $this->makeUser(concurrent: 2);

        // Sequential rather than parallel — PHPUnit is single-threaded, so
        // this cannot reproduce a true race. It does prove the invariant
        // holds across repeated attempts, and the lock test above proves
        // the mechanism that protects the parallel case.
        $accepted = 0;
        for ($i = 0; $i < 6; $i++) {
            // One resolvable host with distinct paths: the concurrency
            // limit is per user, not per website, and inventing hostnames
            // would just fail the SSRF validator's DNS check instead.
            $status = $this->asUser($user)
                ->postJson('/api/audits', ['url' => "https://example.com/page-{$i}"])
                ->status();

            if ($status === 202) {
                $accepted++;
            }
        }

        $this->assertSame(2, $accepted);
        $this->assertSame(2, Audit::query()->active()->count());
    }

    // --- Cancellation endpoint ---

    public function test_the_owner_can_cancel_a_running_audit(): void
    {
        $user = $this->makeUser();
        $audit = $this->makeAudit($user, AuditStatus::Crawling);

        $this->asUser($user)
            ->postJson("/api/audits/{$audit->id}/cancel")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(AuditStatus::Cancelled, $audit->fresh()->status);
        $this->assertNotNull($audit->fresh()->terminated_at);
    }

    public function test_a_completed_audit_cannot_be_cancelled(): void
    {
        $user = $this->makeUser();
        $audit = $this->makeAudit($user, AuditStatus::Completed);

        // Re-marking a finished audit as cancelled would destroy a real
        // result, so this is refused rather than accepted as a no-op.
        $this->asUser($user)
            ->postJson("/api/audits/{$audit->id}/cancel")
            ->assertStatus(422);

        $this->assertSame(AuditStatus::Completed, $audit->fresh()->status);
    }

    public function test_another_user_cannot_cancel_someone_elses_audit(): void
    {
        $owner = $this->makeUser(email: 'owner@example.com');
        $intruder = $this->makeUser(email: 'intruder@example.com');
        $audit = $this->makeAudit($owner, AuditStatus::Crawling);

        // 404, not 403 — a 403 would confirm the audit ID exists.
        $this->asUser($intruder)
            ->postJson("/api/audits/{$audit->id}/cancel")
            ->assertStatus(404);

        $this->assertSame(AuditStatus::Crawling, $audit->fresh()->status);
    }

    public function test_cancelling_twice_is_refused_the_second_time(): void
    {
        $user = $this->makeUser();
        $audit = $this->makeAudit($user, AuditStatus::Crawling);

        $this->asUser($user)->postJson("/api/audits/{$audit->id}/cancel")->assertStatus(200);
        $this->asUser($user)->postJson("/api/audits/{$audit->id}/cancel")->assertStatus(422);
    }

    public function test_a_cancelled_audit_stops_the_pipeline_rather_than_completing(): void
    {
        $user = $this->makeUser();
        $audit = $this->makeAudit($user, AuditStatus::Cancelled);
        AuditScore::create(['audit_id' => $audit->id, 'category' => 'seo', 'score' => 80]);

        // A crawl job that finishes after the cancellation must not drag
        // the audit back into `analyzing`.
        AuditModuleRegistry::dispatchAnalysis($audit);

        $this->assertSame(AuditStatus::Cancelled, $audit->fresh()->status);
    }

    public function test_the_status_endpoint_reports_the_lifecycle_flags_the_ui_needs(): void
    {
        $user = $this->makeUser();
        $audit = $this->makeAudit($user, AuditStatus::GeneratingReport);

        $this->asUser($user)
            ->getJson("/api/audits/{$audit->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'generating_report')
            ->assertJsonPath('data.status_label', 'Generating report')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.is_cancellable', true);
    }
}
