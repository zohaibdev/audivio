<?php

namespace Tests\Feature\Api;

use App\DTOs\CrawledPageData;
use App\Enums\AuditStatus;
use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\AuditScore;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;
use App\Services\Crawler\PlaywrightCrawlerClient;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WebsiteApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::fake(['*' => Http::response('', 404)]);
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
                screenshotDesktopPath: null,
                screenshotMobilePath: null,
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

    private function makeUser(string $email = 'ada@example.com', int $dailyAuditLimit = 50): User
    {
        $plan = Plan::firstOrCreate(
            ['slug' => 'free'],
            [
                'name' => 'Free',
                'max_pages' => 25,
                'daily_audit_limit' => $dailyAuditLimit,
                // Website *deduplication* is what this file tests, not the
                // website cap — a low cap here would refuse the second
                // domain before the dedup logic was ever reached (§40).
                'max_websites' => 50,
                'monthly_audit_limit' => null,
                'is_default' => true,
            ],
        );

        $user = User::create(['name' => 'Ada', 'email' => $email, 'password' => 'x']);
        Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'started_at' => now()]);

        return $user;
    }

    /**
     * Authenticate the next request as $user.
     *
     * `forgetGuards()` is not optional here. Sanctum's RequestGuard
     * memoizes its resolved user, and a PHPUnit test method shares one app
     * container across every simulated request — so without it, a second
     * request in the same test stays authenticated as the *first* user
     * even though a different token is sent (CONTEXT.md section 28). Every
     * test in this file that involves two users depends on this.
     */
    private function asUser(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('test')->plainTextToken);
    }

    private function makeWebsite(User $user, string $domain = 'example.com', array $attributes = []): Website
    {
        return Website::create(array_merge([
            'user_id' => $user->id,
            'name' => $domain,
            'primary_url' => "https://{$domain}/",
            'domain' => $domain,
            'normalized_domain' => $domain,
        ], $attributes));
    }

    private function makeCompletedAudit(Website $website, array $scores = ['overall' => 80], array $severities = []): Audit
    {
        $audit = Audit::create([
            'website_id' => $website->id,
            'user_id' => $website->user_id,
            'status' => AuditStatus::Completed,
            'max_pages' => 25,
            'pages_crawled' => 7,
            'completed_at' => now(),
        ]);

        foreach ($scores as $category => $score) {
            AuditScore::create(['audit_id' => $audit->id, 'category' => $category, 'score' => $score]);
        }

        foreach ($severities as $severity => $count) {
            for ($i = 0; $i < $count; $i++) {
                AuditIssue::create([
                    'audit_id' => $audit->id,
                    'category' => 'seo',
                    'severity' => $severity,
                    'code' => "issue_{$severity}_{$i}",
                    'message' => 'Something is wrong',
                    'recommendation' => 'Fix it',
                ]);
            }
        }

        return $audit;
    }

    // --- Duplicate website prevention ---

    public function test_auditing_the_same_domain_twice_reuses_one_website_record(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $user = $this->makeUser();

        $this->asUser($user)
            ->postJson('/api/audits', ['url' => 'https://example.com'])->assertStatus(202);
        $this->asUser($user)
            ->postJson('/api/audits', ['url' => 'https://example.com'])->assertStatus(202);

        $this->assertSame(1, Website::count());
        $this->assertSame(2, Audit::count());
        $this->assertSame(2, Website::sole()->audits()->count());
    }

    public function test_www_and_bare_domain_are_the_same_website(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $user = $this->makeUser();

        $this->asUser($user)
            ->postJson('/api/audits', ['url' => 'https://www.example.com'])->assertStatus(202);
        $this->asUser($user)
            ->postJson('/api/audits', ['url' => 'https://example.com'])->assertStatus(202);

        $this->assertSame(1, Website::count());
        $this->assertSame('example.com', Website::sole()->normalized_domain);
    }

    public function test_url_spelling_differences_do_not_create_a_second_website(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $user = $this->makeUser();

        foreach ([
            'https://example.com',
            'HTTPS://EXAMPLE.COM/',
            'https://example.com:443/pricing',
            'https://example.com./#top',
            'https://WWW.Example.com/pricing?a=b',
        ] as $url) {
            $this->asUser($user)
                ->postJson('/api/audits', ['url' => $url])->assertStatus(202);
        }

        $this->assertSame(1, Website::count());
        $this->assertSame(5, Audit::count());
    }

    public function test_a_different_domain_gets_its_own_website(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $user = $this->makeUser();

        $this->asUser($user)
            ->postJson('/api/audits', ['url' => 'https://example.com'])->assertStatus(202);
        $this->asUser($user)
            ->postJson('/api/audits', ['url' => 'https://example.org'])->assertStatus(202);

        $this->assertSame(2, Website::count());
    }

    public function test_two_users_auditing_the_same_domain_get_separate_website_records(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $ada = $this->makeUser('ada@example.com');
        $grace = $this->makeUser('grace@example.com');

        $this->asUser($ada)
            ->postJson('/api/audits', ['url' => 'https://example.com'])->assertStatus(202);
        $this->asUser($grace)
            ->postJson('/api/audits', ['url' => 'https://example.com'])->assertStatus(202);

        // One website *per user*, not one globally — each owner has their
        // own profile fields and their own history for that domain.
        $this->assertSame(2, Website::count());
    }

    public function test_the_database_itself_rejects_a_duplicate_domain_for_one_user(): void
    {
        $user = $this->makeUser();
        $this->makeWebsite($user);

        // The unique index, not just the resolver's read-then-write, is
        // what makes duplicates impossible under a race.
        $this->expectException(UniqueConstraintViolationException::class);

        $this->makeWebsite($user);
    }

    public function test_anonymous_audits_of_the_same_domain_do_not_share_a_website_record(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->postJson('/api/audits', ['url' => 'https://example.com'])->assertStatus(202);

        // The second is refused by the anonymous lifetime limit, so create
        // the situation directly: two anonymous rows for one domain are
        // *allowed*, because sharing one would hand every guest the audit
        // history of every other guest who audited that site.
        Website::create([
            'user_id' => null,
            'primary_url' => 'https://example.com/',
            'domain' => 'example.com',
            'normalized_domain' => 'example.com',
        ]);

        $this->assertSame(2, Website::whereNull('user_id')->count());
    }

    public function test_a_repeat_audit_updates_the_primary_url_but_not_the_user_edited_profile(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $user = $this->makeUser();

        $this->asUser($user)
            ->postJson('/api/audits', ['url' => 'https://example.com'])->assertStatus(202);

        Website::sole()->update(['name' => 'My Shop', 'category' => 'ecommerce']);

        $this->asUser($user)
            ->postJson('/api/audits', ['url' => 'https://example.com/pricing'])->assertStatus(202);

        $website = Website::sole();
        $this->assertSame('https://example.com/pricing', $website->primary_url);
        // A re-audit is not an edit of the record.
        $this->assertSame('My Shop', $website->name);
        $this->assertSame('ecommerce', $website->category);
    }

    // --- Multiple audit history and latest audit ---

    public function test_the_listing_shows_one_card_per_domain_with_its_audit_count(): void
    {
        $user = $this->makeUser();
        $website = $this->makeWebsite($user);
        $this->makeCompletedAudit($website, ['overall' => 60]);
        $this->makeCompletedAudit($website, ['overall' => 90]);

        $response = $this->asUser($user)
            ->getJson('/api/websites')
            ->assertStatus(200);

        $response->assertJsonCount(1, 'data.data');
        $this->assertSame(2, $response->json('data.data.0.audits_count'));
    }

    public function test_the_card_reports_the_latest_audits_scores_and_issue_counts(): void
    {
        $user = $this->makeUser();
        $website = $this->makeWebsite($user);

        $this->makeCompletedAudit($website, ['overall' => 40], ['critical' => 5, 'high' => 3]);
        $this->makeCompletedAudit($website, [
            'overall' => 88,
            'performance' => 70,
            'accessibility' => 91,
            'seo' => 84,
            'conversion' => 77,
            'aio' => 65,
        ], ['critical' => 1, 'high' => 2, 'medium' => 4]);

        $card = $this->asUser($user)
            ->getJson('/api/websites')
            ->assertStatus(200)
            ->json('data.data.0');

        $this->assertSame(88, $card['overall_score']);
        $this->assertSame(70, $card['scores']['performance']);
        $this->assertSame(91, $card['scores']['accessibility']);
        $this->assertSame(84, $card['scores']['seo']);
        $this->assertSame(77, $card['scores']['conversion']);
        $this->assertSame(65, $card['scores']['aio']);
        $this->assertSame(7, $card['pages_analyzed']);

        // From the newest audit only — not summed across history.
        $this->assertSame(1, $card['critical_issues']);
        $this->assertSame(2, $card['high_issues']);
    }

    public function test_a_card_keeps_the_last_completed_scores_while_a_re_audit_is_running(): void
    {
        $user = $this->makeUser();
        $website = $this->makeWebsite($user);
        $this->makeCompletedAudit($website, ['overall' => 88]);

        $running = Audit::create([
            'website_id' => $website->id,
            'user_id' => $user->id,
            'status' => AuditStatus::Crawling,
            'max_pages' => 25,
        ]);

        $card = $this->asUser($user)
            ->getJson('/api/websites')
            ->json('data.data.0');

        // The in-flight run must not blank out what the user could see a
        // moment ago; it is reported alongside instead.
        $this->assertSame(88, $card['overall_score']);
        $this->assertSame($running->id, $card['latest_audit']['id']);
        $this->assertSame('crawling', $card['latest_audit']['status']);
    }

    public function test_a_website_with_only_a_failed_audit_reports_no_scores(): void
    {
        $user = $this->makeUser();
        $website = $this->makeWebsite($user);

        Audit::create([
            'website_id' => $website->id,
            'user_id' => $user->id,
            'status' => AuditStatus::Failed,
            'max_pages' => 25,
            'error_message' => 'Crawl failed',
        ]);

        $card = $this->asUser($user)
            ->getJson('/api/websites')
            ->json('data.data.0');

        $this->assertNull($card['overall_score']);
        $this->assertNull($card['last_audit_at']);
        $this->assertSame('failed', $card['latest_audit']['status']);
    }

    public function test_the_listing_only_contains_the_callers_own_websites(): void
    {
        $ada = $this->makeUser('ada@example.com');
        $grace = $this->makeUser('grace@example.com');
        $this->makeWebsite($ada, 'ada-site.example');
        $this->makeWebsite($grace, 'grace-site.example');

        $domains = $this->asUser($ada)
            ->getJson('/api/websites')
            ->json('data.data.*.domain');

        $this->assertSame(['ada-site.example'], $domains);
    }

    // --- Website detail ---

    public function test_detail_can_be_addressed_by_domain_and_returns_every_section(): void
    {
        $user = $this->makeUser();
        $website = $this->makeWebsite($user, 'example.com', ['category' => 'saas', 'country' => 'GB']);
        $this->makeCompletedAudit($website, ['overall' => 70, 'seo' => 65], ['critical' => 2, 'high' => 1]);

        $data = $this->asUser($user)
            ->getJson('/api/websites/example.com')
            ->assertStatus(200)
            ->json('data');

        foreach ([
            'overview', 'latest_audit', 'audit_history', 'score_trends',
            'service_scores', 'issue_trends', 'pages', 'recommendations', 'consultation',
        ] as $section) {
            $this->assertArrayHasKey($section, $data, "Missing section: {$section}");
        }

        $this->assertSame('example.com', $data['overview']['domain']);
        $this->assertSame('saas', $data['overview']['category']);
        $this->assertSame('GB', $data['overview']['country']);
    }

    public function test_detail_can_also_be_addressed_by_id_and_by_domain_spelling_variants(): void
    {
        $user = $this->makeUser();
        $website = $this->makeWebsite($user);

        $this->asUser($user)
            ->getJson("/api/websites/{$website->id}")->assertStatus(200);

        // The segment is canonicalized the same way the stored key was, so
        // every spelling of the domain reaches the one record.
        $this->asUser($user)
            ->getJson('/api/websites/WWW.Example.COM')->assertStatus(200);

        $this->asUser($user)
            ->getJson('/api/websites/example.com.')->assertStatus(200);
    }

    public function test_score_trends_are_chronological_and_exclude_unfinished_audits(): void
    {
        $user = $this->makeUser();
        $website = $this->makeWebsite($user);

        $this->makeCompletedAudit($website, ['overall' => 50, 'seo' => 40]);
        $this->makeCompletedAudit($website, ['overall' => 75, 'seo' => 80]);
        Audit::create([
            'website_id' => $website->id,
            'user_id' => $user->id,
            'status' => AuditStatus::Crawling,
            'max_pages' => 25,
        ]);

        $trends = $this->asUser($user)
            ->getJson('/api/websites/example.com')
            ->json('data.score_trends');

        $this->assertSame([50, 75], array_column($trends['overall'], 'score'));
        $this->assertSame([40, 80], array_column($trends['seo'], 'score'));
    }

    public function test_a_category_no_audit_scored_is_absent_from_the_trends_rather_than_flat_at_zero(): void
    {
        $user = $this->makeUser();
        $website = $this->makeWebsite($user);
        $this->makeCompletedAudit($website, ['overall' => 70, 'seo' => 65]);

        $trends = $this->asUser($user)
            ->getJson('/api/websites/example.com')
            ->json('data.score_trends');

        $this->assertArrayHasKey('seo', $trends);
        // A zero-valued series would read as "we measured this and it is
        // terrible" rather than "this check never ran".
        $this->assertArrayNotHasKey('performance', $trends);
        $this->assertArrayNotHasKey('aio', $trends);
    }

    public function test_service_scores_exclude_the_overall_score(): void
    {
        $user = $this->makeUser();
        $website = $this->makeWebsite($user);
        $this->makeCompletedAudit($website, ['overall' => 70, 'seo' => 65, 'performance' => 80]);

        $data = $this->asUser($user)
            ->getJson('/api/websites/example.com')
            ->json('data');

        $categories = array_column($data['service_scores'], 'category');

        // `audit_scores.category` is enum-cast, so a `=== 'overall'` filter
        // silently never matches and the overall score renders as if it
        // were a seventh service. Caught in the browser, guarded here.
        sort($categories);
        $this->assertSame(['performance', 'seo'], $categories);
        $this->assertSame(70, $data['overview']['overall_score']);
    }

    public function test_issue_trends_report_counts_per_audit_over_time(): void
    {
        $user = $this->makeUser();
        $website = $this->makeWebsite($user);
        $this->makeCompletedAudit($website, ['overall' => 30], ['critical' => 4, 'high' => 2]);
        $this->makeCompletedAudit($website, ['overall' => 80], ['critical' => 1, 'medium' => 3]);

        $trends = $this->asUser($user)
            ->getJson('/api/websites/example.com')
            ->json('data.issue_trends');

        $this->assertSame([4, 1], array_column($trends, 'critical'));
        $this->assertSame([2, 0], array_column($trends, 'high'));
        $this->assertSame([0, 3], array_column($trends, 'medium'));
    }

    public function test_audit_history_includes_failed_runs_that_the_trends_exclude(): void
    {
        $user = $this->makeUser();
        $website = $this->makeWebsite($user);
        $this->makeCompletedAudit($website, ['overall' => 70]);
        Audit::create([
            'website_id' => $website->id,
            'user_id' => $user->id,
            'status' => AuditStatus::Failed,
            'max_pages' => 25,
            'error_message' => 'Crawl failed',
        ]);

        $data = $this->asUser($user)
            ->getJson('/api/websites/example.com')
            ->json('data');

        $this->assertCount(2, $data['audit_history']);
        $this->assertSame('failed', $data['audit_history'][0]['status']);
        $this->assertSame('Crawl failed', $data['audit_history'][0]['error_message']);
        // A failed run would render as a false cliff on a trend line.
        $this->assertCount(1, $data['score_trends']['overall']);
    }

    public function test_recommendations_only_include_issues_that_carry_one(): void
    {
        $user = $this->makeUser();
        $website = $this->makeWebsite($user);
        $audit = $this->makeCompletedAudit($website, ['overall' => 50], ['high' => 1]);

        AuditIssue::create([
            'audit_id' => $audit->id,
            'category' => 'seo',
            'severity' => 'critical',
            'code' => 'no_advice',
            'message' => 'Something is wrong',
            'recommendation' => null,
        ]);

        $recommendations = $this->asUser($user)
            ->getJson('/api/websites/example.com')
            ->json('data.recommendations');

        $this->assertCount(1, $recommendations);
        $this->assertSame('Fix it', $recommendations[0]['recommendation']);
    }

    // --- Ownership and unauthorized access ---

    public function test_website_endpoints_require_authentication(): void
    {
        $website = $this->makeWebsite($this->makeUser());

        $this->getJson('/api/websites')->assertStatus(401);
        $this->getJson("/api/websites/{$website->id}")->assertStatus(401);
        $this->putJson("/api/websites/{$website->id}", ['name' => 'x'])->assertStatus(401);
    }

    public function test_another_users_website_is_not_found_by_id(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $intruder = $this->makeUser('intruder@example.com');
        $website = $this->makeWebsite($owner);

        // 404, not 403: a 403 would confirm the id exists, turning the
        // endpoint into a way to enumerate other users' websites.
        $this->asUser($intruder)
            ->getJson("/api/websites/{$website->id}")
            ->assertStatus(404);
    }

    public function test_another_users_website_is_not_found_by_domain(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $intruder = $this->makeUser('intruder@example.com');
        $this->makeWebsite($owner, 'private-client.example');

        // Knowing the domain is not authorization — it only selects among
        // websites the caller already owns.
        $this->asUser($intruder)
            ->getJson('/api/websites/private-client.example')
            ->assertStatus(404);
    }

    public function test_another_users_website_cannot_be_edited(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $intruder = $this->makeUser('intruder@example.com');
        $website = $this->makeWebsite($owner, 'example.com', ['name' => 'Original']);

        $this->asUser($intruder)
            ->putJson("/api/websites/{$website->id}", ['name' => 'Hijacked'])
            ->assertStatus(404);

        $this->assertSame('Original', $website->fresh()->name);
    }

    public function test_an_anonymous_website_is_not_visible_to_any_logged_in_user(): void
    {
        $user = $this->makeUser();
        $anonymous = Website::create([
            'user_id' => null,
            'primary_url' => 'https://example.com/',
            'domain' => 'example.com',
            'normalized_domain' => 'example.com',
        ]);

        $this->asUser($user)
            ->getJson("/api/websites/{$anonymous->id}")
            ->assertStatus(404);
    }

    // --- Profile editing ---

    public function test_the_owner_can_edit_the_website_profile(): void
    {
        $user = $this->makeUser();
        $website = $this->makeWebsite($user);

        $this->asUser($user)
            ->putJson("/api/websites/{$website->id}", [
                'name' => 'Acme Store',
                'category' => 'ecommerce',
                'description' => 'We sell things.',
                'products_or_services' => 'Widgets',
                'country' => 'gb',
                'budget' => 5000,
                'average_product_cost' => 49.99,
                'competitors' => ['https://rival.example', 'https://other.example'],
            ])
            ->assertStatus(200);

        $website->refresh();
        $this->assertSame('Acme Store', $website->name);
        $this->assertSame('ecommerce', $website->category);
        $this->assertSame('GB', $website->country);
        $this->assertSame('5000.00', $website->budget);
        $this->assertSame('49.99', $website->average_product_cost);
        $this->assertSame(['https://rival.example', 'https://other.example'], $website->competitors);
    }

    public function test_identity_fields_cannot_be_rewritten_through_the_profile_endpoint(): void
    {
        $user = $this->makeUser();
        $website = $this->makeWebsite($user);

        $this->asUser($user)
            ->putJson("/api/websites/{$website->id}", [
                'name' => 'Renamed',
                'domain' => 'attacker.example',
                'normalized_domain' => 'attacker.example',
                'primary_url' => 'https://attacker.example/',
                'user_id' => 999,
            ])
            ->assertStatus(200);

        $website->refresh();
        $this->assertSame('Renamed', $website->name);
        // Identity is derived from what was actually audited; a writable
        // normalized_domain would let one record claim another's.
        $this->assertSame('example.com', $website->domain);
        $this->assertSame('example.com', $website->normalized_domain);
        $this->assertSame('https://example.com/', $website->primary_url);
        $this->assertSame($user->id, $website->user_id);
    }

    public function test_profile_validation_rejects_bad_values(): void
    {
        $user = $this->makeUser();
        $website = $this->makeWebsite($user);

        $this->asUser($user)
            ->putJson("/api/websites/{$website->id}", ['country' => 'United Kingdom'])
            ->assertStatus(422);

        $this->asUser($user)
            ->putJson("/api/websites/{$website->id}", ['budget' => -1])
            ->assertStatus(422);

        $this->asUser($user)
            ->putJson("/api/websites/{$website->id}", ['competitors' => 'not-an-array'])
            ->assertStatus(422);
    }
}
