<?php

namespace Tests\Feature\Api;

use App\Models\ApiProvider;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAiModelApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x', 'is_admin' => true]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('api')->plainTextToken;
    }

    private function claude(): ApiProvider
    {
        return ApiProvider::create(['slug' => 'claude', 'name' => 'Claude', 'provider_type' => 'ai', 'model' => 'claude-sonnet-5']);
    }

    public function test_admin_can_list_ai_models(): void
    {
        $token = $this->tokenFor($this->makeAdmin());
        $this->claude()->aiModels()->create(['model_id' => 'claude-sonnet-5', 'display_name' => 'Claude Sonnet 5']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/ai/models')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_admin_can_add_an_ai_model(): void
    {
        $token = $this->tokenFor($this->makeAdmin());
        $provider = $this->claude();

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/admin/ai/models', [
            'api_provider_id' => $provider->id,
            'model_id' => 'claude-opus-5',
            'display_name' => 'Claude Opus 5',
            'input_cost_per_1k_tokens' => 0.015,
            'output_cost_per_1k_tokens' => 0.075,
            'context_window' => 200000,
            'capabilities' => ['vision', 'reasoning'],
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('ai_models', ['model_id' => 'claude-opus-5', 'api_provider_id' => $provider->id]);
    }

    public function test_duplicate_model_id_for_the_same_provider_is_rejected(): void
    {
        $token = $this->tokenFor($this->makeAdmin());
        $provider = $this->claude();
        $provider->aiModels()->create(['model_id' => 'claude-sonnet-5', 'display_name' => 'Claude Sonnet 5']);

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/admin/ai/models', [
            'api_provider_id' => $provider->id,
            'model_id' => 'claude-sonnet-5',
            'display_name' => 'Duplicate',
        ])->assertStatus(422);
    }

    public function test_a_non_ai_provider_cannot_receive_an_ai_model(): void
    {
        $token = $this->tokenFor($this->makeAdmin());
        $pagespeed = ApiProvider::create(['slug' => 'pagespeed', 'name' => 'PageSpeed', 'provider_type' => 'audit']);

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/admin/ai/models', [
            'api_provider_id' => $pagespeed->id,
            'model_id' => 'v5',
            'display_name' => 'PageSpeed v5',
        ])->assertStatus(422);
    }

    public function test_admin_can_edit_and_disable_an_ai_model(): void
    {
        $token = $this->tokenFor($this->makeAdmin());
        $model = $this->claude()->aiModels()->create(['model_id' => 'claude-sonnet-5', 'display_name' => 'Claude Sonnet 5']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/ai/models/{$model->id}", ['is_enabled' => false, 'display_name' => 'Claude Sonnet 5 (retired)'])
            ->assertStatus(200)
            ->assertJsonPath('data.is_enabled', false);

        $this->assertFalse($model->fresh()->is_enabled);
    }

    public function test_a_globally_disabled_model_is_not_usable_even_if_a_plan_allows_it(): void
    {
        $model = $this->claude()->aiModels()->create(['model_id' => 'claude-sonnet-5', 'display_name' => 'Claude Sonnet 5', 'is_enabled' => false]);
        $plan = Plan::create(['name' => 'Pro', 'slug' => 'pro', 'max_pages' => 100, 'daily_audit_limit' => 10]);
        $plan->allowedAiModels()->attach($model->id);

        $this->assertTrue($plan->isModelAllowed($model)); // allowed by the plan...
        $this->assertFalse($model->fresh()->isUsable()); // ...but not actually usable — disabled globally
    }

    public function test_admin_cannot_delete_a_model_assigned_to_a_plan(): void
    {
        $token = $this->tokenFor($this->makeAdmin());
        $model = $this->claude()->aiModels()->create(['model_id' => 'claude-sonnet-5', 'display_name' => 'Claude Sonnet 5']);
        $plan = Plan::create(['name' => 'Pro', 'slug' => 'pro', 'max_pages' => 100, 'daily_audit_limit' => 10]);
        $plan->allowedAiModels()->attach($model->id);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/admin/ai/models/{$model->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('ai_models', ['id' => $model->id]);
    }

    public function test_admin_can_delete_an_unassigned_model(): void
    {
        $token = $this->tokenFor($this->makeAdmin());
        $model = $this->claude()->aiModels()->create(['model_id' => 'claude-sonnet-5', 'display_name' => 'Claude Sonnet 5']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/admin/ai/models/{$model->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('ai_models', ['id' => $model->id]);
    }

    public function test_non_admin_cannot_manage_ai_models(): void
    {
        $token = $this->tokenFor(User::create(['name' => 'Regular', 'email' => 'regular@example.com', 'password' => 'x']));
        $provider = $this->claude();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/ai/models')
            ->assertStatus(403);

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/admin/ai/models', [
            'api_provider_id' => $provider->id, 'model_id' => 'x', 'display_name' => 'x',
        ])->assertStatus(403);
    }

    public function test_unauthenticated_request_to_ai_models_is_rejected(): void
    {
        $this->getJson('/api/admin/ai/models')->assertStatus(401);
    }
}
