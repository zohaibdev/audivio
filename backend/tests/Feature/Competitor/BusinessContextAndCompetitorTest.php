<?php

namespace Tests\Feature\Competitor;

use App\DTOs\AiResponse;
use App\Enums\CompetitorSource;
use App\Enums\PlanFeature;
use App\Models\Audit;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteCompetitor;
use App\Services\AI\AiAnalysisService;
use App\Services\Audit\AioContextBuilder;
use App\Services\Audit\AioPromptLibrary;
use App\Services\Competitor\CompetitorDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Business onboarding context, AIO prompts, and competitor intelligence
 * (§42).
 */
class BusinessContextAndCompetitorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function makeUser(array $planAttributes = [], string $email = 'ada@example.com'): User
    {
        $plan = Plan::create(array_merge([
            'name' => 'Test', 'slug' => 'plan-'.uniqid(), 'price_cents' => 0,
            'max_pages' => 25, 'max_websites' => 10, 'daily_audit_limit' => 50,
            'concurrent_audit_limit' => 5, 'report_visibility_percent' => 100,
            'aio_prompt_limit' => null, 'custom_aio_prompt_limit' => 2,
        ], $planAttributes));

        $user = User::create(['name' => 'Ada', 'email' => $email, 'password' => 'x']);
        Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'started_at' => now()]);

        return $user->fresh();
    }

    private function asUser(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('t')->plainTextToken);
    }

    private function makeWebsite(User $user, array $attributes = []): Website
    {
        return Website::create(array_merge([
            'user_id' => $user->id, 'name' => 'example.com',
            'primary_url' => 'https://example.com/', 'domain' => 'example.com',
            'normalized_domain' => 'example.com',
        ], $attributes));
    }

    private function fullProfile(): array
    {
        return [
            'category' => 'ecommerce',
            'description' => 'We sell handmade ceramics.',
            'products_or_services' => 'Mugs, bowls, vases',
            'country' => 'GB',
            'aio_goal' => 'Be found for handmade British ceramics',
        ];
    }

    // --- Onboarding context ---

    public function test_the_aio_goal_can_be_saved_through_the_profile_endpoint(): void
    {
        $user = $this->makeUser();
        $website = $this->makeWebsite($user);

        $this->asUser($user)
            ->putJson("/api/websites/{$website->id}", ['aio_goal' => 'Be found for handmade ceramics'])
            ->assertStatus(200);

        $this->assertSame('Be found for handmade ceramics', $website->fresh()->aio_goal);
    }

    public function test_the_context_builder_includes_only_fields_the_owner_filled_in(): void
    {
        $website = $this->makeWebsite($this->makeUser(), ['category' => 'saas', 'country' => 'GB']);

        $context = app(AioContextBuilder::class)->build($website);

        $this->assertSame(['business_category' => 'saas', 'country' => 'GB'], $context);
        // An empty field is omitted, never sent as null — otherwise the
        // model is invited to reason about "description: ''".
        $this->assertArrayNotHasKey('business_description', $context);
    }

    public function test_context_is_sufficient_only_once_the_aio_goal_is_set(): void
    {
        $builder = app(AioContextBuilder::class);
        $user = $this->makeUser();

        $without = $this->makeWebsite($user, ['category' => 'saas', 'description' => 'Lots of detail here.']);
        $this->assertFalse($builder->isSufficient($without));

        $with = $this->makeWebsite($user, ['normalized_domain' => 'b.example', 'domain' => 'b.example', 'aio_goal' => 'Be found for X']);
        $this->assertTrue($builder->isSufficient($with));
    }

    public function test_missing_fields_are_reported_for_the_onboarding_prompt(): void
    {
        $website = $this->makeWebsite($this->makeUser(), ['category' => 'saas']);

        $missing = app(AioContextBuilder::class)->missingFields($website);

        $this->assertContains('aio_goal', $missing);
        $this->assertContains('competitors', $missing);
        $this->assertNotContains('category', $missing);
    }

    public function test_the_detail_endpoint_reports_onboarding_completeness(): void
    {
        $user = $this->makeUser();
        $website = $this->makeWebsite($user, $this->fullProfile() + ['competitors' => ['rival.example']]);

        $data = $this->asUser($user)->getJson("/api/websites/{$website->id}")->json('data');

        $this->assertTrue($data['onboarding']['is_complete']);
        $this->assertTrue($data['onboarding']['unlocks_context_prompts']);
        $this->assertSame([], $data['onboarding']['missing_fields']);
    }

    // --- AIO prompt library ---

    public function test_ten_default_prompts_run_when_the_profile_is_complete(): void
    {
        $website = $this->makeWebsite($this->makeUser(), $this->fullProfile());

        $prompts = app(AioPromptLibrary::class)->for($website);

        $this->assertCount(10, $prompts);
        $this->assertSame('default', $prompts[0]['source']);
    }

    public function test_context_dependent_prompts_are_skipped_when_the_goal_is_missing(): void
    {
        $website = $this->makeWebsite($this->makeUser(), ['category' => 'saas']);

        $prompts = app(AioPromptLibrary::class)->for($website);
        $skipped = app(AioPromptLibrary::class)->skippedFor($website);

        // Asking "does the content serve the stated goal" with no stated
        // goal invites the model to invent one.
        $this->assertCount(7, $prompts);
        $this->assertSame(['goal_alignment', 'offering_coverage', 'audience_and_locale'], $skipped['skipped']);
        $this->assertContains('aio_goal', $skipped['missing_fields']);
    }

    public function test_custom_prompts_are_appended_after_the_defaults(): void
    {
        $website = $this->makeWebsite($this->makeUser(), $this->fullProfile());
        $website->aioPrompts()->create(['prompt' => 'Do we explain our returns policy clearly?', 'sort_order' => 1]);

        $prompts = app(AioPromptLibrary::class)->for($website);

        $this->assertCount(11, $prompts);
        $this->assertSame('custom', end($prompts)['source']);
    }

    public function test_the_execution_budget_truncates_custom_prompts_before_defaults(): void
    {
        $user = $this->makeUser(['aio_prompt_limit' => 10]);
        $website = $this->makeWebsite($user, $this->fullProfile());
        $website->aioPrompts()->create(['prompt' => 'A custom question about our policies?', 'sort_order' => 1]);

        $prompts = app(AioPromptLibrary::class)->for($website);

        // The baseline is what makes one audit comparable to the next, so
        // a tight budget drops the owner's extra questions, not the ten.
        $this->assertCount(10, $prompts);
        $this->assertSame(['default'], array_unique(array_column($prompts, 'source')));
    }

    // --- Custom prompt CRUD and its limit ---

    public function test_the_owner_can_add_and_remove_a_custom_prompt(): void
    {
        $user = $this->makeUser();
        $website = $this->makeWebsite($user);

        $id = $this->asUser($user)
            ->postJson("/api/websites/{$website->id}/aio-prompts", ['prompt' => 'Do we clearly state our delivery times?'])
            ->assertStatus(201)
            ->json('data.id');

        $this->assertSame(1, $website->aioPrompts()->count());

        $this->asUser($user)
            ->deleteJson("/api/websites/{$website->id}/aio-prompts/{$id}")
            ->assertStatus(200);

        $this->assertSame(0, $website->aioPrompts()->count());
    }

    public function test_adding_beyond_the_plan_limit_is_refused_at_the_point_of_adding(): void
    {
        $user = $this->makeUser(['custom_aio_prompt_limit' => 1]);
        $website = $this->makeWebsite($user);

        $this->asUser($user)
            ->postJson("/api/websites/{$website->id}/aio-prompts", ['prompt' => 'First question about our site?'])
            ->assertStatus(201);

        // Told now, rather than having the prompt silently ignored later.
        $this->asUser($user)
            ->postJson("/api/websites/{$website->id}/aio-prompts", ['prompt' => 'Second question about our site?'])
            ->assertStatus(403)
            ->assertJsonPath('errors.limit', 'custom_aio_prompt_limit');

        $this->assertSame(1, $website->aioPrompts()->count());
    }

    public function test_a_plan_with_no_custom_prompts_is_told_so(): void
    {
        $user = $this->makeUser(['custom_aio_prompt_limit' => 0]);
        $website = $this->makeWebsite($user);

        $this->asUser($user)
            ->postJson("/api/websites/{$website->id}/aio-prompts", ['prompt' => 'A question about our site?'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Custom AIO prompts are not included in your current plan.');
    }

    public function test_prompts_on_another_users_website_are_not_reachable(): void
    {
        $owner = $this->makeUser(email: 'owner@example.com');
        $intruder = $this->makeUser(email: 'intruder@example.com');
        $website = $this->makeWebsite($owner);
        $prompt = $website->aioPrompts()->create(['prompt' => 'Owner question about the site?', 'sort_order' => 1]);

        $this->asUser($intruder)->getJson("/api/websites/{$website->id}/aio-prompts")->assertStatus(404);
        $this->asUser($intruder)->deleteJson("/api/websites/{$website->id}/aio-prompts/{$prompt->id}")->assertStatus(404);

        $this->assertSame(1, $website->aioPrompts()->count());
    }

    public function test_the_prompt_index_shows_the_defaults_alongside_custom_ones(): void
    {
        $user = $this->makeUser();
        $website = $this->makeWebsite($user);

        $data = $this->asUser($user)->getJson("/api/websites/{$website->id}/aio-prompts")->json('data');

        // An empty custom list must not read as "nothing is being asked".
        $this->assertCount(10, $data['defaults']);
        $this->assertSame([], $data['prompts']);
        $this->assertSame(2, $data['limit']);
        $this->assertTrue($data['can_add']);
    }

    // --- Competitor discovery ---

    private function fakeAi(string $content): void
    {
        $this->mock(AiAnalysisService::class, function ($mock) use ($content) {
            $mock->shouldReceive('analyze')->andReturn(
                new AiResponse(provider: 'claude', content: $content, promptTokens: 1, completionTokens: 1),
            );
        });
    }

    private function makeAudit(Website $website): Audit
    {
        return Audit::create([
            'website_id' => $website->id, 'user_id' => $website->user_id,
            'status' => 'completed', 'max_pages' => 5, 'completed_at' => now(),
        ]);
    }

    public function test_discovery_is_skipped_when_the_plan_does_not_include_it(): void
    {
        $user = $this->makeUser(['features' => [PlanFeature::CompetitorDiscovery->value => false]]);
        $website = $this->makeWebsite($user, $this->fullProfile());

        $this->assertFalse(app(CompetitorDiscoveryService::class)->shouldDiscoverFor($website));
    }

    public function test_discovery_is_skipped_when_the_owner_named_their_own_competitors(): void
    {
        $user = $this->makeUser(['features' => [PlanFeature::CompetitorDiscovery->value => true]]);
        $website = $this->makeWebsite($user, $this->fullProfile() + ['competitors' => ['rival.example']]);

        // Discovery fills a gap; it does not second-guess the owner.
        $this->assertFalse(app(CompetitorDiscoveryService::class)->shouldDiscoverFor($website));
    }

    public function test_discovery_is_skipped_when_there_is_no_business_context_to_reason_from(): void
    {
        $user = $this->makeUser(['features' => [PlanFeature::CompetitorDiscovery->value => true]]);
        $website = $this->makeWebsite($user);

        // A model guessing competitors for an unknown business produces
        // plausible names with no basis, which is worse than no answer.
        $this->assertFalse(app(CompetitorDiscoveryService::class)->shouldDiscoverFor($website));
    }

    public function test_discovery_stores_suggested_competitors(): void
    {
        $user = $this->makeUser(['features' => [PlanFeature::CompetitorDiscovery->value => true]]);
        $website = $this->makeWebsite($user, $this->fullProfile());

        $this->fakeAi(json_encode([
            ['name' => 'Rival Ceramics', 'domain' => 'rival.example', 'reason' => 'Sells the same handmade range.'],
            ['name' => 'Other Pots', 'domain' => 'https://other.example/shop', 'reason' => 'Overlapping catalogue.'],
        ]));

        app(CompetitorDiscoveryService::class)->discover($website, $this->makeAudit($website));

        $this->assertSame(2, $website->competitorRecords()->count());
        $row = $website->competitorRecords()->where('normalized_domain', 'other.example')->first();
        // A full URL is normalized to its domain like any other input.
        $this->assertNotNull($row);
        $this->assertSame(CompetitorSource::Discovered, $row->source);
    }

    public function test_discovery_never_returns_the_site_itself_as_its_own_competitor(): void
    {
        $user = $this->makeUser(['features' => [PlanFeature::CompetitorDiscovery->value => true]]);
        $website = $this->makeWebsite($user, $this->fullProfile());

        $this->fakeAi(json_encode([
            ['name' => 'Themselves', 'domain' => 'example.com', 'reason' => 'n/a'],
            ['name' => 'Real Rival', 'domain' => 'rival.example', 'reason' => 'Competes.'],
        ]));

        app(CompetitorDiscoveryService::class)->discover($website, $this->makeAudit($website));

        $this->assertSame(['rival.example'], $website->competitorRecords()->pluck('normalized_domain')->all());
    }

    public function test_discovery_discards_entries_that_are_not_resolvable_domains(): void
    {
        $user = $this->makeUser(['features' => [PlanFeature::CompetitorDiscovery->value => true]]);
        $website = $this->makeWebsite($user, $this->fullProfile());

        $this->fakeAi(json_encode([
            ['name' => 'Nonsense', 'domain' => 'not a domain at all', 'reason' => 'x'],
            ['name' => 'Injection', 'domain' => 'javascript:alert(1)', 'reason' => 'x'],
            ['name' => 'Real', 'domain' => 'rival.example', 'reason' => 'Competes.'],
        ]));

        app(CompetitorDiscoveryService::class)->discover($website, $this->makeAudit($website));

        // A hallucinated "domain" is rejected here rather than becoming a
        // row that later fails to crawl.
        $this->assertSame(['rival.example'], $website->competitorRecords()->pluck('normalized_domain')->all());
    }

    public function test_discovery_never_relabels_an_owner_named_competitor(): void
    {
        $user = $this->makeUser(['features' => [PlanFeature::CompetitorDiscovery->value => true]]);
        $website = $this->makeWebsite($user, $this->fullProfile());

        WebsiteCompetitor::create([
            'website_id' => $website->id, 'name' => 'My Rival',
            'normalized_domain' => 'rival.example', 'source' => CompetitorSource::User,
        ]);

        $this->fakeAi(json_encode([['name' => 'Renamed By AI', 'domain' => 'rival.example', 'reason' => 'x']]));

        app(CompetitorDiscoveryService::class)->discover($website, $this->makeAudit($website));

        $row = $website->competitorRecords()->sole();
        // Re-labelling it would misattribute the owner's own input.
        $this->assertSame(CompetitorSource::User, $row->source);
        $this->assertSame('My Rival', $row->name);
    }

    // --- Competitor reporting ---

    public function test_an_unanalyzed_competitor_reports_null_scores_not_zero(): void
    {
        $user = $this->makeUser();
        $website = $this->makeWebsite($user);
        WebsiteCompetitor::create([
            'website_id' => $website->id, 'name' => 'Rival',
            'normalized_domain' => 'rival.example', 'source' => CompetitorSource::User,
        ]);

        $competitor = $this->asUser($user)
            ->getJson("/api/websites/{$website->id}")
            ->json('data.competitors.0');

        // A competitor we could not reach must never render as one doing
        // badly — that would be a false claim about a real company.
        $this->assertNull($competitor['aio_score']);
        $this->assertFalse($competitor['is_analyzed']);
    }

    public function test_owner_named_competitors_are_listed_before_discovered_ones(): void
    {
        $user = $this->makeUser();
        $website = $this->makeWebsite($user);

        WebsiteCompetitor::create([
            'website_id' => $website->id, 'normalized_domain' => 'found.example',
            'source' => CompetitorSource::Discovered, 'aio_score' => 90, 'analyzed_at' => now(),
        ]);
        WebsiteCompetitor::create([
            'website_id' => $website->id, 'normalized_domain' => 'named.example',
            'source' => CompetitorSource::User, 'aio_score' => 10, 'analyzed_at' => now(),
        ]);

        $domains = $this->asUser($user)
            ->getJson("/api/websites/{$website->id}")
            ->json('data.competitors.*.domain');

        // What the owner told us outranks what a model guessed, regardless
        // of score.
        $this->assertSame(['named.example', 'found.example'], $domains);
    }
}
