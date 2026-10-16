<?php

namespace Tests\Unit\Services\Audit;

use App\Models\Audit;
use App\Models\AuditPage;
use App\Models\Website;
use App\Services\Audit\AioAuditModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AioAuditModuleTest extends TestCase
{
    use RefreshDatabase;

    private function makeAudit(): Audit
    {
        $website = Website::create(['primary_url' => 'https://example.com', 'domain' => 'example.com', 'normalized_domain' => 'example.com']);

        return Audit::create(['website_id' => $website->id, 'status' => 'analyzing', 'max_pages' => 5]);
    }

    public function test_well_structured_page_scores_100(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'headings' => [['level' => 1, 'text' => 'Clear Topic']],
            'structured_data' => [['@type' => 'Article']],
        ]);

        $result = app(AioAuditModule::class)->run($audit->fresh());

        $this->assertSame(100, $result->score);
    }

    public function test_no_structured_data_anywhere_is_flagged(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'headings' => [['level' => 1, 'text' => 'Clear Topic']],
            'structured_data' => [],
        ]);

        $result = app(AioAuditModule::class)->run($audit->fresh());

        $this->assertTrue(collect($result->issues)->pluck('code')->contains('aio.no_structured_data'));
    }

    public function test_page_with_no_headings_is_flagged_high(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'headings' => [],
            'structured_data' => [['@type' => 'Article']],
        ]);

        $result = app(AioAuditModule::class)->run($audit->fresh());

        $issue = collect($result->issues)->firstWhere('code', 'aio.no_heading_structure');
        $this->assertNotNull($issue);
        $this->assertSame('high', $issue->severity->value);
    }
}
