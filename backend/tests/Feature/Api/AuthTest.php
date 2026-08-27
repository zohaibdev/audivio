<?php

namespace Tests\Feature\Api;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_a_user_and_returns_a_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'ada@example.com')
            ->assertJsonStructure(['data' => ['user', 'token']]);

        $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);
    }

    public function test_registration_subscribes_the_user_to_the_default_plan(): void
    {
        Plan::create(['name' => 'Free', 'slug' => 'free', 'max_pages' => 25, 'daily_audit_limit' => 1, 'is_default' => true]);

        $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertStatus(201);

        $user = User::where('email', 'ada@example.com')->first();
        $this->assertNotNull($user->subscription);
        $this->assertSame('free', $user->plan()->slug);
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        User::create(['name' => 'Existing', 'email' => 'ada@example.com', 'password' => 'x']);

        $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertStatus(422);
    }

    public function test_registration_rejects_mismatched_password_confirmation(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'different',
        ])->assertStatus(422);
    }

    public function test_login_with_correct_credentials_returns_a_token(): void
    {
        User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'correct-horse-battery-staple']);

        $response = $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery-staple',
        ]);

        $response->assertStatus(200)->assertJsonStructure(['data' => ['user', 'token']]);
    }

    public function test_login_with_wrong_password_is_rejected(): void
    {
        User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'correct-horse-battery-staple']);

        $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    public function test_login_with_unknown_email_is_rejected(): void
    {
        $this->postJson('/api/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ])->assertStatus(401);
    }

    public function test_authenticated_user_can_fetch_their_own_profile(): void
    {
        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
        $token = $user->createToken('api')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/user')
            ->assertStatus(200)
            ->assertJsonPath('data.email', 'ada@example.com');
    }

    public function test_unauthenticated_request_to_user_endpoint_is_rejected(): void
    {
        $this->getJson('/api/user')->assertStatus(401);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
        $token = $user->createToken('api')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/logout')
            ->assertStatus(200);

        // Asserted against the DB directly, not a second simulated request:
        // within one test method, Sanctum's guard caches its resolved user
        // for the lifetime of the shared app container, so a second
        // getJson() call here would still "authenticate" even though the
        // token is genuinely gone — an artifact of the testing harness
        // reusing one container across simulated requests, not something
        // a real server does (each real HTTP request is a fresh process).
        $this->assertSame(0, $user->tokens()->count());
    }
}
