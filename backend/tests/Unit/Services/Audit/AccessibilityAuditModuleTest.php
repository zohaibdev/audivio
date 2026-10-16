<?php

namespace Tests\Unit\Services\Audit;

use App\Models\Audit;
use App\Models\AuditPage;
use App\Models\Website;
use App\Services\Audit\AccessibilityAuditModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessibilityAuditModuleTest extends TestCase
{
    use RefreshDatabase;

    private function makeAudit(): Audit
    {
        $website = Website::create(['primary_url' => 'https://example.com', 'domain' => 'example.com', 'normalized_domain' => 'example.com']);

        return Audit::create(['website_id' => $website->id, 'status' => 'completed', 'max_pages' => 5]);
    }

    public function test_no_violations_scores_100(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'accessibility_violations' => [],
        ]);

        $result = (new AccessibilityAuditModule)->run($audit->fresh());

        $this->assertSame(100, $result->score);
    }

    public function test_same_rule_repeated_across_pages_is_raised_once(): void
    {
        $audit = $this->makeAudit();

        foreach (['/a', '/b', '/c'] as $path) {
            AuditPage::create([
                'audit_id' => $audit->id,
                'url' => "https://example.com{$path}",
                'http_status' => 200,
                'accessibility_violations' => [
                    ['id' => 'color-contrast', 'impact' => 'serious', 'description' => 'x', 'help' => 'Fix contrast', 'helpUrl' => 'https://x', 'nodeCount' => 2],
                ],
            ]);
        }

        $result = (new AccessibilityAuditModule)->run($audit->fresh());

        // One issue for the rule across all 3 pages, not three — a
        // site-wide pattern shouldn't be penalized 3x just because the
        // site has 3 pages.
        $this->assertCount(1, $result->issues);
        $this->assertSame('axe.color-contrast', $result->issues[0]->code);
        $this->assertSame(3, $result->issues[0]->meta['affected_page_count']);
        $this->assertSame(90, $result->score); // 100 - 10 (serious -> high)
    }

    public function test_highest_impact_seen_for_a_rule_wins(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/a',
            'http_status' => 200,
            'accessibility_violations' => [
                ['id' => 'x', 'impact' => 'minor', 'description' => 'x', 'help' => 'x', 'helpUrl' => 'x', 'nodeCount' => 1],
            ],
        ]);

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/b',
            'http_status' => 200,
            'accessibility_violations' => [
                ['id' => 'x', 'impact' => 'critical', 'description' => 'x', 'help' => 'x', 'helpUrl' => 'x', 'nodeCount' => 1],
            ],
        ]);

        $result = (new AccessibilityAuditModule)->run($audit->fresh());

        $this->assertCount(1, $result->issues);
        $this->assertSame('critical', $result->issues[0]->severity->value);
    }

    public function test_pages_with_no_violation_scan_are_ignored_not_penalized(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'accessibility_violations' => null, // axe injection failed for this page
        ]);

        $result = (new AccessibilityAuditModule)->run($audit->fresh());

        $this->assertSame(100, $result->score);
        $this->assertCount(0, $result->issues);
    }
}
