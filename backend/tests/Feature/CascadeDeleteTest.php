<?php

namespace Tests\Feature;

use App\Enums\ScheduleFrequency;
use App\Models\AiAnalysis;
use App\Models\AiModel;
use App\Models\ApiProvider;
use App\Models\ApiUsageLog;
use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\AuditPage;
use App\Models\AuditSchedule;
use App\Models\AuditScore;
use App\Models\CmsPage;
use App\Models\CmsSection;
use App\Models\CrawlJob;
use App\Models\CrawlUrl;
use App\Models\Feature;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Plan;
use App\Models\Report;
use App\Models\Screenshot;
use App\Models\Subscription;
use App\Models\UsageLog;
use App\Models\User;
use App\Models\Website;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $report = Report::create(['audit_id' => $audit->id, 'content' => [], 'generated_at' => now()]);

        $audit->delete();

        $this->assertNull(CrawlJob::find($crawlJob->id));
        $this->assertNull(AuditPage::find($page->id));
        $this->assertNull(AuditIssue::find($issue->id));
        $this->assertNull(AuditScore::find($score->id));
        $this->assertNull(AiAnalysis::find($analysis->id));
        $this->assertNull(Report::find($report->id));
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

    private function makePlan(): Plan
    {
        return Plan::create(['name' => 'Free', 'slug' => 'free', 'max_pages' => 25, 'daily_audit_limit' => 1, 'is_default' => true]);
    }

    public function test_deleting_a_user_cascades_to_their_subscription(): void
    {
        $plan = $this->makePlan();
        $user = User::create(['name' => 'Test', 'email' => 'test@example.com', 'password' => 'x']);
        $subscription = Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'started_at' => now()]);

        $user->delete();

        $this->assertNull(Subscription::find($subscription->id));
    }

    public function test_deleting_a_plan_with_an_active_subscription_is_prevented(): void
    {
        $plan = $this->makePlan();
        $user = User::create(['name' => 'Test', 'email' => 'test@example.com', 'password' => 'x']);
        Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'started_at' => now()]);

        $this->expectException(QueryException::class);

        $plan->delete();
    }

    public function test_deleting_a_user_cascades_to_their_usage_logs(): void
    {
        $user = User::create(['name' => 'Test', 'email' => 'test@example.com', 'password' => 'x']);
        $log = UsageLog::create(['user_id' => $user->id, 'action' => 'audit_created']);

        $user->delete();

        $this->assertNull(UsageLog::find($log->id));
    }

    public function test_deleting_a_website_or_audit_nulls_the_usage_logs_references_instead_of_deleting_the_log(): void
    {
        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'status' => 'completed', 'max_pages' => 5]);
        $log = UsageLog::create(['ip_address' => '203.0.113.1', 'action' => 'audit_created', 'website_id' => $website->id, 'audit_id' => $audit->id]);

        $audit->delete();
        $website->delete();

        $this->assertNotNull($log->fresh());
        $this->assertNull($log->fresh()->website_id);
        $this->assertNull($log->fresh()->audit_id);
    }

    public function test_deleting_a_lead_cascades_to_its_activities(): void
    {
        $lead = Lead::create(['name' => 'A', 'email' => 'a@example.com', 'source' => 'talk_to_consultant']);
        $activity = LeadActivity::create(['lead_id' => $lead->id, 'note' => 'x']);

        $lead->delete();

        $this->assertNull(LeadActivity::find($activity->id));
    }

    public function test_deleting_an_audit_nulls_the_leads_audit_id_instead_of_deleting_the_lead(): void
    {
        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'status' => 'completed', 'max_pages' => 5]);
        $lead = Lead::create(['audit_id' => $audit->id, 'name' => 'A', 'email' => 'a@example.com', 'source' => 'request_audit_review']);

        $audit->delete();

        $this->assertNotNull($lead->fresh());
        $this->assertNull($lead->fresh()->audit_id);
    }

    public function test_deleting_a_user_nulls_the_lead_activities_user_id_instead_of_deleting_the_activity(): void
    {
        $user = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x']);
        $lead = Lead::create(['name' => 'A', 'email' => 'a@example.com', 'source' => 'talk_to_consultant']);
        $activity = LeadActivity::create(['lead_id' => $lead->id, 'user_id' => $user->id, 'note' => 'x']);

        $user->delete();

        $this->assertNotNull($activity->fresh());
        $this->assertNull($activity->fresh()->user_id);
    }

    public function test_deleting_a_cms_page_cascades_to_its_sections(): void
    {
        $page = CmsPage::create(['slug' => 'homepage', 'title' => 'Home']);
        $section = $page->sections()->create(['section_key' => 'hero', 'content' => ['x' => 'y']]);

        $page->delete();

        $this->assertNull(CmsSection::find($section->id));
    }

    public function test_deleting_a_user_cascades_to_their_audit_schedules(): void
    {
        $user = User::create(['name' => 'Test', 'email' => 'test@example.com', 'password' => 'x']);
        $website = Website::create(['user_id' => $user->id, 'url' => 'https://example.com', 'domain' => 'example.com']);
        $schedule = AuditSchedule::create([
            'user_id' => $user->id,
            'website_id' => $website->id,
            'frequency' => ScheduleFrequency::Weekly,
            'next_run_at' => now()->addWeek(),
        ]);

        $user->delete();

        $this->assertNull(AuditSchedule::find($schedule->id));
    }

    public function test_deleting_a_website_cascades_to_its_audit_schedules(): void
    {
        $user = User::create(['name' => 'Test', 'email' => 'test@example.com', 'password' => 'x']);
        $website = Website::create(['user_id' => $user->id, 'url' => 'https://example.com', 'domain' => 'example.com']);
        $schedule = AuditSchedule::create([
            'user_id' => $user->id,
            'website_id' => $website->id,
            'frequency' => ScheduleFrequency::Daily,
            'next_run_at' => now()->addDay(),
        ]);

        $website->delete();

        $this->assertNull(AuditSchedule::find($schedule->id));
    }

    public function test_deleting_a_user_nulls_the_api_usage_logs_user_id_instead_of_deleting_the_log(): void
    {
        $user = User::create(['name' => 'Test', 'email' => 'test@example.com', 'password' => 'x']);
        $log = ApiUsageLog::create(['provider' => 'claude', 'service' => 'ai_analysis', 'success' => true, 'user_id' => $user->id]);

        $user->delete();

        $this->assertNotNull($log->fresh());
        $this->assertNull($log->fresh()->user_id);
    }

    public function test_deleting_an_audit_nulls_the_api_usage_logs_audit_id_instead_of_deleting_the_log(): void
    {
        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'status' => 'completed', 'max_pages' => 5]);
        $log = ApiUsageLog::create(['provider' => 'claude', 'service' => 'ai_analysis', 'success' => true, 'audit_id' => $audit->id]);

        $audit->delete();

        $this->assertNotNull($log->fresh());
        $this->assertNull($log->fresh()->audit_id);
    }

    public function test_deleting_a_plan_cascades_to_its_plan_features_pivot_rows(): void
    {
        $plan = $this->makePlan();
        $feature = Feature::create(['key' => 'seo', 'name' => 'SEO']);
        $plan->auditFeatures()->attach($feature->id, ['is_enabled' => true]);

        $plan->delete();

        $this->assertSame(0, DB::table('plan_features')->where('feature_id', $feature->id)->count());
        $this->assertNotNull(Feature::find($feature->id), 'the feature registry row itself must survive');
    }

    public function test_deleting_a_feature_cascades_to_its_plan_features_pivot_rows(): void
    {
        $plan = $this->makePlan();
        $feature = Feature::create(['key' => 'seo', 'name' => 'SEO']);
        $plan->auditFeatures()->attach($feature->id, ['is_enabled' => true]);

        $feature->delete();

        $this->assertSame(0, DB::table('plan_features')->where('plan_id', $plan->id)->count());
    }

    public function test_deleting_a_plan_cascades_to_its_plan_ai_models_pivot_rows(): void
    {
        $plan = $this->makePlan();
        $provider = ApiProvider::create(['slug' => 'claude', 'name' => 'Claude', 'provider_type' => 'ai']);
        $model = AiModel::create(['api_provider_id' => $provider->id, 'model_id' => 'claude-sonnet-5', 'display_name' => 'Claude Sonnet 5']);
        $plan->allowedAiModels()->attach($model->id);

        $plan->delete();

        $this->assertSame(0, DB::table('plan_ai_models')->where('ai_model_id', $model->id)->count());
        $this->assertNotNull(AiModel::find($model->id), 'the ai model catalog row itself must survive');
    }

    public function test_deleting_an_api_provider_cascades_to_its_ai_models(): void
    {
        $provider = ApiProvider::create(['slug' => 'claude', 'name' => 'Claude', 'provider_type' => 'ai']);
        $model = AiModel::create(['api_provider_id' => $provider->id, 'model_id' => 'claude-sonnet-5', 'display_name' => 'Claude Sonnet 5']);

        $provider->delete();

        $this->assertNull(AiModel::find($model->id));
    }

    public function test_deleting_an_audit_cascades_to_its_screenshots(): void
    {
        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'status' => 'completed', 'max_pages' => 5]);
        $page = AuditPage::create(['audit_id' => $audit->id, 'url' => 'https://example.com/', 'http_status' => 200]);
        $screenshot = Screenshot::create(['audit_id' => $audit->id, 'audit_page_id' => $page->id, 'viewport' => 'desktop', 'width' => 1366, 'height' => 900, 'storage_path' => 'screenshots/1/1/desktop.png']);

        $audit->delete();

        $this->assertNull(Screenshot::find($screenshot->id));
    }

    public function test_deleting_an_audit_issue_cascades_to_its_issue_pages_pivot_rows(): void
    {
        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'status' => 'completed', 'max_pages' => 5]);
        $page = AuditPage::create(['audit_id' => $audit->id, 'url' => 'https://example.com/', 'http_status' => 200]);
        $issue = AuditIssue::create(['audit_id' => $audit->id, 'category' => 'seo', 'severity' => 'medium', 'code' => 'seo.test', 'message' => 'x']);
        $issue->pages()->attach($page->id);

        $issue->delete();

        $this->assertSame(0, DB::table('issue_pages')->where('audit_page_id', $page->id)->count());
    }

    public function test_deleting_a_page_cascades_to_its_issue_pages_pivot_rows(): void
    {
        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'status' => 'completed', 'max_pages' => 5]);
        $page = AuditPage::create(['audit_id' => $audit->id, 'url' => 'https://example.com/', 'http_status' => 200]);
        $issue = AuditIssue::create(['audit_id' => $audit->id, 'category' => 'seo', 'severity' => 'medium', 'code' => 'seo.test', 'message' => 'x']);
        $issue->pages()->attach($page->id);

        $page->delete();

        $this->assertSame(0, DB::table('issue_pages')->where('audit_issue_id', $issue->id)->count());
    }
}
