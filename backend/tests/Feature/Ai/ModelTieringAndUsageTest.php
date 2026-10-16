<?php

namespace Tests\Feature\Ai;

use App\Enums\AiTaskComplexity;
use App\Enums\AuditCategory;
use App\Models\AiAnalysis;
use App\Models\AiModel;
use App\Models\ApiProvider;
use App\Models\ApiUsageLog;
use App\Models\Audit;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;
use App\Services\AI\AiAnalysisService;
use App\Services\AI\PlanAiModelResolver;
use App\Services\Provider\ProviderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Model tiering, usage attribution and the AI failure paths (§53).
 *
 * Two things are being protected here: that a cheap task is actually
 * served by a cheap model, and that what gets *recorded* about a call is
 * the call that happened rather than the configuration that was sitting
 * there.
 */
class ModelTieringAndUsageTest extends TestCase
{
    use RefreshDatabase;

    private function provider(string $slug = 'claude', string $model = 'claude-sonnet-5'): ApiProvider
    {
        return ApiProvider::create([
            'slug' => $slug, 'name' => ucfirst($slug), 'provider_type' => 'ai',
            'is_enabled' => true, 'api_key' => 'test-key', 'model' => $model,
        ]);
    }

    private function model(ApiProvider $provider, string $id, AiTaskComplexity $tier, ?float $in = null, ?float $out = null): AiModel
    {
        return $provider->aiModels()->create([
            'model_id' => $id,
            'display_name' => $id,
            'tier' => $tier->value,
            'is_enabled' => true,
            'input_cost_per_1k_tokens' => $in,
            'output_cost_per_1k_tokens' => $out,
        ]);
    }

