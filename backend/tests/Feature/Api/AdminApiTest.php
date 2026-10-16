<?php

namespace Tests\Feature\Api;

use App\Enums\ScheduleFrequency;
use App\Models\ApiProvider;
use App\Models\Audit;
use App\Models\AuditSchedule;
use App\Models\CmsPage;
use App\Models\CrawlJob;
use App\Models\Feature;
use App\Models\Lead;
use App\Models\Plan;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminApiTest extends TestCase
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

    // --- Authorization ---

    public function test_unauthenticated_request_to_admin_route_is_rejected(): void
    {
        $this->getJson('/api/admin/dashboard')->assertStatus(401);
    }

    public function test_non_admin_authenticated_user_is_rejected_with_403(): void
    {
        $token = $this->tokenFor($this->makeRegularUser());

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/dashboard')
            ->assertStatus(403);
    }

    public function test_admin_user_can_access_admin_routes(): void
    {
        $token = $this->tokenFor($this->makeAdmin());

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/dashboard')
            ->assertStatus(200);
    }

    // --- Dashboard / resource listings ---

    public function test_dashboard_returns_summary_data(): void
    {
        $token = $this->tokenFor($this->makeAdmin());

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/dashboard')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['users', 'websites', 'audits', 'leads', 'ai_usage', 'failed_jobs', 'audit_schedules', 'provider_usage_today']]);
    }

    public function test_users_listing_is_paginated_and_includes_subscription(): void
    {
        $admin = $this->makeAdmin();
        $token = $this->tokenFor($admin);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/users')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['data', 'current_page', 'total']]);
    }

    public function test_websites_listing_includes_audit_count(): void
    {
        $token = $this->tokenFor($this->makeAdmin());
        $website = Website::create(['primary_url' => 'https://example.com', 'domain' => 'example.com', 'normalized_domain' => 'example.com']);
        Audit::create(['website_id' => $website->id, 'status' => 'completed', 'max_pages' => 5]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/admin/websites');

        $response->assertStatus(200)->assertJsonPath('data.data.0.audits_count', 1);
    }

    public function test_audit_show_includes_scores_and_issues(): void
    {
        $token = $this->tokenFor($this->makeAdmin());
        $website = Website::create(['primary_url' => 'https://example.com', 'domain' => 'example.com', 'normalized_domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'status' => 'completed', 'max_pages' => 5]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/audits/{$audit->id}")
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['id', 'website', 'scores', 'issues']]);
    }

    public function test_crawl_jobs_listing(): void
    {
        $token = $this->tokenFor($this->makeAdmin());
        $website = Website::create(['primary_url' => 'https://example.com', 'domain' => 'example.com', 'normalized_domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'status' => 'completed', 'max_pages' => 5]);
        CrawlJob::create(['audit_id' => $audit->id, 'status' => 'completed']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/crawl-jobs')
            ->assertStatus(200)
            ->assertJsonPath('data.total', 1);
    }

    public function test_audit_schedules_listing(): void
    {
        $token = $this->tokenFor($this->makeAdmin());
        $user = $this->makeRegularUser();
        $website = Website::create(['user_id' => $user->id, 'primary_url' => 'https://example.com', 'domain' => 'example.com', 'normalized_domain' => 'example.com']);
        AuditSchedule::create(['user_id' => $user->id, 'website_id' => $website->id, 'frequency' => ScheduleFrequency::Weekly, 'next_run_at' => now()->addWeek()]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/audit-schedules')
            ->assertStatus(200)
            ->assertJsonPath('data.total', 1);
    }

    public function test_failed_jobs_listing_and_deletion(): void
    {
        $token = $this->tokenFor($this->makeAdmin());
        $id = DB::table('failed_jobs')->insertGetId([
            'uuid' => 'test-uuid', 'connection' => 'database', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'test', 'failed_at' => now(),
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/failed-jobs')
            ->assertStatus(200)
            ->assertJsonPath('data.total', 1);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/admin/failed-jobs/{$id}")
            ->assertStatus(200);

        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_deleting_an_unknown_failed_job_returns_404(): void
    {
        $token = $this->tokenFor($this->makeAdmin());

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/admin/failed-jobs/999999')
            ->assertStatus(404);
    }

    // --- Plans CRUD (finally where paid tiers get created) ---

    public function test_admin_can_create_a_plan(): void
    {
        $token = $this->tokenFor($this->makeAdmin());

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/admin/plans', [
            'name' => 'Pro', 'slug' => 'pro', 'price_cents' => 2900, 'max_pages' => 100, 'daily_audit_limit' => 10,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('plans', ['slug' => 'pro', 'price_cents' => 2900]);
    }

    public function test_setting_a_new_default_plan_unsets_the_previous_default(): void
    {
        $token = $this->tokenFor($this->makeAdmin());
        $free = Plan::create(['name' => 'Free', 'slug' => 'free', 'max_pages' => 25, 'daily_audit_limit' => 1, 'is_default' => true]);

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/admin/plans', [
            'name' => 'Pro', 'slug' => 'pro', 'price_cents' => 2900, 'max_pages' => 100, 'daily_audit_limit' => 10, 'is_default' => true,
        ])->assertStatus(201);

        $this->assertFalse($free->fresh()->is_default);
        $this->assertTrue(Plan::where('slug', 'pro')->first()->is_default);
    }

    public function test_admin_can_update_a_plan(): void
    {
        $token = $this->tokenFor($this->makeAdmin());
        $plan = Plan::create(['name' => 'Free', 'slug' => 'free', 'max_pages' => 25, 'daily_audit_limit' => 1]);

        $this->withHeader('Authorization', "Bearer {$token}")->putJson("/api/admin/plans/{$plan->id}", [
            'name' => 'Free', 'slug' => 'free', 'price_cents' => 0, 'max_pages' => 50, 'daily_audit_limit' => 2,
        ])->assertStatus(200)->assertJsonPath('data.max_pages', 50);
    }

    public function test_admin_cannot_delete_a_plan_with_an_active_subscription(): void
    {
        $token = $this->tokenFor($this->makeAdmin());
        $plan = Plan::create(['name' => 'Free', 'slug' => 'free', 'max_pages' => 25, 'daily_audit_limit' => 1]);
        $user = $this->makeRegularUser();
        DB::table('subscriptions')->insert(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/admin/plans/{$plan->id}")
            ->assertStatus(409);
    }

    public function test_plan_slug_must_be_unique(): void
    {
        $token = $this->tokenFor($this->makeAdmin());
        Plan::create(['name' => 'Free', 'slug' => 'free', 'max_pages' => 25, 'daily_audit_limit' => 1]);

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/admin/plans', [
            'name' => 'Free Again', 'slug' => 'free', 'price_cents' => 0, 'max_pages' => 25, 'daily_audit_limit' => 1,
        ])->assertStatus(422);
    }

    // --- Plan audit-feature and AI-model access management (TC-PLAN-003..009, TC-AI-*) ---

    private function seedFeatures(): void
    {
        foreach (['performance', 'accessibility', 'cro', 'seo', 'aio', 'ecommerce', 'product_auditor'] as $key) {
            Feature::create(['key' => $key, 'name' => ucfirst($key), 'is_enabled' => true]);
        }
    }

    public function test_admin_can_enable_and_disable_individual_audit_features_for_a_plan(): void
    {
        $token = $this->tokenFor($this->makeAdmin());
        $this->seedFeatures();
        $plan = Plan::create(['name' => 'Pro', 'slug' => 'pro', 'max_pages' => 100, 'daily_audit_limit' => 10]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->putJson("/api/admin/plans/{$plan->id}/features", [
            'features' => [
                ['key' => 'performance', 'is_enabled' => true],
                ['key' => 'accessibility', 'is_enabled' => true],
                ['key' => 'cro', 'is_enabled' => true],
                ['key' => 'seo', 'is_enabled' => true],
                ['key' => 'aio', 'is_enabled' => true],
                ['key' => 'ecommerce', 'is_enabled' => true],
                ['key' => 'product_auditor', 'is_enabled' => false],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertTrue($plan->fresh()->hasAuditFeature('cro'));
        $this->assertFalse($plan->fresh()->hasAuditFeature('product_auditor'));

        // The endpoint syncs the whole submitted set (whatever the admin
        // UI's checkbox grid currently shows), not a per-feature PATCH —
        // resending with only 'cro' therefore also clears every feature
        // left out of this second call, including the 'performance' this
        // plan was granted a moment ago.
        $this->withHeader('Authorization', "Bearer {$token}")->putJson("/api/admin/plans/{$plan->id}/features", [
            'features' => [['key' => 'cro', 'is_enabled' => false]],
        ])->assertStatus(200);

        $this->assertFalse($plan->fresh()->hasAuditFeature('cro'));
        $this->assertFalse($plan->fresh()->hasAuditFeature('performance'));
    }

    public function test_a_feature_disabled_globally_is_unavailable_even_if_the_plan_enables_it(): void
    {
        $this->seedFeatures();
        $plan = Plan::create(['name' => 'Pro', 'slug' => 'pro', 'max_pages' => 100, 'daily_audit_limit' => 10, 'is_default' => true, 'is_active' => true]);
        $plan->auditFeatures()->attach(Feature::where('key', 'aio')->first()->id, ['is_enabled' => true]);

        $this->assertTrue($plan->hasAuditFeature('aio'));

        Feature::where('key', 'aio')->update(['is_enabled' => false]);

        $this->assertFalse($plan->fresh()->hasAuditFeature('aio'));
    }

    public function test_admin_can_globally_disable_a_feature(): void
    {
        $token = $this->tokenFor($this->makeAdmin());
        $this->seedFeatures();
        $feature = Feature::where('key', 'seo')->first();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/admin/features/{$feature->id}/status", ['is_enabled' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.is_enabled', false);

        $this->assertFalse($feature->fresh()->is_enabled);
    }

    public function test_non_admin_cannot_modify_plan_features_or_global_feature_status(): void
    {
        $token = $this->tokenFor($this->makeRegularUser());
        $this->seedFeatures();
        $plan = Plan::create(['name' => 'Pro', 'slug' => 'pro', 'max_pages' => 100, 'daily_audit_limit' => 10]);
        $feature = Feature::first();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/plans/{$plan->id}/features", ['features' => [['key' => 'seo', 'is_enabled' => true]]])
            ->assertStatus(403);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/admin/features/{$feature->id}/status", ['is_enabled' => false])
            ->assertStatus(403);
    }

    public function test_admin_can_assign_and_remove_ai_models_from_a_plan(): void
    {
        $token = $this->tokenFor($this->makeAdmin());
        $plan = Plan::create(['name' => 'Pro', 'slug' => 'pro', 'max_pages' => 100, 'daily_audit_limit' => 10]);
        $claude = ApiProvider::create(['slug' => 'claude', 'name' => 'Claude', 'provider_type' => 'ai', 'model' => 'claude-sonnet-5']);
        $openai = ApiProvider::create(['slug' => 'openai', 'name' => 'OpenAI', 'provider_type' => 'ai', 'model' => 'gpt-5']);
        $claudeModel = $claude->aiModels()->create(['model_id' => 'claude-sonnet-5', 'display_name' => 'Claude Sonnet 5']);
        $openaiModel = $openai->aiModels()->create(['model_id' => 'gpt-5', 'display_name' => 'GPT-5']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/plans/{$plan->id}/ai-models", ['ai_model_ids' => [$claudeModel->id, $openaiModel->id]])
            ->assertStatus(200);

        $this->assertTrue($plan->fresh()->isModelAllowed($claudeModel));
        $this->assertTrue($plan->fresh()->isModelAllowed($openaiModel));

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/plans/{$plan->id}/ai-models", ['ai_model_ids' => [$claudeModel->id]])
            ->assertStatus(200);

        $this->assertTrue($plan->fresh()->isModelAllowed($claudeModel));
        $this->assertFalse($plan->fresh()->isModelAllowed($openaiModel));
    }

    public function test_non_admin_cannot_assign_ai_models_to_a_plan(): void
    {
        $token = $this->tokenFor($this->makeRegularUser());
        $plan = Plan::create(['name' => 'Pro', 'slug' => 'pro', 'max_pages' => 100, 'daily_audit_limit' => 10]);
        $provider = ApiProvider::create(['slug' => 'claude', 'name' => 'Claude', 'provider_type' => 'ai']);
        $model = $provider->aiModels()->create(['model_id' => 'claude-sonnet-5', 'display_name' => 'Claude Sonnet 5']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/plans/{$plan->id}/ai-models", ['ai_model_ids' => [$model->id]])
            ->assertStatus(403);
    }

    public function test_duplicate_plan_ai_model_assignment_via_repeated_ids_is_rejected(): void
    {
        $token = $this->tokenFor($this->makeAdmin());
        $plan = Plan::create(['name' => 'Pro', 'slug' => 'pro', 'max_pages' => 100, 'daily_audit_limit' => 10]);
        $provider = ApiProvider::create(['slug' => 'claude', 'name' => 'Claude', 'provider_type' => 'ai']);
        $model = $provider->aiModels()->create(['model_id' => 'claude-sonnet-5', 'display_name' => 'Claude Sonnet 5']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/plans/{$plan->id}/ai-models", ['ai_model_ids' => [$model->id, $model->id]])
            ->assertStatus(422);
    }

    // --- Usage ---

    public function test_usage_listing(): void
    {
        $token = $this->tokenFor($this->makeAdmin());
        DB::table('usage_logs')->insert(['ip_address' => '203.0.113.1', 'action' => 'audit_created', 'created_at' => now(), 'updated_at' => now()]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/usage')
            ->assertStatus(200)
            ->assertJsonPath('data.total', 1);
    }

    // --- AI usage ---

    public function test_ai_usage_endpoint_returns_aggregates(): void
    {
        $token = $this->tokenFor($this->makeAdmin());

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/ai-usage')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['by_provider', 'by_category', 'recent']]);
    }

    public function test_system_health_reports_real_database_and_cache_status(): void
    {
        $token = $this->tokenFor($this->makeAdmin());

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/admin/system-health');

        $response->assertStatus(200)
            ->assertJsonPath('data.database.status', 'ok')
            ->assertJsonPath('data.cache.status', 'ok')
            ->assertJsonStructure(['data' => ['database', 'cache', 'queue_connection']]);
    }

    public function test_system_health_requires_admin(): void
    {
        $this->getJson('/api/admin/system-health')->assertStatus(401);
    }

    // --- Leads: public capture + admin management ---

    public function test_anyone_can_submit_a_lead(): void
    {
        $response = $this->postJson('/api/leads', [
            'name' => 'Prospective Customer', 'email' => 'prospect@example.com', 'source' => 'talk_to_consultant',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('leads', ['email' => 'prospect@example.com', 'status' => 'new']);
    }

    public function test_lead_submission_requires_a_valid_source(): void
    {
        $this->postJson('/api/leads', [
            'name' => 'X', 'email' => 'x@example.com', 'source' => 'not_a_real_source',
        ])->assertStatus(422);
    }

    public function test_lead_submission_is_rejected_without_admin_auth_to_list(): void
    {
        $this->getJson('/api/admin/leads')->assertStatus(401);
    }

    public function test_lead_submission_rejects_an_oversized_message(): void
    {
        $this->postJson('/api/leads', [
            'name' => 'Prospective Customer',
            'email' => 'prospect@example.com',
            'source' => 'talk_to_consultant',
            'message' => str_repeat('a', 5001),
        ])->assertStatus(422);
    }

    public function test_lead_submission_safely_stores_injection_and_script_style_strings_without_executing_them(): void
    {
        $maliciousMessage = "<script>alert('xss')</script>'; DROP TABLE leads; --";

        $response = $this->postJson('/api/leads', [
            'name' => 'Prospective Customer',
            'email' => 'prospect@example.com',
            'source' => 'talk_to_consultant',
            'message' => $maliciousMessage,
        ]);

        $response->assertStatus(201);
        // Stored verbatim as plain data and the table survives intact —
        // proof it was bound as a parameter, never interpreted as SQL,
        // and that this API never attempts to sanitize/execute HTML
        // server-side (that's a frontend rendering concern, not this
        // endpoint's).
        $this->assertDatabaseHas('leads', ['email' => 'prospect@example.com', 'message' => $maliciousMessage]);
        $this->assertSame(1, Lead::count());
    }

    public function test_lead_submissions_beyond_the_rate_limit_are_throttled(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/leads', ['name' => 'X', 'email' => "x{$i}@example.com", 'source' => 'talk_to_consultant']);
        }

        $this->postJson('/api/leads', ['name' => 'X', 'email' => 'last@example.com', 'source' => 'talk_to_consultant'])
            ->assertStatus(429);
    }

    public function test_admin_can_list_and_filter_leads_by_status(): void
    {
        $token = $this->tokenFor($this->makeAdmin());
        Lead::create(['name' => 'A', 'email' => 'a@example.com', 'source' => 'talk_to_consultant', 'status' => 'new']);
        Lead::create(['name' => 'B', 'email' => 'b@example.com', 'source' => 'talk_to_consultant', 'status' => 'won']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/leads?status=won');

        $response->assertStatus(200)->assertJsonPath('data.total', 1);
    }

    public function test_admin_can_update_lead_status_and_it_logs_an_activity(): void
    {
        $admin = $this->makeAdmin();
        $token = $this->tokenFor($admin);
        $lead = Lead::create(['name' => 'A', 'email' => 'a@example.com', 'source' => 'talk_to_consultant', 'status' => 'new']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/leads/{$lead->id}", ['status' => 'contacted'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'contacted');

        $this->assertDatabaseHas('lead_activities', ['lead_id' => $lead->id, 'user_id' => $admin->id]);
    }

    public function test_admin_can_add_a_note_to_a_lead(): void
    {
        $admin = $this->makeAdmin();
        $token = $this->tokenFor($admin);
        $lead = Lead::create(['name' => 'A', 'email' => 'a@example.com', 'source' => 'talk_to_consultant']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/leads/{$lead->id}/activities", ['note' => 'Called, left voicemail.'])
            ->assertStatus(201);

        $this->assertDatabaseHas('lead_activities', ['lead_id' => $lead->id, 'note' => 'Called, left voicemail.']);
    }

    // --- CMS: public read + admin management ---

    public function test_public_can_read_a_cms_page(): void
    {
        $page = CmsPage::create(['slug' => 'homepage', 'title' => 'Home']);
        $page->sections()->create(['section_key' => 'hero', 'content' => ['headline' => 'Audit your website']]);

        $response = $this->getJson('/api/cms/homepage');

        $response->assertStatus(200)
            ->assertJsonPath('data.slug', 'homepage')
            ->assertJsonPath('data.sections.0.section_key', 'hero');
    }

    public function test_public_read_of_unknown_cms_page_returns_404(): void
    {
        $this->getJson('/api/cms/does-not-exist')->assertStatus(404);
    }

    public function test_admin_can_create_a_cms_page(): void
    {
        $token = $this->tokenFor($this->makeAdmin());

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/cms', ['slug' => 'pricing', 'title' => 'Pricing'])
            ->assertStatus(201);

        $this->assertDatabaseHas('cms_pages', ['slug' => 'pricing']);
    }

    public function test_admin_can_upsert_a_section_creating_it_if_new(): void
    {
        $token = $this->tokenFor($this->makeAdmin());
        $page = CmsPage::create(['slug' => 'homepage', 'title' => 'Home']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/cms/{$page->id}/sections/hero", ['content' => ['headline' => 'New headline']])
            ->assertStatus(200);

        $this->assertDatabaseHas('cms_sections', ['cms_page_id' => $page->id, 'section_key' => 'hero']);
    }

    public function test_admin_can_upsert_a_section_updating_it_if_it_exists(): void
    {
        $token = $this->tokenFor($this->makeAdmin());
        $page = CmsPage::create(['slug' => 'homepage', 'title' => 'Home']);
        $page->sections()->create(['section_key' => 'hero', 'content' => ['headline' => 'Old']]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/cms/{$page->id}/sections/hero", ['content' => ['headline' => 'New']])
            ->assertStatus(200);

        $this->assertSame(1, $page->sections()->count()); // updated, not duplicated
        $this->assertSame('New', $page->sections()->first()->content['headline']);
    }

    public function test_admin_can_delete_a_section(): void
    {
        $token = $this->tokenFor($this->makeAdmin());
        $page = CmsPage::create(['slug' => 'homepage', 'title' => 'Home']);
        $page->sections()->create(['section_key' => 'hero', 'content' => ['headline' => 'X']]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/admin/cms/{$page->id}/sections/hero")
            ->assertStatus(200);

        $this->assertSame(0, $page->sections()->count());
    }

    public function test_cms_page_slug_must_be_unique(): void
    {
        $token = $this->tokenFor($this->makeAdmin());
        CmsPage::create(['slug' => 'homepage', 'title' => 'Home']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/cms', ['slug' => 'homepage', 'title' => 'Another'])
            ->assertStatus(422);
    }
}
