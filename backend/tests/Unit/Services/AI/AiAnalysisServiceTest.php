<?php

namespace Tests\Unit\Services\AI;

use App\Enums\AuditCategory;
use App\Models\AiAnalysis;
use App\Models\ApiProvider;
use App\Models\ApiUsageLog;
use App\Models\Audit;
use App\Models\Plan;
use App\Models\Website;
use App\Services\AI\AiAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiAnalysisServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeAudit(): Audit
    {
        $website = Website::create(['primary_url' => 'https://example.com', 'domain' => 'example.com', 'normalized_domain' => 'example.com']);

        return Audit::create(['website_id' => $website->id, 'status' => 'analyzing', 'max_pages' => 5]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        ApiProvider::create([
            'slug' => 'claude',
            'name' => 'Claude',
            'provider_type' => 'ai',
            'is_enabled' => true,
            'api_key' => 'test-key',
            'model' => 'claude-sonnet-5',
        ]);
    }

    public function test_second_identical_call_hits_cache_not_the_provider(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => '[]']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        $audit = $this->makeAudit();
        $service = app(AiAnalysisService::class);

        $first = $service->analyze($audit, AuditCategory::Seo, 'Analyze this', ['page' => 'a']);
        $second = $service->analyze($audit, AuditCategory::Seo, 'Analyze this', ['page' => 'a']);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        Http::assertSentCount(1); // second call must not hit the network
        $this->assertSame(1, AiAnalysis::count());
    }

    public function test_different_context_is_not_a_cache_hit(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => '[]']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        $audit = $this->makeAudit();
        $service = app(AiAnalysisService::class);

        $service->analyze($audit, AuditCategory::Seo, 'Analyze this', ['page' => 'a']);
        $service->analyze($audit, AuditCategory::Seo, 'Analyze this', ['page' => 'b']);

        Http::assertSentCount(2);
        $this->assertSame(2, AiAnalysis::count());
    }

    public function test_provider_failure_returns_null_and_logs_an_error_row(): void
    {
        ApiProvider::where('slug', 'claude')->update(['is_enabled' => false]); // force AiProviderException

        $audit = $this->makeAudit();
        $service = app(AiAnalysisService::class);

        $result = $service->analyze($audit, AuditCategory::Seo, 'Analyze this', ['page' => 'a']);

        $this->assertNull($result);
        $this->assertSame(1, AiAnalysis::count());
        $this->assertNotNull(AiAnalysis::first()->error);
    }

    public function test_successful_call_writes_an_api_usage_log(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => '[]']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])]);

        $audit = $this->makeAudit();
        app(AiAnalysisService::class)->analyze($audit, AuditCategory::Seo, 'Analyze this', ['page' => 'a']);

        $this->assertSame(1, ApiUsageLog::count());
        $log = ApiUsageLog::first();
        $this->assertSame('claude', $log->provider);
        $this->assertSame('ai_analysis', $log->service);
        $this->assertTrue($log->success);
        $this->assertSame(10, $log->prompt_tokens);
        $this->assertSame(5, $log->completion_tokens);
        $this->assertSame(15, $log->total_tokens);
        $this->assertSame($audit->id, $log->audit_id);
    }

    public function test_failed_call_writes_a_failed_api_usage_log(): void
    {
        ApiProvider::where('slug', 'claude')->update(['is_enabled' => false]);

        $audit = $this->makeAudit();
        app(AiAnalysisService::class)->analyze($audit, AuditCategory::Seo, 'Analyze this', ['page' => 'a']);

        $this->assertSame(1, ApiUsageLog::count());
        $this->assertFalse(ApiUsageLog::first()->success);
    }

    /**
     * When a real plan exists but allows no usable AI model (e.g. every
     * provider is disabled, matching this app's actual dev-environment
     * state), the category must be recorded as visibly AI-unavailable —
     * not silently skipped with no trace, which would be indistinguishable
     * from "nothing was ever attempted" and could be mistaken for an AI
     * score having quietly been fabricated from nothing.
     */
    public function test_no_plan_allowed_model_records_an_error_row_instead_of_being_silent(): void
    {
        ApiProvider::where('slug', 'claude')->update(['is_enabled' => false]);
        $plan = Plan::create(['name' => 'Restricted', 'slug' => 'restricted', 'max_pages' => 25, 'daily_audit_limit' => 5, 'is_default' => true, 'is_active' => true]);
        // No ai_models attached to $plan at all.

        $audit = $this->makeAudit();
        $result = app(AiAnalysisService::class)->analyze($audit, AuditCategory::Seo, 'Analyze this', ['page' => 'a']);

        $this->assertNull($result);
        $this->assertSame(1, AiAnalysis::count());
        $analysis = AiAnalysis::first();
        $this->assertNotNull($analysis->error);
        $this->assertSame('unavailable', $analysis->provider);
        $this->assertSame(0, ApiUsageLog::count(), 'no provider was ever chosen, so there is nothing to attribute a usage-log row to');
    }

    public function test_analysis_is_skipped_once_the_daily_limit_is_reached(): void
    {
        ApiProvider::where('slug', 'claude')->update(['config' => ['daily_request_limit' => 1]]);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => '[]']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        $audit = $this->makeAudit();
        $service = app(AiAnalysisService::class);

        $service->analyze($audit, AuditCategory::Seo, 'First', ['page' => 'a']);
        $result = $service->analyze($audit, AuditCategory::Aio, 'Second', ['page' => 'b']);

        $this->assertNull($result);
        Http::assertSentCount(1);
        $this->assertSame(1, ApiUsageLog::where('error_category', 'limit_exceeded')->count());
    }

    public function test_caching_disabled_calls_provider_every_time(): void
    {
        config(['ai.cache_analyses' => false]);

        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => '[]']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        $audit = $this->makeAudit();
        $service = app(AiAnalysisService::class);

        $service->analyze($audit, AuditCategory::Seo, 'Analyze this', ['page' => 'a']);
        $service->analyze($audit, AuditCategory::Seo, 'Analyze this', ['page' => 'a']);

        Http::assertSentCount(2);
    }
}
