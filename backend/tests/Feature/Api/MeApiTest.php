<?php

namespace Tests\Feature\Api;

use App\Models\ApiProvider;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeApiTest extends TestCase
{
    use RefreshDatabase;

    private function seedFeatures(): void
    {
        foreach (['performance', 'accessibility', 'cro', 'seo', 'aio', 'ecommerce', 'product_auditor'] as $key) {
            Feature::create(['key' => $key, 'name' => ucfirst($key), 'is_enabled' => true]);
        }
    }

    public function test_anonymous_visitor_sees_the_default_plans_features(): void
    {
        $this->seedFeatures();
        $free = Plan::create(['name' => 'Free', 'slug' => 'free', 'max_pages' => 25, 'daily_audit_limit' => 1, 'is_default' => true, 'is_active' => true]);
        $free->auditFeatures()->attach(Feature::where('key', 'seo')->first()->id, ['is_enabled' => true]);

        $response = $this->getJson('/api/me/features');

        $response->assertStatus(200);
        $seo = collect($response->json('data'))->firstWhere('key', 'seo');
        $aio = collect($response->json('data'))->firstWhere('key', 'aio');

        $this->assertTrue($seo['enabled']);
        $this->assertFalse($aio['enabled']);
    }

    public function test_a_locked_feature_lists_which_plans_include_it(): void
    {
        $this->seedFeatures();
        $free = Plan::create(['name' => 'Free', 'slug' => 'free', 'max_pages' => 25, 'daily_audit_limit' => 1, 'is_default' => true, 'is_active' => true]);
        $pro = Plan::create(['name' => 'Pro', 'slug' => 'pro', 'max_pages' => 100, 'daily_audit_limit' => 10, 'is_active' => true]);
        $pro->auditFeatures()->attach(Feature::where('key', 'aio')->first()->id, ['is_enabled' => true]);

        $response = $this->getJson('/api/me/features');

        $aio = collect($response->json('data'))->firstWhere('key', 'aio');
        $this->assertFalse($aio['enabled']);
        $this->assertSame(['Pro'], collect($aio['plans_with_access'])->pluck('name')->all());
    }

    public function test_a_globally_disabled_feature_is_not_advertised_as_available_anywhere(): void
    {
        $this->seedFeatures();
        Feature::where('key', 'aio')->update(['is_enabled' => false]);
        $pro = Plan::create(['name' => 'Pro', 'slug' => 'pro', 'max_pages' => 100, 'daily_audit_limit' => 10, 'is_default' => true, 'is_active' => true]);
        $pro->auditFeatures()->attach(Feature::where('key', 'aio')->first()->id, ['is_enabled' => true]);

        $response = $this->getJson('/api/me/features');

        $this->assertNull(collect($response->json('data'))->firstWhere('key', 'aio'));
    }

    public function test_logged_in_user_sees_their_own_plans_allowed_ai_models(): void
    {
        $plan = Plan::create(['name' => 'Pro', 'slug' => 'pro', 'max_pages' => 100, 'daily_audit_limit' => 10]);
        $provider = ApiProvider::create([
            'slug' => 'claude', 'name' => 'Claude', 'provider_type' => 'ai',
            'is_enabled' => true, 'api_key' => 'test-key', 'model' => 'claude-sonnet-5',
        ]);
        $model = $provider->aiModels()->create(['model_id' => 'claude-sonnet-5', 'display_name' => 'Claude Sonnet 5']);
        $plan->allowedAiModels()->attach($model->id);

        $user = User::create(['name' => 'User', 'email' => 'user@example.com', 'password' => 'x']);
        Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'started_at' => now()]);
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/me/ai-models');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame('claude-sonnet-5', $response->json('data.0.model_id'));
        $this->assertArrayNotHasKey('input_cost_per_1k_tokens', $response->json('data.0'));
    }

    public function test_ai_models_not_assigned_to_the_plan_are_not_exposed(): void
    {
        $plan = Plan::create(['name' => 'Pro', 'slug' => 'pro', 'max_pages' => 100, 'daily_audit_limit' => 10, 'is_default' => true, 'is_active' => true]);
        $provider = ApiProvider::create([
            'slug' => 'claude', 'name' => 'Claude', 'provider_type' => 'ai',
            'is_enabled' => true, 'api_key' => 'test-key', 'model' => 'claude-sonnet-5',
        ]);
        $provider->aiModels()->create(['model_id' => 'claude-sonnet-5', 'display_name' => 'Claude Sonnet 5']);
        // Not attached to $plan at all.

        $response = $this->getJson('/api/me/ai-models');

        $response->assertStatus(200)->assertJsonCount(0, 'data');
    }
}
