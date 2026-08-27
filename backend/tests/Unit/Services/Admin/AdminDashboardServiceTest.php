<?php

namespace Tests\Unit\Services\Admin;

use App\Models\AiAnalysis;
use App\Models\Audit;
use App\Models\Lead;
use App\Models\User;
use App\Models\Website;
use App\Services\Admin\AdminDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_counts_users_and_admins_separately(): void
    {
        User::create(['name' => 'Regular', 'email' => 'regular@example.com', 'password' => 'x']);
        User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x', 'is_admin' => true]);

        $summary = (new AdminDashboardService)->summary();

        $this->assertSame(2, $summary['users']['total']);
        $this->assertSame(1, $summary['users']['admins']);
    }

    public function test_audits_grouped_by_status(): void
    {
        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com']);
        Audit::create(['website_id' => $website->id, 'status' => 'completed', 'max_pages' => 5]);
        Audit::create(['website_id' => $website->id, 'status' => 'completed', 'max_pages' => 5]);
        Audit::create(['website_id' => $website->id, 'status' => 'failed', 'max_pages' => 5]);

        $summary = (new AdminDashboardService)->summary();

        $this->assertSame(3, $summary['audits']['total']);
        $this->assertSame(2, $summary['audits']['by_status']['completed']);
        $this->assertSame(1, $summary['audits']['by_status']['failed']);
    }

    public function test_leads_grouped_by_status(): void
    {
        Lead::create(['name' => 'A', 'email' => 'a@example.com', 'source' => 'talk_to_consultant', 'status' => 'new']);
        Lead::create(['name' => 'B', 'email' => 'b@example.com', 'source' => 'talk_to_consultant', 'status' => 'converted']);

        $summary = (new AdminDashboardService)->summary();

        $this->assertSame(2, $summary['leads']['total']);
        $this->assertSame(1, $summary['leads']['by_status']['new']);
        $this->assertSame(1, $summary['leads']['by_status']['converted']);
    }

    public function test_ai_usage_separates_successful_from_failed_calls_and_sums_tokens(): void
    {
        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'status' => 'completed', 'max_pages' => 5]);

        AiAnalysis::create(['audit_id' => $audit->id, 'category' => 'aio', 'provider' => 'claude', 'context_hash' => 'a', 'prompt' => 'x', 'response' => 'ok', 'prompt_tokens' => 100, 'completion_tokens' => 50]);
        AiAnalysis::create(['audit_id' => $audit->id, 'category' => 'conversion', 'provider' => 'claude', 'context_hash' => 'b', 'prompt' => 'x', 'error' => 'no key']);

        $summary = (new AdminDashboardService)->summary();

        $this->assertSame(1, $summary['ai_usage']['total_calls']);
        $this->assertSame(1, $summary['ai_usage']['failed_calls']);
        $this->assertSame(100, $summary['ai_usage']['total_prompt_tokens']);
        $this->assertSame(50, $summary['ai_usage']['total_completion_tokens']);
    }

    public function test_empty_database_returns_zeros_not_errors(): void
    {
        $summary = (new AdminDashboardService)->summary();

        $this->assertSame(0, $summary['users']['total']);
        $this->assertSame(0, $summary['audits']['total']);
        $this->assertSame(0, $summary['leads']['total']);
        $this->assertSame(0, $summary['ai_usage']['total_calls']);
        $this->assertSame(0, $summary['failed_jobs']['total']);
    }
}
