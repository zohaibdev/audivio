<?php

namespace Tests\Unit\Services\AI;

use App\Enums\AuditCategory;
use App\Models\AiAnalysis;
use App\Models\Audit;
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
        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com']);

        return Audit::create(['website_id' => $website->id, 'status' => 'analyzing', 'max_pages' => 5]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.providers.claude.api_key' => 'test-key']);
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
        config(['ai.providers.claude.api_key' => null]); // force AiProviderException

        $audit = $this->makeAudit();
        $service = app(AiAnalysisService::class);

        $result = $service->analyze($audit, AuditCategory::Seo, 'Analyze this', ['page' => 'a']);

        $this->assertNull($result);
        $this->assertSame(1, AiAnalysis::count());
        $this->assertNotNull(AiAnalysis::first()->error);
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
