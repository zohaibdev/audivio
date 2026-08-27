<?php

namespace Tests\Feature\Api;

use App\Models\ApiProvider;
use App\Models\ApiUsageLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminProviderApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x', 'is_admin' => true]);
    }

    private function makeRegularUser(): User
    {
        return User::create(['name' => 'Regular', 'email' => 'regular@example.com', 'password' => 'x']);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('api')->plainTextToken;
    }

    private function seedProvider(string $slug, string $type = 'ai', ?string $apiKey = null, bool $enabled = false): ApiProvider
    {
        return ApiProvider::create([
            'slug' => $slug,
            'name' => ucfirst($slug),
            'provider_type' => $type,
            'is_enabled' => $enabled,
            'api_key' => $apiKey,
            'model' => $type === 'ai' ? 'test-model' : null,
        ]);
    }

    // --- TC-ADMIN-PROVIDER-001/008: view + authorization ---

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/admin/providers')->assertStatus(401);
    }

    public function test_non_admin_cannot_access_provider_configuration(): void
    {
        $token = $this->tokenFor($this->makeRegularUser());

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/providers')
            ->assertStatus(403);
    }

    public function test_admin_can_view_provider_configuration(): void
    {
        $this->seedProvider('claude');
        $token = $this->tokenFor($this->makeAdmin());

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/providers')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => [['slug', 'name', 'is_enabled', 'masked_key', 'requests_today', 'requests_this_month']]]);
    }

    // --- TC-ADMIN-PROVIDER-002/003/004/005: configure each provider ---

    public function test_admin_can_configure_claude(): void
    {
        $this->seedProvider('claude');
        $token = $this->tokenFor($this->makeAdmin());

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/admin/providers/claude', ['api_key' => 'sk-ant-test-key', 'model' => 'claude-sonnet-5', 'max_tokens' => 4096]);

        $response->assertStatus(200)->assertJsonPath('data.model', 'claude-sonnet-5');
        $this->assertSame('sk-ant-test-key', ApiProvider::where('slug', 'claude')->first()->api_key);
    }

    public function test_admin_can_configure_openai(): void
    {
        $this->seedProvider('openai');
        $token = $this->tokenFor($this->makeAdmin());

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/admin/providers/openai', ['api_key' => 'sk-openai-test', 'model' => 'gpt-5', 'temperature' => 0.7])
            ->assertStatus(200)
            ->assertJsonPath('data.config.temperature', 0.7);
    }

    public function test_admin_can_configure_gemini(): void
    {
        $this->seedProvider('gemini');
        $token = $this->tokenFor($this->makeAdmin());

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/admin/providers/gemini', ['api_key' => 'gemini-test', 'model' => 'gemini-3-pro', 'daily_request_limit' => 100])
            ->assertStatus(200)
            ->assertJsonPath('data.daily_limit', 100);
    }

    public function test_admin_can_configure_google_pagespeed(): void
    {
        $this->seedProvider('pagespeed', type: 'audit');
        $token = $this->tokenFor($this->makeAdmin());

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/admin/providers/pagespeed', ['api_key' => 'pagespeed-test', 'mobile_enabled' => false, 'desktop_enabled' => true]);

        $response->assertStatus(200);
        $provider = ApiProvider::where('slug', 'pagespeed')->first();
        $this->assertFalse($provider->getConfigValue('mobile_enabled'));
        $this->assertTrue($provider->getConfigValue('desktop_enabled'));
    }

    // --- TC-ADMIN-PROVIDER-006: enable/disable ---

    public function test_admin_can_enable_and_disable_a_provider(): void
    {
        $this->seedProvider('claude', apiKey: 'sk-test', enabled: false);
        $token = $this->tokenFor($this->makeAdmin());

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/admin/providers/claude/status', ['is_enabled' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.is_enabled', true);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/admin/providers/claude/status', ['is_enabled' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.is_enabled', false);
    }

    public function test_enabling_a_provider_without_a_key_is_rejected(): void
    {
        $this->seedProvider('claude', apiKey: null, enabled: false);
        $token = $this->tokenFor($this->makeAdmin());

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/admin/providers/claude/status', ['is_enabled' => true])
            ->assertStatus(422);
    }

    // --- TC-ADMIN-PROVIDER-007: key never returned in full ---

    public function test_provider_api_key_is_never_returned_in_full(): void
    {
        $this->seedProvider('claude', apiKey: 'sk-ant-super-secret-value', enabled: true);
        $token = $this->tokenFor($this->makeAdmin());

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/admin/providers/claude');

        $response->assertStatus(200);
        $this->assertStringNotContainsString('sk-ant-super-secret-value', $response->getContent());
        $this->assertArrayNotHasKey('api_key', $response->json('data'));
    }

    // --- TC-ADMIN-PROVIDER-009/010: test connection ---

    public function test_connection_succeeds_with_valid_credentials(): void
    {
        $this->seedProvider('claude', apiKey: 'sk-test', enabled: false);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'OK']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);
        $token = $this->tokenFor($this->makeAdmin());

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/admin/providers/claude/test');

        $response->assertStatus(200)->assertJsonPath('data.success', true);
        $this->assertNotNull($response->json('data.response_time_ms'));
        $this->assertDatabaseHas('api_usage_logs', ['provider' => 'claude', 'service' => 'test_connection', 'success' => 1]);
    }

    public function test_connection_fails_safely_with_invalid_credentials(): void
    {
        $this->seedProvider('claude', apiKey: 'sk-bad-key', enabled: false);
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'invalid api key'], 401)]);
        $token = $this->tokenFor($this->makeAdmin());

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/admin/providers/claude/test');

        $response->assertStatus(200)->assertJsonPath('data.success', false);
        $this->assertStringNotContainsString('sk-bad-key', $response->getContent());
        $this->assertDatabaseHas('api_usage_logs', ['provider' => 'claude', 'service' => 'test_connection', 'success' => 0]);
    }

    // --- TC-USAGE: cross-provider usage analytics ---

    public function test_every_provider_request_creates_a_usage_record(): void
    {
        $this->seedProvider('claude', apiKey: 'sk-test', enabled: false);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [], 'usage' => []])]);
        $token = $this->tokenFor($this->makeAdmin());

        $this->assertSame(0, ApiUsageLog::count());

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/admin/providers/claude/test');

        $this->assertSame(1, ApiUsageLog::count());
    }

    public function test_daily_request_aggregation_is_correct(): void
    {
        // Eloquent's create() always stamps created_at with now() regardless
        // of what's passed in the attributes array, so backdating requires
        // a separate update() after the row exists.
        ApiUsageLog::create(['provider' => 'claude', 'service' => 'ai_analysis', 'success' => true]);
        ApiUsageLog::create(['provider' => 'claude', 'service' => 'ai_analysis', 'success' => true]);
        $yesterday = ApiUsageLog::create(['provider' => 'claude', 'service' => 'ai_analysis', 'success' => false]);
        $yesterday->forceFill(['created_at' => now()->subDay()])->save();
        $token = $this->tokenFor($this->makeAdmin());

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/admin/usage/daily');

        $response->assertStatus(200);
        $rows = collect($response->json('data'));
        $today = $rows->firstWhere('date', now()->toDateString());
        $this->assertSame(2, (int) $today['requests']);
    }

    public function test_usage_provider_filtering_works(): void
    {
        ApiUsageLog::create(['provider' => 'claude', 'service' => 'ai_analysis', 'success' => true]);
        ApiUsageLog::create(['provider' => 'openai', 'service' => 'ai_analysis', 'success' => true]);
        $token = $this->tokenFor($this->makeAdmin());

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/admin/usage/logs?provider=claude');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.total'));
    }

    public function test_usage_date_filtering_works(): void
    {
        ApiUsageLog::create(['provider' => 'claude', 'service' => 'ai_analysis', 'success' => true]);
        $old = ApiUsageLog::create(['provider' => 'claude', 'service' => 'ai_analysis', 'success' => true]);
        $old->forceFill(['created_at' => now()->subDays(10)])->save();
        $token = $this->tokenFor($this->makeAdmin());

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/usage/logs?from='.now()->subDay()->toDateString());

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.total'));
    }

    public function test_failed_requests_are_counted(): void
    {
        ApiUsageLog::create(['provider' => 'claude', 'service' => 'ai_analysis', 'success' => true]);
        ApiUsageLog::create(['provider' => 'claude', 'service' => 'ai_analysis', 'success' => false]);
        $token = $this->tokenFor($this->makeAdmin());

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/admin/usage/summary');

        $response->assertStatus(200)
            ->assertJsonPath('data.total_requests', 2)
            ->assertJsonPath('data.successful_requests', 1)
            ->assertJsonPath('data.failed_requests', 1);
    }

    public function test_token_usage_is_recorded_where_available(): void
    {
        ApiUsageLog::create(['provider' => 'claude', 'service' => 'ai_analysis', 'success' => true, 'prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150]);
        $token = $this->tokenFor($this->makeAdmin());

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/usage/summary')
            ->assertStatus(200)
            ->assertJsonPath('data.total_tokens', 150);
    }

    public function test_estimated_cost_is_calculated_where_supported(): void
    {
        $provider = $this->seedProvider('claude', apiKey: 'sk-test');
        $provider->update(['config' => ['cost_per_1k_input_tokens' => 0.01, 'cost_per_1k_output_tokens' => 0.02]]);

        $this->assertSame(0.0004, $provider->estimateCost(20, 10));
        $this->assertNull((new ApiProvider(['config' => []]))->estimateCost(20, 10));
    }

    public function test_usage_dashboard_loads_real_backend_data(): void
    {
        ApiUsageLog::create(['provider' => 'gemini', 'service' => 'ai_analysis', 'success' => true]);
        $token = $this->tokenFor($this->makeAdmin());

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/admin/usage/by-provider');

        $response->assertStatus(200);
        $this->assertSame('gemini', $response->json('data.0.provider'));
    }
}
