<?php

namespace Tests\Unit\Services\Audit;

use App\Models\Audit;
use App\Models\AuditPage;
use App\Models\Website;
use App\Services\Audit\SeoAuditModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoAuditModuleTest extends TestCase
{
    use RefreshDatabase;

    private function makeAudit(): Audit
    {
        $website = Website::create(['primary_url' => 'https://example.com', 'domain' => 'example.com', 'normalized_domain' => 'example.com']);

        return Audit::create(['website_id' => $website->id, 'status' => 'completed', 'max_pages' => 5]);
    }

    public function test_perfect_page_scores_100(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'title' => 'A Perfectly Fine Page Title',
            'meta_description' => str_repeat('a', 100),
            'canonical_url' => 'https://example.com/',
            'headings' => [['level' => 1, 'text' => 'Heading']],
            'images' => [['src' => 'a.png', 'alt' => 'A picture']],
            'links' => [],
            'redirect_chain' => [],
            // §44 reports a site with no Organization schema; these
            // fixtures isolate one check each, so they carry a valid one.
            'structured_data' => [['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'Example', 'url' => 'https://example.com', 'logo' => 'https://example.com/logo.png', 'sameAs' => ['https://x.com/example']]],
        ]);

        $result = app(SeoAuditModule::class)->run($audit->fresh());

        $this->assertSame(100, $result->score);
        $this->assertCount(0, $result->issues);
    }

    public function test_missing_title_is_critical(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'title' => null,
            'meta_description' => str_repeat('a', 100),
            'canonical_url' => 'https://example.com/',
            'headings' => [['level' => 1, 'text' => 'Heading']],
            'images' => [],
            'links' => [],
            'redirect_chain' => [],
            // §44 reports a site with no Organization schema; these
            // fixtures isolate one check each, so they carry a valid one.
            'structured_data' => [['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'Example', 'url' => 'https://example.com', 'logo' => 'https://example.com/logo.png', 'sameAs' => ['https://x.com/example']]],
        ]);

        $result = app(SeoAuditModule::class)->run($audit->fresh());

        $codes = collect($result->issues)->pluck('code');
        $this->assertTrue($codes->contains('seo.missing_title'));
        $this->assertSame('critical', collect($result->issues)->firstWhere('code', 'seo.missing_title')->severity->value);
        $this->assertSame(80, $result->score); // 100 - 20 (critical)
    }

    public function test_missing_meta_description_and_canonical(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'title' => 'A Perfectly Fine Page Title',
            'meta_description' => null,
            'canonical_url' => null,
            'headings' => [['level' => 1, 'text' => 'Heading']],
            'images' => [],
            'links' => [],
            'redirect_chain' => [],
            // §44 reports a site with no Organization schema; these
            // fixtures isolate one check each, so they carry a valid one.
            'structured_data' => [['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'Example', 'url' => 'https://example.com', 'logo' => 'https://example.com/logo.png', 'sameAs' => ['https://x.com/example']]],
        ]);

        $result = app(SeoAuditModule::class)->run($audit->fresh());

        $codes = collect($result->issues)->pluck('code');
        $this->assertTrue($codes->contains('seo.missing_meta_description'));
        $this->assertTrue($codes->contains('seo.missing_canonical'));
        $this->assertSame(85, $result->score); // 100 - 10 (high) - 5 (medium)
    }

    public function test_missing_and_multiple_h1(): void
    {
        $audit = $this->makeAudit();

        $page = AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/no-h1',
            'http_status' => 200,
            'title' => 'A Perfectly Fine Page Title',
            'meta_description' => str_repeat('a', 100),
            'canonical_url' => 'https://example.com/no-h1',
            'headings' => [],
            'images' => [],
            'links' => [],
            'redirect_chain' => [],
            // §44 reports a site with no Organization schema; these
            // fixtures isolate one check each, so they carry a valid one.
            'structured_data' => [['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'Example', 'url' => 'https://example.com', 'logo' => 'https://example.com/logo.png', 'sameAs' => ['https://x.com/example']]],
        ]);

        $result = app(SeoAuditModule::class)->run($audit->fresh());
        $this->assertTrue(collect($result->issues)->pluck('code')->contains('seo.missing_h1'));

        $page->update(['headings' => [
            ['level' => 1, 'text' => 'First'],
            ['level' => 1, 'text' => 'Second'],
        ]]);

        $result = app(SeoAuditModule::class)->run($audit->fresh());
        $this->assertTrue(collect($result->issues)->pluck('code')->contains('seo.multiple_h1'));
    }

    public function test_images_missing_alt_are_aggregated_per_page(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'title' => 'A Perfectly Fine Page Title',
            'meta_description' => str_repeat('a', 100),
            'canonical_url' => 'https://example.com/',
            'headings' => [['level' => 1, 'text' => 'Heading']],
            'images' => [
                ['src' => 'a.png', 'alt' => null],
                ['src' => 'b.png', 'alt' => ''],
                ['src' => 'c.png', 'alt' => 'Fine'],
            ],
            'links' => [],
            'redirect_chain' => [],
            // §44 reports a site with no Organization schema; these
            // fixtures isolate one check each, so they carry a valid one.
            'structured_data' => [['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'Example', 'url' => 'https://example.com', 'logo' => 'https://example.com/logo.png', 'sameAs' => ['https://x.com/example']]],
        ]);

        $result = app(SeoAuditModule::class)->run($audit->fresh());

        $issue = collect($result->issues)->firstWhere('code', 'seo.missing_image_alt');
        $this->assertNotNull($issue);
        $this->assertSame(2, $issue->meta['count']);
    }

    public function test_duplicate_titles_across_pages_flagged_once(): void
    {
        $audit = $this->makeAudit();

        foreach (['/a', '/b'] as $path) {
            AuditPage::create([
                'audit_id' => $audit->id,
                'url' => "https://example.com{$path}",
                'http_status' => 200,
                'title' => 'Same Title Everywhere',
                'meta_description' => str_repeat('a', 100),
                'canonical_url' => "https://example.com{$path}",
                'headings' => [['level' => 1, 'text' => 'Heading']],
                'images' => [],
                'links' => [],
                'redirect_chain' => [],
            ]);
        }

        $result = app(SeoAuditModule::class)->run($audit->fresh());

        $duplicateIssues = collect($result->issues)->where('code', 'seo.duplicate_title');
        $this->assertCount(1, $duplicateIssues);
        // `affected_pages` is the shape every module uses for a
        // multi-page finding; the old `urls` key was SEO-only.
        $this->assertSame(2, count($duplicateIssues->first()->meta['affected_pages']));
        $this->assertSame(2, $duplicateIssues->first()->meta['affected_page_count']);
    }

    public function test_the_same_issue_on_every_page_is_aggregated_into_one_and_does_not_zero_the_score(): void
    {
        $audit = $this->makeAudit();

        // 25 pages, every single one missing a meta description (High,
        // 10pts) — before aggregation this was 25 separate deductions
        // (250pts), flooring the score at 0 regardless of anything else
        // about the site. This is the bug behind a real production audit
        // scoring `seo: 0` on an otherwise-fine 25-page site.
        for ($i = 0; $i < 25; $i++) {
            AuditPage::create([
                'audit_id' => $audit->id,
                'url' => "https://example.com/page-{$i}",
                'http_status' => 200,
                'title' => "Unique Page Title {$i}",
                'meta_description' => null,
                'canonical_url' => "https://example.com/page-{$i}",
                'headings' => [['level' => 1, 'text' => 'Heading']],
                'images' => [],
                'links' => [],
                'redirect_chain' => [],
            ]);
        }

        $result = app(SeoAuditModule::class)->run($audit->fresh());

        $missingMetaIssues = collect($result->issues)->where('code', 'seo.missing_meta_description');
        $this->assertCount(1, $missingMetaIssues, 'the 25 per-page findings must collapse into one site-wide issue');
        $this->assertSame(25, $missingMetaIssues->first()->meta['affected_page_count']);
        $this->assertNull($missingMetaIssues->first()->auditPageId, 'a multi-page issue must not point at a single arbitrary page');
        // 100 - 10 for the one aggregated High, not 250 for 25 of them.
        // The other 10 is two site-wide Mediums this fixture genuinely
        // earns: none of its 25 pages links to another, so all 25 are
        // orphans, and it declares no Organization schema.
        $this->assertSame(80, $result->score);
        $this->assertNotNull(collect($result->issues)->firstWhere('code', 'seo.orphan_pages'));
    }

    public function test_broken_internal_link_detected_only_for_crawled_targets(): void
    {
        $audit = $this->makeAudit();

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/broken-target',
            'http_status' => 404,
            'title' => 'Not Found',
            'meta_description' => str_repeat('a', 100),
            'canonical_url' => 'https://example.com/broken-target',
            'headings' => [['level' => 1, 'text' => 'Heading']],
            'images' => [],
            'links' => [],
            'redirect_chain' => [],
            // §44 reports a site with no Organization schema; these
            // fixtures isolate one check each, so they carry a valid one.
            'structured_data' => [['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'Example', 'url' => 'https://example.com', 'logo' => 'https://example.com/logo.png', 'sameAs' => ['https://x.com/example']]],
        ]);

        AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'title' => 'Home',
            'meta_description' => str_repeat('a', 100),
            'canonical_url' => 'https://example.com/',
            'headings' => [['level' => 1, 'text' => 'Heading']],
            'images' => [],
            'links' => [['href' => 'https://example.com/broken-target', 'text' => 'Broken'], ['href' => 'https://external.example/x', 'text' => 'External']],
            'redirect_chain' => [],
            'structured_data' => [['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'Example', 'url' => 'https://example.com', 'logo' => 'https://example.com/logo.png', 'sameAs' => ['https://x.com/example']]],
        ]);

        $result = app(SeoAuditModule::class)->run($audit->fresh());

        $issue = collect($result->issues)->firstWhere('code', 'seo.broken_internal_links');
        $this->assertNotNull($issue);
        // Each broken link carries where it was found and what the target
        // returned — a bare URL list cannot tell an owner which page to fix.
        $this->assertSame([[
            'from' => 'https://example.com/',
            'to' => 'https://example.com/broken-target',
            'status' => 404,
            'anchor_text' => 'Broken',
        ]], $issue->meta['broken_links']);
    }
}