    private function auditFor(?Plan $plan): Audit
    {
        $userId = null;

        if ($plan !== null) {
            $user = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x']);
            Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'started_at' => now()]);
            $userId = $user->id;
        }

        $website = Website::create([
            'user_id' => $userId, 'primary_url' => 'https://example.com',
            'domain' => 'example.com', 'normalized_domain' => 'example.com',
        ]);

        return Audit::create([
            'website_id' => $website->id, 'user_id' => $userId,
            'status' => 'analyzing', 'max_pages' => 5,
        ]);
    }

    private function plan(): Plan
    {
        return Plan::create(['name' => 'Pro', 'slug' => 'pro', 'max_pages' => 100, 'daily_audit_limit' => 10]);
    }

    private function fakeClaude(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => '[]']],
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 1000],
        ])]);
    }

    // --- Tier selection ---

    public function test_a_simple_task_takes_the_cheapest_model_that_can_do_it(): void
    {
        $plan = $this->plan();
        $provider = $this->provider();

        $cheap = $this->model($provider, 'haiku', AiTaskComplexity::Simple, 0.001, 0.005);
        $dear = $this->model($provider, 'opus', AiTaskComplexity::Complex, 0.015, 0.075);
        $plan->allowedAiModels()->attach([$cheap->id, $dear->id]);

        $resolved = app(PlanAiModelResolver::class)
            ->resolveFor($this->auditFor($plan), AiTaskComplexity::Simple);

        $this->assertSame('haiku', $resolved->model);
    }

    public function test_a_complex_task_is_never_handed_a_simple_model(): void
    {
        $plan = $this->plan();
        $provider = $this->provider();

        $cheap = $this->model($provider, 'haiku', AiTaskComplexity::Simple, 0.001, 0.005);
        $strong = $this->model($provider, 'opus', AiTaskComplexity::Complex, 0.015, 0.075);
        $plan->allowedAiModels()->attach([$cheap->id, $strong->id]);

        $resolved = app(PlanAiModelResolver::class)
            ->resolveFor($this->auditFor($plan), AiTaskComplexity::Complex);

        // Saving tokens on open-ended reasoning buys a worse answer at
        // the same price, so the cheap model is not eligible at all.
        $this->assertSame('opus', $resolved->model);
    }

    public function test_a_model_with_no_price_recorded_is_not_treated_as_free(): void
    {
        $plan = $this->plan();
        $provider = $this->provider();

        $priced = $this->model($provider, 'priced', AiTaskComplexity::Simple, 0.002, 0.002);
        $unpriced = $this->model($provider, 'unpriced', AiTaskComplexity::Simple);
        $plan->allowedAiModels()->attach([$priced->id, $unpriced->id]);

        $resolved = app(PlanAiModelResolver::class)
            ->resolveFor($this->auditFor($plan), AiTaskComplexity::Simple);

        // An unknown rate sorts last. Picking it as "cheapest" would be
        // choosing a model precisely because nobody knows what it costs.
        $this->assertSame('priced', $resolved->model);
    }

    public function test_tiering_never_widens_what_a_plan_allows(): void
    {
        $plan = $this->plan();
        $provider = $this->provider();

        $allowed = $this->model($provider, 'allowed', AiTaskComplexity::Complex, 0.02, 0.02);
        // Cheaper, strong enough, enabled — and simply not on this plan.
        $this->model($provider, 'forbidden', AiTaskComplexity::Simple, 0.0001, 0.0001);
        $plan->allowedAiModels()->attach($allowed->id);

        $resolved = app(PlanAiModelResolver::class)
            ->resolveFor($this->auditFor($plan), AiTaskComplexity::Simple);

        $this->assertSame('allowed', $resolved->model);
    }

    public function test_an_unclassified_catalogue_still_serves_work_the_way_it_did_before(): void
    {
        $plan = $this->plan();
        $provider = $this->provider(model: 'claude-sonnet-5');

        // `tier` defaults to Standard, which is what every model on an
        // existing install gets when this migration runs. Nothing there
        // meets a Complex task, so resolution has to fall back to the
        // pre-§53 rule rather than returning null — otherwise upgrading
        // would silently switch AI off for the categories that need it
        // most.
        $model = $this->model($provider, 'claude-sonnet-5', AiTaskComplexity::Standard, 0.01, 0.01);
        $plan->allowedAiModels()->attach($model->id);

        $resolved = app(PlanAiModelResolver::class)
            ->resolveFor($this->auditFor($plan), AiTaskComplexity::Complex);

        $this->assertNotNull($resolved);
        $this->assertSame('claude-sonnet-5', $resolved->model);
    }

    public function test_a_model_defaults_to_standard_rather_than_to_the_cheapest_tier(): void
    {
        $provider = $this->provider();
        $model = $provider->aiModels()->create(['model_id' => 'unclassified', 'display_name' => 'Unclassified']);

        // An admin who adds a model without thinking about tiers must not
        // thereby volunteer it for every simple task in the system.
        $this->assertSame(AiTaskComplexity::Standard, $model->fresh()->tier);
    }

    public function test_the_category_decides_the_tier_and_config_decides_the_category(): void
    {
        config(['ai.task_complexity.seo' => 'simple', 'ai.task_complexity.aio' => 'complex']);

        $plan = $this->plan();
        $provider = $this->provider();
        $cheap = $this->model($provider, 'haiku', AiTaskComplexity::Simple, 0.001, 0.001);
        $strong = $this->model($provider, 'opus', AiTaskComplexity::Complex, 0.05, 0.05);
        $plan->allowedAiModels()->attach([$cheap->id, $strong->id]);

        $this->fakeClaude();
        $audit = $this->auditFor($plan);
        $service = app(AiAnalysisService::class);

        $service->analyze($audit, AuditCategory::Seo, 'Simple work', ['a' => 1]);
        $service->analyze($audit, AuditCategory::Aio, 'Hard work', ['b' => 2]);

        $used = ApiUsageLog::orderBy('id')->pluck('model')->all();

        $this->assertSame(['haiku', 'opus'], $used);
    }

    // --- Attribution ---

    public function test_usage_records_the_model_that_was_called_not_the_one_configured(): void
    {
        $plan = $this->plan();
        // The provider row is set to Sonnet...
        $provider = $this->provider(model: 'claude-sonnet-5');
        $this->model($provider, 'claude-sonnet-5', AiTaskComplexity::Complex, 0.003, 0.015);
        // ...but a simple task should be served by Haiku.
        $haiku = $this->model($provider, 'claude-haiku', AiTaskComplexity::Simple, 0.0008, 0.004);
        $plan->allowedAiModels()->attach(
            AiModel::where('api_provider_id', $provider->id)->pluck('id')->all()
        );

        config(['ai.task_complexity.seo' => 'simple']);
        $this->fakeClaude();

        app(AiAnalysisService::class)->analyze($this->auditFor($plan), AuditCategory::Seo, 'p', ['x' => 1]);

        $log = ApiUsageLog::firstOrFail();

        // A chart built on the configured model would be describing
        // requests that never happened.
        $this->assertSame($haiku->model_id, $log->model);
        $this->assertSame($haiku->model_id, AiAnalysis::firstOrFail()->model);
    }

    public function test_cost_is_priced_at_the_model_actually_used(): void
    {
        $plan = $this->plan();
        $provider = $this->provider(model: 'expensive');
        $this->model($provider, 'expensive', AiTaskComplexity::Complex, 1.0, 1.0);
        $this->model($provider, 'cheap', AiTaskComplexity::Simple, 0.001, 0.001);
        $plan->allowedAiModels()->attach(AiModel::where('api_provider_id', $provider->id)->pluck('id')->all());

        config(['ai.task_complexity.seo' => 'simple']);
        $this->fakeClaude(); // 1000 prompt + 1000 completion tokens

        app(AiAnalysisService::class)->analyze($this->auditFor($plan), AuditCategory::Seo, 'p', ['x' => 1]);

        // 1000/1000 tokens at 0.001 each per 1k = 0.002, not 2.0. Pricing
        // the cheap call at the flagship's rate would corrupt the single
        // number this dashboard exists to report.
        $this->assertEqualsWithDelta(0.002, (float) ApiUsageLog::firstOrFail()->estimated_cost, 0.0000001);
    }

    public function test_every_ai_call_records_the_prompt_version_that_produced_it(): void
    {
        config(['audit_versions.prompt' => '2.1.0']);

        $plan = $this->plan();
        $provider = $this->provider();
        $model = $this->model($provider, 'claude-sonnet-5', AiTaskComplexity::Complex, 0.01, 0.01);
        $plan->allowedAiModels()->attach($model->id);

        $this->fakeClaude();
        app(AiAnalysisService::class)->analyze($this->auditFor($plan), AuditCategory::Aio, 'p', ['x' => 1]);

        $this->assertSame('2.1.0', ApiUsageLog::firstOrFail()->prompt_version);
        $this->assertSame('2.1.0', AiAnalysis::firstOrFail()->prompt_version);
    }

    // --- The failure paths (§49's bug, found again) ---

    public function test_ai_error_columns_are_text_not_varchar(): void
    {
        // The regression that matters: both columns were varchar(191)
        // while the code writing them truncated to 250, and this server
        // runs STRICT_ALL_TABLES so the over-length write throws.
        $this->assertSame('text', Schema::getColumnType('ai_analyses', 'error'));
        $this->assertSame('text', Schema::getColumnType('api_providers', 'last_error'));
    }

    public function test_a_long_provider_error_is_recorded_rather_than_destroying_the_record(): void
    {
        $plan = $this->plan();
        $provider = $this->provider();
        $model = $this->model($provider, 'claude-sonnet-5', AiTaskComplexity::Complex, 0.01, 0.01);
        $plan->allowedAiModels()->attach($model->id);

        // A provider returning a long body — an HTML error page, a stack
        // trace, a verbose rate-limit explanation.
        Http::fake(['api.anthropic.com/*' => Http::response(str_repeat('boundary exceeded ', 60), 500)]);

        $result = app(AiAnalysisService::class)
            ->analyze($this->auditFor($plan), AuditCategory::Aio, 'p', ['x' => 1]);

        $this->assertNull($result);

        // The whole point: the failure record survives. Before the fix
        // the write recording it threw, so the real error was replaced by
        // a database exception.
        $analysis = AiAnalysis::firstOrFail();
        $this->assertNotNull($analysis->error);
        $this->assertGreaterThan(191, strlen($analysis->error));
    }

    // --- The admin dashboard ---

    public function test_usage_can_be_grouped_by_the_model_that_was_called(): void
    {
        foreach ([['haiku', 2], ['opus', 1]] as [$model, $count]) {
            for ($i = 0; $i < $count; $i++) {
                ApiUsageLog::create([
                    'provider' => 'claude', 'service' => 'ai_analysis', 'request_type' => 'seo',
                    'model' => $model, 'success' => true, 'total_tokens' => 100, 'estimated_cost' => 0.01,
                ]);
            }
        }

        $rows = $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->getJson('/api/admin/usage/by-model')
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('haiku', $rows[0]['model']);
        $this->assertSame(2, (int) $rows[0]['requests']);
    }

    public function test_today_and_this_month_ignore_the_selected_date_range(): void
    {
        ApiUsageLog::create([
            'provider' => 'claude', 'service' => 'ai_analysis', 'request_type' => 'seo',
            'model' => 'haiku', 'success' => true,
        ]);

        // A range that excludes today entirely.
        $summary = $this->withHeader('Authorization', 'Bearer '.$this->adminToken())
            ->getJson('/api/admin/usage/summary?from=2020-01-01&to=2020-01-31')
            ->assertStatus(200)
            ->json('data');

        // The filtered totals are empty, but "today" and "this month" are
        // questions about now — answering them from the selected range
        // would give a number that reads as absolute and isn't.
        $this->assertSame(0, $summary['total_requests']);
        $this->assertSame(1, $summary['requests_today']);
        $this->assertSame(1, $summary['requests_this_month']);
    }

    private function adminToken(): string
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin@example.com',
            'password' => 'x', 'is_admin' => true,
        ]);

        return $admin->createToken('api')->plainTextToken;
    }

    public function test_a_long_failure_message_reaches_the_provider_row(): void
    {
        $provider = $this->provider();

        app(ProviderResolver::class)->recordFailure($provider, str_repeat('x', 800));

        $this->assertGreaterThan(191, strlen($provider->fresh()->last_error));
    }
}
