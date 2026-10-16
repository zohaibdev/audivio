<?php

namespace Tests\Unit\Services\AI;

use App\Models\ApiProvider;
use App\Models\Audit;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;
use App\Services\AI\PlanAiModelResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanAiModelResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): PlanAiModelResolver
    {
        return app(PlanAiModelResolver::class);
    }

    private function makeAudit(?Plan $plan): Audit
    {
        $website = Website::create(['primary_url' => 'https://example.com', 'domain' => 'example.com', 'normalized_domain' => 'example.com']);
        $userId = null;

        if ($plan !== null) {
            $user = User::create(['name' => 'User', 'email' => 'user@example.com', 'password' => 'x']);
            Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'started_at' => now()]);
            $userId = $user->id;
        }

        return Audit::create(['website_id' => $website->id, 'user_id' => $userId, 'status' => 'analyzing', 'max_pages' => 5]);
    }

    public function test_no_plan_at_all_falls_back_to_the_configured_default_provider(): void
    {
        // No plans in the system — same "nothing to gate against yet"
        // bootstrap state as FeatureAccessResolverTest.
        $audit = $this->makeAudit(null);

        $resolved = $this->resolver()->resolveFor($audit);

        // Since §53 resolution returns the provider *and* the model to
        // call, so the provider is one field of the result rather than
        // the result itself.
        $this->assertSame(config('ai.default'), $resolved->provider->name());
    }

    public function test_a_model_allowed_by_the_plan_and_active_on_its_provider_is_used(): void
    {
        $plan = Plan::create(['name' => 'Pro', 'slug' => 'pro', 'max_pages' => 100, 'daily_audit_limit' => 10]);
        $provider = ApiProvider::create([
            'slug' => 'openai', 'name' => 'OpenAI', 'provider_type' => 'ai',
            'is_enabled' => true, 'api_key' => 'test-key', 'model' => 'gpt-5',
        ]);
        $model = $provider->aiModels()->create(['model_id' => 'gpt-5', 'display_name' => 'GPT-5']);
        $plan->allowedAiModels()->attach($model->id);

        $audit = $this->makeAudit($plan);

        $resolved = $this->resolver()->resolveFor($audit);

        $this->assertSame('openai', $resolved->provider->name());
        $this->assertSame('gpt-5', $resolved->model);
    }

    public function test_a_model_allowed_by_the_plan_but_not_the_providers_current_active_model_is_not_used(): void
    {
        $plan = Plan::create(['name' => 'Pro', 'slug' => 'pro', 'max_pages' => 100, 'daily_audit_limit' => 10]);
        $provider = ApiProvider::create([
            'slug' => 'openai', 'name' => 'OpenAI', 'provider_type' => 'ai',
            'is_enabled' => true, 'api_key' => 'test-key', 'model' => 'gpt-5', // provider is actively calling gpt-5...
        ]);
        // ...but the plan only allows a different, non-active catalog entry.
        $otherModel = $provider->aiModels()->create(['model_id' => 'gpt-5-mini', 'display_name' => 'GPT-5 Mini']);
        $plan->allowedAiModels()->attach($otherModel->id);

        $audit = $this->makeAudit($plan);

        $this->assertNull($this->resolver()->resolveFor($audit));
    }

    public function test_a_globally_disabled_model_is_never_used_even_if_the_plan_allows_it(): void
    {
        $plan = Plan::create(['name' => 'Pro', 'slug' => 'pro', 'max_pages' => 100, 'daily_audit_limit' => 10]);
        $provider = ApiProvider::create([
            'slug' => 'openai', 'name' => 'OpenAI', 'provider_type' => 'ai',
            'is_enabled' => true, 'api_key' => 'test-key', 'model' => 'gpt-5',
        ]);
        $model = $provider->aiModels()->create(['model_id' => 'gpt-5', 'display_name' => 'GPT-5', 'is_enabled' => false]);
        $plan->allowedAiModels()->attach($model->id);

        $audit = $this->makeAudit($plan);

        $this->assertNull($this->resolver()->resolveFor($audit));
    }

    public function test_a_model_enabled_globally_but_not_assigned_to_the_plan_is_not_used(): void
    {
        $plan = Plan::create(['name' => 'Pro', 'slug' => 'pro', 'max_pages' => 100, 'daily_audit_limit' => 10]);
        $provider = ApiProvider::create([
            'slug' => 'openai', 'name' => 'OpenAI', 'provider_type' => 'ai',
            'is_enabled' => true, 'api_key' => 'test-key', 'model' => 'gpt-5',
        ]);
        $provider->aiModels()->create(['model_id' => 'gpt-5', 'display_name' => 'GPT-5']);
        // Never attached to $plan.

        $audit = $this->makeAudit($plan);

        $this->assertNull($this->resolver()->resolveFor($audit));
    }

    public function test_the_default_provider_is_preferred_when_multiple_are_allowed(): void
    {
        $plan = Plan::create(['name' => 'Pro', 'slug' => 'pro', 'max_pages' => 100, 'daily_audit_limit' => 10]);

        $openai = ApiProvider::create(['slug' => 'openai', 'name' => 'OpenAI', 'provider_type' => 'ai', 'is_enabled' => true, 'api_key' => 'k', 'model' => 'gpt-5']);
        $openaiModel = $openai->aiModels()->create(['model_id' => 'gpt-5', 'display_name' => 'GPT-5']);
        $plan->allowedAiModels()->attach($openaiModel->id);

        $claude = ApiProvider::create(['slug' => 'claude', 'name' => 'Claude', 'provider_type' => 'ai', 'is_enabled' => true, 'api_key' => 'k', 'model' => 'claude-sonnet-5']);
        $claudeModel = $claude->aiModels()->create(['model_id' => 'claude-sonnet-5', 'display_name' => 'Claude Sonnet 5']);
        $plan->allowedAiModels()->attach($claudeModel->id);

        $audit = $this->makeAudit($plan);

        // config('ai.default') is 'claude' — see config/ai.php.
        $this->assertSame(config('ai.default'), $this->resolver()->resolveFor($audit)->provider->name());
    }
}
