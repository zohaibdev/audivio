<?php

namespace Tests\Feature\Api;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_anyone_can_list_plans(): void
    {
        Plan::create(['name' => 'Free', 'slug' => 'free', 'max_pages' => 25, 'daily_audit_limit' => 1, 'is_default' => true]);
        Plan::create(['name' => 'Pro', 'slug' => 'pro', 'price_cents' => 2900, 'max_pages' => 100, 'daily_audit_limit' => 5]);

        $response = $this->getJson('/api/plans');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_plans_are_ordered_by_price(): void
    {
        Plan::create(['name' => 'Pro', 'slug' => 'pro', 'price_cents' => 2900, 'max_pages' => 100, 'daily_audit_limit' => 5]);
        Plan::create(['name' => 'Free', 'slug' => 'free', 'max_pages' => 25, 'daily_audit_limit' => 1, 'is_default' => true]);

        $response = $this->getJson('/api/plans');

        $this->assertSame('free', $response->json('data.0.slug'));
        $this->assertSame('pro', $response->json('data.1.slug'));
    }
}
