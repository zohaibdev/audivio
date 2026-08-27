<?php

namespace Tests\Feature\Api;

use App\DTOs\CrawledPageData;
use App\Models\Audit;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;
use App\Services\Crawler\PlaywrightCrawlerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuditApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::fake(['*' => Http::response('', 404)]); // no robots.txt/sitemap on the fake site
        $this->mockCrawler();
    }

    private function mockCrawler(): void
    {
        $this->mock(PlaywrightCrawlerClient::class, function ($mock) {
            $mock->shouldReceive('crawl')->andReturn(new CrawledPageData(
                url: 'https://example.com/',
                httpStatus: 200,
                redirectChain: [],
                loadTimeMs: 500,
                screenshotPath: null,
                title: 'Home',
                metaDescription: str_repeat('a', 100),
                canonicalUrl: 'https://example.com/',
                headings: [['level' => 1, 'text' => 'Welcome']],
                links: [],
                images: [],
                forms: [],
                structuredData: [],
                ctaElements: [],
                htmlSizeBytes: 1000,
                accessibilityViolations: [],
            ));
        });
    }

    private function makePlanAndUser(int $dailyAuditLimit = 1, string $email = 'ada@example.com', array $features = []): User
    {
        $plan = Plan::firstOrCreate(
            ['slug' => 'free'],
            ['name' => 'Free', 'max_pages' => 25, 'daily_audit_limit' => $dailyAuditLimit, 'is_default' => true, 'features' => $features],
        );

        if ($plan->wasRecentlyCreated === false && $features !== []) {
            $plan->update(['features' => $features]);
        }

        $user = User::create(['name' => 'Ada', 'email' => $email, 'password' => 'x']);
        Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'started_at' => now()]);

        return $user;
    }

    public function test_anonymous_visitor_can_create_an_audit(): void
    {
        $response = $this->postJson('/api/audits', ['url' => 'https://example.com']);

        // 'pending' is the correct response here regardless of what the
        // sync queue driver happens to do under the hood in tests — a 202
        // Accepted response reflects the state at acceptance, not
        // whatever a background worker gets to next. Real async
        // processing (redis in production) means this MUST stay
        // 'pending' at this point; a client polls GET /api/audits/{id}
        // for progress (see test_show_returns_audit_status).
        $response->assertStatus(202)->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseHas('audits', ['id' => $response->json('data.audit_id')]);
    }

    public function test_created_audit_has_no_user_id_for_an_anonymous_request(): void
    {
        $response = $this->postJson('/api/audits', ['url' => 'https://example.com']);

        $audit = Audit::find($response->json('data.audit_id'));
        $this->assertNull($audit->user_id);
    }

    public function test_anonymous_audit_is_capped_at_the_configured_anonymous_page_limit(): void
    {
        config(['audit_limits.anonymous_max_pages' => 5]);

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com']);

        $audit = Audit::find($response->json('data.audit_id'));
        $this->assertSame(5, $audit->max_pages);
    }

    public function test_unsafe_url_is_rejected_before_creating_any_records(): void
    {
        $response = $this->postJson('/api/audits', ['url' => 'http://127.0.0.1/']);

        $response->assertStatus(422);
        $this->assertSame(0, Audit::count());
    }

    public function test_missing_url_is_rejected_with_a_validation_error(): void
    {
        $this->postJson('/api/audits', [])->assertStatus(422);
    }

    public function test_second_audit_from_the_same_anonymous_ip_is_rejected(): void
    {
        $this->postJson('/api/audits', ['url' => 'https://example.com'])->assertStatus(202);

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com']);

        $response->assertStatus(429);
        $this->assertSame(1, Audit::count());
    }

    public function test_registered_user_can_create_an_audit_with_their_plans_page_limit(): void
    {
        $user = $this->makePlanAndUser();
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/audits', ['url' => 'https://example.com']);

        $response->assertStatus(202);
        $audit = Audit::find($response->json('data.audit_id'));
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame(25, $audit->max_pages);
    }

    public function test_registered_user_hitting_their_daily_limit_is_rejected(): void
    {
        $user = $this->makePlanAndUser(dailyAuditLimit: 1);
        $token = $user->createToken('api')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/audits', ['url' => 'https://example.com'])
            ->assertStatus(202);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/audits', ['url' => 'https://example.com'])
            ->assertStatus(429);
    }

    public function test_a_registered_users_limit_is_independent_of_an_exhausted_anonymous_ip_limit(): void
    {
        // Anonymous IP uses its one lifetime audit...
        $this->postJson('/api/audits', ['url' => 'https://example.com'])->assertStatus(202);

        // ...but a registered user hitting the API from the SAME test
        // client (same IP, in effect) must not be blocked by that.
        $user = $this->makePlanAndUser();
        $token = $user->createToken('api')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/audits', ['url' => 'https://example.com'])
            ->assertStatus(202);
    }

    public function test_show_returns_audit_status(): void
    {
        $create = $this->postJson('/api/audits', ['url' => 'https://example.com']);
        $auditId = $create->json('data.audit_id');

        $response = $this->getJson("/api/audits/{$auditId}");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.audit_id', $auditId);
    }

    public function test_show_for_an_unknown_audit_returns_404(): void
    {
        $this->getJson('/api/audits/999999')->assertStatus(404);
    }

    public function test_report_endpoint_returns_the_anonymous_view_for_an_anonymous_viewer(): void
    {
        $create = $this->postJson('/api/audits', ['url' => 'https://example.com']);
        $auditId = $create->json('data.audit_id');

        $response = $this->getJson("/api/audits/{$auditId}/report");

        $response->assertStatus(200)->assertJsonPath('data.is_full_access', false);
    }

    public function test_report_endpoint_returns_full_access_for_the_audits_owner(): void
    {
        $user = $this->makePlanAndUser();
        $token = $user->createToken('api')->plainTextToken;

        $create = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/audits', ['url' => 'https://example.com']);
        $auditId = $create->json('data.audit_id');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/audits/{$auditId}/report");

        $response->assertStatus(200)->assertJsonPath('data.is_full_access', true);
    }

    public function test_report_endpoint_does_not_grant_full_access_to_a_different_registered_user(): void
    {
        $owner = $this->makePlanAndUser(email: 'owner@example.com');
        $ownerToken = $owner->createToken('api')->plainTextToken;

        $create = $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->postJson('/api/audits', ['url' => 'https://example.com']);
        $auditId = $create->json('data.audit_id');

        $someoneElse = $this->makePlanAndUser(email: 'someone-else@example.com');
        $otherToken = $someoneElse->createToken('api')->plainTextToken;

        // Sanctum's RequestGuard memoizes its resolved user for the
        // guard instance's lifetime, which — unlike in production, where
        // every HTTP request is a fresh PHP process — spans multiple
        // simulated requests sharing one app container within a single
        // test method. Without resetting it here, this second call would
        // still resolve as $owner (the first token's user) regardless of
        // which token is actually sent.
        $this->app['auth']->forgetGuards();

        $response = $this->withHeader('Authorization', "Bearer {$otherToken}")
            ->getJson("/api/audits/{$auditId}/report");

        $response->assertStatus(200)->assertJsonPath('data.is_full_access', false);
    }

    public function test_pdf_export_returns_a_pdf_for_the_owner_on_a_plan_with_the_feature(): void
    {
        $user = $this->makePlanAndUser(email: 'owner@example.com', features: ['pdf_export' => true]);
        $token = $user->createToken('api')->plainTextToken;

        $create = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/audits', ['url' => 'https://example.com']);
        $auditId = $create->json('data.audit_id');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->get("/api/audits/{$auditId}/report/pdf");

        $response->assertStatus(200);
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_pdf_export_is_rejected_when_the_owners_plan_lacks_the_feature(): void
    {
        $user = $this->makePlanAndUser(email: 'owner@example.com');
        $token = $user->createToken('api')->plainTextToken;

        $create = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/audits', ['url' => 'https://example.com']);
        $auditId = $create->json('data.audit_id');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/audits/{$auditId}/report/pdf")
            ->assertStatus(403);
    }

    public function test_pdf_export_is_rejected_for_a_non_owner(): void
    {
        $owner = $this->makePlanAndUser(email: 'owner@example.com', features: ['pdf_export' => true]);
        $ownerToken = $owner->createToken('api')->plainTextToken;

        $create = $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->postJson('/api/audits', ['url' => 'https://example.com']);
        $auditId = $create->json('data.audit_id');

        $someoneElse = $this->makePlanAndUser(email: 'someone-else@example.com');
        $this->app['auth']->forgetGuards();
        $otherToken = $someoneElse->createToken('api')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$otherToken}")
            ->getJson("/api/audits/{$auditId}/report/pdf")
            ->assertStatus(403);
    }

    public function test_pdf_export_is_rejected_for_an_anonymous_viewer(): void
    {
        $user = $this->makePlanAndUser(email: 'owner@example.com', features: ['pdf_export' => true]);
        $token = $user->createToken('api')->plainTextToken;

        $create = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/audits', ['url' => 'https://example.com']);
        $auditId = $create->json('data.audit_id');

        // withHeader() sets a *default* header that otherwise persists on
        // every subsequent request from this test client — without
        // dropping it here, this "anonymous" call would still carry the
        // owner's bearer token. Also reset the Sanctum guard memoized
        // from the prior authenticated call (see CONTEXT.md §26).
        $this->app['auth']->forgetGuards();

        $this->withoutHeader('Authorization')
            ->getJson("/api/audits/{$auditId}/report/pdf")
            ->assertStatus(403);
    }

    public function test_pdf_export_returns_404_when_the_report_is_not_ready(): void
    {
        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'status' => 'crawling', 'max_pages' => 5]);

        $this->getJson("/api/audits/{$audit->id}/report/pdf")->assertStatus(404);
    }

    public function test_requests_beyond_the_rate_limit_are_throttled(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/api/audits/999999');
        }

        $this->getJson('/api/audits/999999')->assertStatus(429);
    }
}
