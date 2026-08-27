<?php

namespace Tests\Feature;

use App\Models\AiAnalysis;
use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\AuditPage;
use App\Models\AuditScore;
use App\Models\CrawlJob;
use App\Models\CrawlUrl;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the FK cascade/null-on-delete behavior declared in the
 * migrations actually happens at the database level — not just that the
 * Eloquent relationship methods exist.
 */
class CascadeDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_website_cascades_to_its_audits(): void
    {
        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'status' => 'completed', 'max_pages' => 5]);

        $website->delete();

        $this->assertNull(Audit::find($audit->id));
    }

    public function test_deleting_a_user_nulls_the_websites_user_id_instead_of_deleting_the_website(): void
    {
        $user = User::create(['name' => 'Test', 'email' => 'test@example.com', 'password' => 'x']);
        $website = Website::create(['user_id' => $user->id, 'url' => 'https://example.com', 'domain' => 'example.com']);

        $user->delete();

        $this->assertNotNull($website->fresh());
        $this->assertNull($website->fresh()->user_id);
    }

    public function test_deleting_an_audit_cascades_to_its_crawl_job_pages_issues_and_scores(): void
    {
        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'status' => 'completed', 'max_pages' => 5]);

        $crawlJob = CrawlJob::create(['audit_id' => $audit->id, 'status' => 'completed']);
        $page = AuditPage::create(['audit_id' => $audit->id, 'url' => 'https://example.com/', 'http_status' => 200]);
        $issue = AuditIssue::create(['audit_id' => $audit->id, 'audit_page_id' => $page->id, 'category' => 'seo', 'severity' => 'medium', 'code' => 'x', 'message' => 'x']);
        $score = AuditScore::create(['audit_id' => $audit->id, 'category' => 'seo', 'score' => 80]);
        $analysis = AiAnalysis::create(['audit_id' => $audit->id, 'category' => 'seo', 'provider' => 'claude', 'context_hash' => 'abc', 'prompt' => 'x']);

        $audit->delete();

        $this->assertNull(CrawlJob::find($crawlJob->id));
        $this->assertNull(AuditPage::find($page->id));
        $this->assertNull(AuditIssue::find($issue->id));
        $this->assertNull(AuditScore::find($score->id));
        $this->assertNull(AiAnalysis::find($analysis->id));
    }

    public function test_deleting_a_crawl_job_cascades_to_its_crawl_urls(): void
    {
        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'status' => 'completed', 'max_pages' => 5]);
        $crawlJob = CrawlJob::create(['audit_id' => $audit->id, 'status' => 'completed']);
        $url = CrawlUrl::create(['crawl_job_id' => $crawlJob->id, 'url' => 'https://example.com/', 'status' => 'completed']);

        $crawlJob->delete();

        $this->assertNull(CrawlUrl::find($url->id));
    }

    public function test_deleting_a_crawl_url_nulls_the_audit_pages_crawl_url_id_instead_of_deleting_the_page(): void
    {
        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'status' => 'completed', 'max_pages' => 5]);
        $crawlJob = CrawlJob::create(['audit_id' => $audit->id, 'status' => 'completed']);
        $url = CrawlUrl::create(['crawl_job_id' => $crawlJob->id, 'url' => 'https://example.com/', 'status' => 'completed']);
        $page = AuditPage::create(['audit_id' => $audit->id, 'crawl_url_id' => $url->id, 'url' => 'https://example.com/', 'http_status' => 200]);

        $url->delete();

        $this->assertNotNull($page->fresh());
        $this->assertNull($page->fresh()->crawl_url_id);
    }

    public function test_deleting_an_audit_page_cascades_to_its_issues(): void
    {
        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'status' => 'completed', 'max_pages' => 5]);
        $page = AuditPage::create(['audit_id' => $audit->id, 'url' => 'https://example.com/', 'http_status' => 200]);
        $pageIssue = AuditIssue::create(['audit_id' => $audit->id, 'audit_page_id' => $page->id, 'category' => 'seo', 'severity' => 'medium', 'code' => 'x', 'message' => 'x']);
        $siteWideIssue = AuditIssue::create(['audit_id' => $audit->id, 'audit_page_id' => null, 'category' => 'seo', 'severity' => 'medium', 'code' => 'y', 'message' => 'y']);

        $page->delete();

        $this->assertNull(AuditIssue::find($pageIssue->id));
        // A site-wide issue (no audit_page_id) must survive — it isn't tied to this page.
        $this->assertNotNull(AuditIssue::find($siteWideIssue->id));
    }

    public function test_no_orphaned_crawl_urls_remain_after_deleting_the_owning_audit(): void
    {
        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'status' => 'completed', 'max_pages' => 5]);
        $crawlJob = CrawlJob::create(['audit_id' => $audit->id, 'status' => 'completed']);
        CrawlUrl::create(['crawl_job_id' => $crawlJob->id, 'url' => 'https://example.com/', 'status' => 'completed']);
        CrawlUrl::create(['crawl_job_id' => $crawlJob->id, 'url' => 'https://example.com/about', 'status' => 'completed']);

        $audit->delete();

        $this->assertSame(0, CrawlUrl::where('crawl_job_id', $crawlJob->id)->count());
    }
}
