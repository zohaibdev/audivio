<?php

namespace Tests\Feature\Api;

use App\Enums\ScheduleFrequency;
use App\Models\AuditSchedule;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditScheduleApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(bool $canSchedule = true, int $fullRecrawls = 1, string $email = 'ada@example.com'): User
    {
        $plan = Plan::create([
            'name' => 'Paid',
            'slug' => 'paid-'.uniqid(),
            'max_pages' => 100,
            'daily_audit_limit' => 5,
            'full_recrawls' => $fullRecrawls,
            'features' => ['scheduled_audits' => $canSchedule],
        ]);
        $user = User::create(['name' => 'Ada', 'email' => $email, 'password' => 'x']);
        Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'started_at' => now()]);

        return $user;
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('api')->plainTextToken;
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/audit-schedules', ['website_id' => 1, 'frequency' => 'daily'])
            ->assertStatus(401);
    }

    public function test_a_user_can_schedule_their_own_website(): void
    {
        $user = $this->makeUser();
        $website = Website::create(['user_id' => $user->id, 'url' => 'https://example.com', 'domain' => 'example.com']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/audit-schedules', ['website_id' => $website->id, 'frequency' => 'weekly']);

        $response->assertStatus(201)->assertJsonPath('data.frequency', 'weekly');
        $this->assertDatabaseHas('audit_schedules', ['user_id' => $user->id, 'website_id' => $website->id]);
    }

    public function test_a_user_cannot_schedule_someone_elses_website(): void
    {
        $owner = $this->makeUser(email: 'owner@example.com');
        $website = Website::create(['user_id' => $owner->id, 'url' => 'https://example.com', 'domain' => 'example.com']);

        $other = $this->makeUser(email: 'other@example.com');
        $this->app['auth']->forgetGuards();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($other))
            ->postJson('/api/audit-schedules', ['website_id' => $website->id, 'frequency' => 'weekly']);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('audit_schedules', ['website_id' => $website->id]);
    }

    public function test_a_plan_without_the_scheduled_audits_feature_is_rejected(): void
    {
        $user = $this->makeUser(canSchedule: false);
        $website = Website::create(['user_id' => $user->id, 'url' => 'https://example.com', 'domain' => 'example.com']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/audit-schedules', ['website_id' => $website->id, 'frequency' => 'weekly']);

        $response->assertStatus(403);
    }

    public function test_a_second_website_beyond_the_plans_full_recrawls_limit_is_rejected(): void
    {
        $user = $this->makeUser(fullRecrawls: 1);
        $websiteOne = Website::create(['user_id' => $user->id, 'url' => 'https://one.example.com', 'domain' => 'one.example.com']);
        $websiteTwo = Website::create(['user_id' => $user->id, 'url' => 'https://two.example.com', 'domain' => 'two.example.com']);
        $token = $this->tokenFor($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/audit-schedules', ['website_id' => $websiteOne->id, 'frequency' => 'daily'])
            ->assertStatus(201);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/audit-schedules', ['website_id' => $websiteTwo->id, 'frequency' => 'daily'])
            ->assertStatus(403);
    }

    public function test_a_website_cannot_be_scheduled_twice(): void
    {
        $user = $this->makeUser(fullRecrawls: 5);
        $website = Website::create(['user_id' => $user->id, 'url' => 'https://example.com', 'domain' => 'example.com']);
        $token = $this->tokenFor($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/audit-schedules', ['website_id' => $website->id, 'frequency' => 'daily'])
            ->assertStatus(201);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/audit-schedules', ['website_id' => $website->id, 'frequency' => 'weekly'])
            ->assertStatus(422);
    }

    public function test_invalid_frequency_is_rejected(): void
    {
        $user = $this->makeUser();
        $website = Website::create(['user_id' => $user->id, 'url' => 'https://example.com', 'domain' => 'example.com']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/audit-schedules', ['website_id' => $website->id, 'frequency' => 'yearly'])
            ->assertStatus(422);
    }

    public function test_a_user_can_list_only_their_own_schedules(): void
    {
        $user = $this->makeUser(fullRecrawls: 5, email: 'mine@example.com');
        $website = Website::create(['user_id' => $user->id, 'url' => 'https://example.com', 'domain' => 'example.com']);
        AuditSchedule::create(['user_id' => $user->id, 'website_id' => $website->id, 'frequency' => ScheduleFrequency::Daily, 'next_run_at' => now()->addDay()]);

        $other = $this->makeUser(fullRecrawls: 5, email: 'other@example.com');
        $otherWebsite = Website::create(['user_id' => $other->id, 'url' => 'https://other.example.com', 'domain' => 'other.example.com']);
        AuditSchedule::create(['user_id' => $other->id, 'website_id' => $otherWebsite->id, 'frequency' => ScheduleFrequency::Weekly, 'next_run_at' => now()->addWeek()]);

        $this->app['auth']->forgetGuards();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/audit-schedules');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($website->id, $response->json('data.0.website_id'));
    }

    public function test_a_user_can_pause_their_own_schedule(): void
    {
        $user = $this->makeUser();
        $website = Website::create(['user_id' => $user->id, 'url' => 'https://example.com', 'domain' => 'example.com']);
        $schedule = AuditSchedule::create(['user_id' => $user->id, 'website_id' => $website->id, 'frequency' => ScheduleFrequency::Daily, 'next_run_at' => now()->addDay()]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->putJson("/api/audit-schedules/{$schedule->id}", ['is_active' => false]);

        $response->assertStatus(200)->assertJsonPath('data.is_active', false);
    }

    public function test_a_user_cannot_update_someone_elses_schedule(): void
    {
        $owner = $this->makeUser(email: 'owner@example.com');
        $website = Website::create(['user_id' => $owner->id, 'url' => 'https://example.com', 'domain' => 'example.com']);
        $schedule = AuditSchedule::create(['user_id' => $owner->id, 'website_id' => $website->id, 'frequency' => ScheduleFrequency::Daily, 'next_run_at' => now()->addDay()]);

        $other = $this->makeUser(email: 'other@example.com');
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($other))
            ->putJson("/api/audit-schedules/{$schedule->id}", ['is_active' => false])
            ->assertStatus(404);
    }

    public function test_a_user_can_delete_their_own_schedule(): void
    {
        $user = $this->makeUser();
        $website = Website::create(['user_id' => $user->id, 'url' => 'https://example.com', 'domain' => 'example.com']);
        $schedule = AuditSchedule::create(['user_id' => $user->id, 'website_id' => $website->id, 'frequency' => ScheduleFrequency::Daily, 'next_run_at' => now()->addDay()]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->deleteJson("/api/audit-schedules/{$schedule->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('audit_schedules', ['id' => $schedule->id]);
    }

    public function test_a_user_cannot_delete_someone_elses_schedule(): void
    {
        $owner = $this->makeUser(email: 'owner@example.com');
        $website = Website::create(['user_id' => $owner->id, 'url' => 'https://example.com', 'domain' => 'example.com']);
        $schedule = AuditSchedule::create(['user_id' => $owner->id, 'website_id' => $website->id, 'frequency' => ScheduleFrequency::Daily, 'next_run_at' => now()->addDay()]);

        $other = $this->makeUser(email: 'other@example.com');
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($other))
            ->deleteJson("/api/audit-schedules/{$schedule->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('audit_schedules', ['id' => $schedule->id]);
    }
}
