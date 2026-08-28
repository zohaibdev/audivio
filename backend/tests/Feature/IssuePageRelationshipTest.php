<?php

namespace Tests\Feature;

use App\Enums\AuditStatus;
use App\Jobs\Audit\RunAuditModuleJob;
use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\AuditPage;
use App\Models\Website;
use App\Services\Audit\SeoAuditModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TC-ISSUE-005 (grouping): confirms an issue spanning several pages is
 * genuinely queryable in both directions via the issue_pages pivot — not
 * just carrying a JSON list of URLs a human can read but the database
 * can't query (CONTEXT.md §34).
 */
class IssuePageRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_multi_page_issue_is_queryable_by_page_and_a_page_is_queryable_by_issue(): void
    {
        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'status' => AuditStatus::Analyzing, 'max_pages' => 5]);

        $pages = [];
        foreach (['/a', '/b', '/c'] as $path) {
            $pages[] = AuditPage::create([
                'audit_id' => $audit->id,
                'url' => "https://example.com{$path}",
                'http_status' => 200,
                'title' => 'Fine Title',
                'meta_description' => null, // triggers seo.missing_meta_description on every page
                'canonical_url' => "https://example.com{$path}",
                'headings' => [['level' => 1, 'text' => 'Heading']],
                'images' => [],
                'links' => [],
                'redirect_chain' => [],
            ]);
        }

        RunAuditModuleJob::dispatchSync($audit, SeoAuditModule::class);

        $issue = AuditIssue::where('audit_id', $audit->id)->where('code', 'seo.missing_meta_description')->first();
        $this->assertNotNull($issue);
        $this->assertNull($issue->audit_page_id, 'a 3-page issue must not point at a single arbitrary page');

        // Issue -> pages
        $this->assertCount(3, $issue->pages);
        $this->assertEqualsCanonicalizing(
            collect($pages)->pluck('id')->all(),
            $issue->pages->pluck('id')->all(),
        );

        // Page -> issues (the reverse direction)
        $pageB = $pages[1]->fresh();
        $this->assertTrue($pageB->multiPageIssues->contains('id', $issue->id));
        $this->assertTrue($pageB->allIssues()->contains('id', $issue->id));
    }
}
