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
            'password' => 'correct-horse-battery-staple1',
            'password_confirmation' => 'correct-horse-battery-staple1',
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
            'password' => 'correct-horse-battery-staple1',
            'password_confirmation' => 'correct-horse-battery-staple1',
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
            'password' => 'correct-horse-battery-staple1',
            'password_confirmation' => 'correct-horse-battery-staple1',
        ])->assertStatus(422);
    }

    public function test_registration_rejects_mismatched_password_confirmation(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery-staple1',
            'password_confirmation' => 'different',
        ])->assertStatus(422);
    }

    public function test_registration_rejects_a_password_with_no_numbers(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'lettersonly',
            'password_confirmation' => 'lettersonly',
        ])->assertStatus(422);
    }

    public function test_registration_rejects_a_password_under_eight_characters(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'ab1',
            'password_confirmation' => 'ab1',
        ])->assertStatus(422);
    }

    public function test_registration_rejects_an_oversized_name(): void
    {
        $this->postJson('/api/register', [
            'name' => str_repeat('a', 256),
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery-staple1',
            'password_confirmation' => 'correct-horse-battery-staple1',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'ada@example.com']);
    }

    public function test_registration_safely_stores_an_injection_style_name_without_executing_it(): void
    {
        $maliciousName = "Robert'); DROP TABLE users; --";

        $this->postJson('/api/register', [
            'name' => $maliciousName,
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery-staple1',
            'password_confirmation' => 'correct-horse-battery-staple1',
        ])->assertStatus(201);

        // The users table must still exist and hold exactly this one row
        // — proof the string was bound as data, never interpreted as SQL.
        $this->assertDatabaseHas('users', ['name' => $maliciousName, 'email' => 'ada@example.com']);
        $this->assertSame(1, User::count());
    }

    public function test_login_with_correct_credentials_returns_a_token(): void
    {
        User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'correct-horse-battery-staple1']);

        $response = $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery-staple1',
        ]);

        $response->assertStatus(200)->assertJsonStructure(['data' => ['user', 'token']]);
    }

    public function test_registration_response_includes_the_new_subscription_and_plan(): void
    {
        Plan::create(['name' => 'Free', 'slug' => 'free', 'max_pages' => 25, 'daily_audit_limit' => 1, 'is_default' => true]);

        $response = $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery-staple1',
            'password_confirmation' => 'correct-horse-battery-staple1',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.user.subscription.plan.slug', 'free');
    }

    public function test_login_response_includes_the_current_subscription_and_plan(): void
    {
        $plan = Plan::create(['name' => 'Free', 'slug' => 'free', 'max_pages' => 25, 'daily_audit_limit' => 1, 'is_default' => true]);
        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'correct-horse-battery-staple1']);
        $user->subscription()->create(['plan_id' => $plan->id, 'started_at' => now()]);

        $response = $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery-staple1',
        ]);

        $response->assertStatus(200)->assertJsonPath('data.user.subscription.plan.slug', 'free');
    }

    public function test_login_with_wrong_password_is_rejected(): void
    {
        User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'correct-horse-battery-staple1']);

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

    public function test_own_profile_includes_the_current_subscription_and_plan(): void
    {
        $plan = Plan::create(['name' => 'Free', 'slug' => 'free', 'max_pages' => 25, 'daily_audit_limit' => 1, 'is_default' => true]);
        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
        $user->subscription()->create(['plan_id' => $plan->id, 'started_at' => now()]);
        $token = $user->createToken('api')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/user')
            ->assertStatus(200)
            ->assertJsonPath('data.subscription.plan.slug', 'free');
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

    public function test_successful_api_responses_include_baseline_security_headers(): void
    {
        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/user');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_exception_rendered_api_responses_also_include_security_headers(): void
    {
        // A middleware's post-$next() code would miss this case — an
        // AuthenticationException thrown before $next() unwinds straight
        // to the exception handler. This proves the RequestHandled-event
        // approach covers it anyway.
        $response = $this->getJson('/api/user');

        $response->assertStatus(401);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_login_attempts_beyond_the_rate_limit_are_throttled(): void
    {
        User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'correct-horse-battery-staple1']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'wrong-password']);
        }

        $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'wrong-password'])
            ->assertStatus(429);
    }

    public function test_registration_attempts_beyond_the_rate_limit_are_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/register', ['name' => 'X', 'email' => "x{$i}@example.com", 'password' => 'x', 'password_confirmation' => 'y']);
        }

        $this->postJson('/api/register', ['name' => 'X', 'email' => 'last@example.com', 'password' => 'x', 'password_confirmation' => 'y'])
            ->assertStatus(429);
    }
}
