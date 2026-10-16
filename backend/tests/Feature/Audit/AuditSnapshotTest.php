<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditStatus;
use App\Jobs\Report\GenerateReportJob;
use App\Models\AiAnalysis;
use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\AuditPage;
use App\Models\AuditScore;
use App\Models\AuditSnapshot;
use App\Models\AuditSnapshotIsImmutable;
use App\Models\PagePerformanceMetric;
use App\Models\Screenshot;
use App\Models\Website;
use App\Services\Audit\AuditSnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The immutable audit snapshot (§38).
 *
 * The whole value of a snapshot is the guarantee that nothing rewrote it,
 * so the immutability tests here are not ceremony — they are the feature.
 */
class AuditSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function makeAudit(): Audit
    {
        $website = Website::create([
            'name' => 'Acme',
            'primary_url' => 'https://example.com/',
            'domain' => 'example.com',
            'normalized_domain' => 'example.com',
            'platform' => 'wordpress',
            'category' => 'ecommerce',
            'country' => 'GB',
        ]);

        $audit = Audit::create([
            'website_id' => $website->id,
            'status' => AuditStatus::Completed,
            'max_pages' => 25,
            'pages_crawled' => 2,
            'completed_at' => now(),
        ]);

        $page = AuditPage::create([
            'audit_id' => $audit->id,
            'url' => 'https://example.com/',
            'http_status' => 200,
            'title' => 'Home',
            'load_time_ms' => 420,
            'html_size_bytes' => 12000,
        ]);

        AuditScore::create(['audit_id' => $audit->id, 'category' => 'overall', 'score' => 78]);
        AuditScore::create(['audit_id' => $audit->id, 'category' => 'seo', 'score' => 65]);
        AuditScore::create(['audit_id' => $audit->id, 'category' => 'ecommerce', 'score' => null, 'is_applicable' => false]);

        AuditIssue::create([
            'audit_id' => $audit->id,
            'audit_page_id' => $page->id,
            'category' => 'seo',
            'severity' => 'critical',
            'code' => 'missing_title',
            'message' => 'The page has no title',
            'recommendation' => 'Add a <title> element',
        ]);

        AuditIssue::create([
            'audit_id' => $audit->id,
            'category' => 'seo',
            'severity' => 'low',
            'code' => 'no_advice',
            'message' => 'Something minor',
            'recommendation' => null,
        ]);

        Screenshot::create([
            'audit_id' => $audit->id,
            'audit_page_id' => $page->id,
            'viewport' => 'desktop',
            'width' => 1366,
            'height' => 900,
            'storage_path' => 'screenshots/1/homepage/desktop.png',
        ]);

        PagePerformanceMetric::create([
            'audit_id' => $audit->id,
            'audit_page_id' => $page->id,
            'source' => 'crawler',
            'strategy' => 'desktop',
            'fcp_ms' => 900,
            'ttfb_ms' => 120,
            'total_requests' => 34,
            'total_transfer_bytes' => 850000,
        ]);

        AiAnalysis::create([
            'audit_id' => $audit->id,
            'category' => 'aio',
            'provider' => 'claude',
            'context_hash' => 'abc123',
            'context' => [],
            'prompt' => 'p',
            'response' => 'r',
        ]);

        return $audit->fresh();
    }

    // --- Immutability ---

    public function test_a_written_snapshot_cannot_be_updated(): void
    {
        $snapshot = app(AuditSnapshotBuilder::class)->build($this->makeAudit());

        $this->expectException(AuditSnapshotIsImmutable::class);

        $snapshot->update(['website_url' => 'https://attacker.example/']);
    }

    public function test_a_written_snapshot_cannot_be_changed_by_attribute_assignment(): void
    {
        $snapshot = app(AuditSnapshotBuilder::class)->build($this->makeAudit());

        $snapshot->domain = 'attacker.example';

        // The guard is on saving, not on assignment — an in-memory change
        // is harmless right up until someone tries to persist it.
        $this->expectException(AuditSnapshotIsImmutable::class);

        $snapshot->save();
    }

    public function test_the_stored_content_survives_an_attempted_rewrite(): void
    {
        $audit = $this->makeAudit();
        $snapshot = app(AuditSnapshotBuilder::class)->build($audit);

        try {
            $snapshot->update(['content' => ['scores' => ['overall' => 100]]]);
        } catch (AuditSnapshotIsImmutable) {
            // expected
        }

        $this->assertSame(78, $snapshot->fresh()->content['scores']['overall']);
    }

    public function test_a_written_snapshot_cannot_be_deleted(): void
    {
        $snapshot = app(AuditSnapshotBuilder::class)->build($this->makeAudit());

        // Deleting and re-running would be a subtler way to rewrite
        // history than editing in place.
        $this->expectException(AuditSnapshotIsImmutable::class);

        $snapshot->delete();
    }

    public function test_rebuilding_returns_the_existing_snapshot_rather_than_writing_a_new_one(): void
    {
        $audit = $this->makeAudit();
        $builder = app(AuditSnapshotBuilder::class);

        $first = $builder->build($audit);

        // The audit's data changes after the snapshot was taken...
        AuditScore::where('audit_id', $audit->id)
            ->where('category', 'overall')
            ->update(['score' => 12]);

        // ...and a retry of the report job must not pick that up.
        $second = $builder->build($audit->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(78, $second->content['scores']['overall']);
        $this->assertSame(1, AuditSnapshot::count());
    }

    public function test_a_snapshot_is_not_affected_by_a_later_change_to_its_website(): void
    {
        $audit = $this->makeAudit();
        $snapshot = app(AuditSnapshotBuilder::class)->build($audit);

        $audit->website->update(['name' => 'Renamed Later', 'primary_url' => 'https://example.com/new']);

        // Denormalized on purpose: a snapshot full of foreign keys would
        // silently start telling a different story.
        $this->assertSame('https://example.com/', $snapshot->fresh()->website_url);
        $this->assertSame('Acme', $snapshot->fresh()->content['website']['name']);
    }

    // --- What the snapshot records ---

    public function test_the_snapshot_records_every_field_the_spec_requires(): void
    {
        config([
            'audit_versions.crawler' => '2.1.0',
            'audit_versions.scoring' => '3.4.5',
            'audit_versions.prompt' => '9.9.9',
        ]);

        $snapshot = app(AuditSnapshotBuilder::class)->build($this->makeAudit());

        $this->assertSame('https://example.com/', $snapshot->website_url);
        $this->assertSame('example.com', $snapshot->domain);
        $this->assertSame('2.1.0', $snapshot->crawler_version);
        $this->assertSame('3.4.5', $snapshot->scoring_version);
        $this->assertSame('9.9.9', $snapshot->prompt_version);
        $this->assertSame('claude', $snapshot->ai_provider);
        $this->assertSame(1, $snapshot->pages_analyzed);
        $this->assertNotNull($snapshot->audited_at);

        foreach ([
            'website', 'scores', 'service_results', 'issues', 'recommendations',
            'pages', 'screenshots', 'performance', 'ai', 'versions', 'scoring_config',
        ] as $key) {
            $this->assertArrayHasKey($key, $snapshot->content, "Snapshot content is missing: {$key}");
        }
    }

    public function test_the_snapshot_stores_the_scoring_weights_not_just_their_version(): void
    {
        config(['audit_scoring.category_weights' => ['seo' => 42]]);

        $snapshot = app(AuditSnapshotBuilder::class)->build($this->makeAudit());

        // Reading a two-year-old snapshot should not require digging a
        // matching config out of git history.
        $this->assertSame(['seo' => 42], $snapshot->content['scoring_config']['category_weights']);
    }

    public function test_service_results_record_which_services_did_not_run(): void
    {
        $snapshot = app(AuditSnapshotBuilder::class)->build($this->makeAudit());

        $byCategory = collect($snapshot->content['service_results'])->keyBy('category');

        $this->assertTrue($byCategory['seo']['ran']);
        $this->assertSame(65, $byCategory['seo']['score']);
        $this->assertSame(2, $byCategory['seo']['issue_count']);

        // A snapshot that silently omitted a category that never ran would
        // read as "this site had no accessibility problems".
        $this->assertFalse($byCategory['accessibility']['ran']);
        $this->assertNull($byCategory['accessibility']['score']);

        // Not Applicable is distinguishable from "did not run".
        $this->assertTrue($byCategory['ecommerce']['ran']);
        $this->assertFalse($byCategory['ecommerce']['is_applicable']);
        $this->assertNull($byCategory['ecommerce']['score']);
    }

    public function test_recommendations_only_include_issues_that_carry_one(): void
    {
        $snapshot = app(AuditSnapshotBuilder::class)->build($this->makeAudit());

        $this->assertCount(1, $snapshot->content['recommendations']);
        $this->assertSame('Add a <title> element', $snapshot->content['recommendations'][0]['recommendation']);
        // Both issues are still recorded; only one is advice.
        $this->assertCount(2, $snapshot->content['issues']);
    }

    public function test_screenshots_and_performance_data_are_recorded(): void
    {
        $snapshot = app(AuditSnapshotBuilder::class)->build($this->makeAudit());

        $this->assertCount(1, $snapshot->content['screenshots']);
        $this->assertSame('desktop', $snapshot->content['screenshots'][0]['viewport']);
        $this->assertSame(
            'screenshots/1/homepage/desktop.png',
            $snapshot->content['screenshots'][0]['storage_path'],
        );

        $this->assertCount(1, $snapshot->content['performance']);
        $this->assertSame(900, $snapshot->content['performance'][0]['fcp_ms']);
        // Enum-cast columns are stored as their string values, so the
        // stored record cannot change shape under a later refactor.
        $this->assertSame('crawler', $snapshot->content['performance'][0]['source']);
        $this->assertSame('desktop', $snapshot->content['performance'][0]['strategy']);
    }

    public function test_ai_provenance_records_the_provider_that_actually_answered(): void
    {
        $snapshot = app(AuditSnapshotBuilder::class)->build($this->makeAudit());

        $this->assertSame('claude', $snapshot->content['ai']['provider']);
        $this->assertSame(1, $snapshot->content['ai']['calls']);
        $this->assertSame(0, $snapshot->content['ai']['failed_calls']);
    }

    public function test_a_failed_ai_call_is_not_reported_as_the_answering_provider(): void
    {
        $audit = $this->makeAudit();
        AiAnalysis::where('audit_id', $audit->id)->update(['error' => 'provider timed out']);

        $snapshot = app(AuditSnapshotBuilder::class)->build($audit->fresh());

        $this->assertNull($snapshot->content['ai']['provider']);
        $this->assertSame(1, $snapshot->content['ai']['failed_calls']);
    }

    // --- Backfilling snapshots for audits that predate the feature ---

    public function test_the_backfill_command_writes_snapshots_for_historical_audits(): void
    {
        $audit = $this->makeAudit();
        $this->assertNull($audit->snapshot);

        $this->artisan('audits:backfill-snapshots')
            ->expectsOutputToContain('Wrote 1 snapshot(s).')
            ->assertSuccessful();

        $this->assertNotNull($audit->fresh()->snapshot);
    }

    public function test_a_backfilled_snapshot_says_so(): void
    {
        $audit = $this->makeAudit();

        $this->artisan('audits:backfill-snapshots')->assertSuccessful();

        $content = $audit->fresh()->snapshot->content;

        // The findings in a backfilled snapshot are real stored data, but
        // its version stamps are inferred from current config rather than
        // recorded at the time — a reader has to be able to tell it apart
        // from a contemporaneous record.
        $this->assertTrue($content['backfilled']);
        $this->assertNotNull($content['backfilled_at']);
    }

    public function test_a_snapshot_written_by_the_pipeline_is_not_marked_backfilled(): void
    {
        $audit = $this->makeAudit();

        $snapshot = app(AuditSnapshotBuilder::class)->build($audit);

        $this->assertFalse($snapshot->content['backfilled']);
        $this->assertNull($snapshot->content['backfilled_at']);
    }

    public function test_the_backfill_is_a_no_op_once_every_audit_has_a_snapshot(): void
    {
        $audit = $this->makeAudit();
        app(AuditSnapshotBuilder::class)->build($audit);

        // Re-runnable: it must never rewrite a snapshot that already
        // exists, since snapshots are written once (§38).
        $this->artisan('audits:backfill-snapshots')
            ->expectsOutputToContain('Every completed audit already has a snapshot.')
            ->assertSuccessful();

        $this->assertSame(1, AuditSnapshot::count());
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->makeAudit();

        $this->artisan('audits:backfill-snapshots --dry-run')
            ->expectsOutputToContain('Would write 1 snapshot(s).')
            ->assertSuccessful();

        $this->assertSame(0, AuditSnapshot::count());
    }

    public function test_an_unfinished_audit_is_not_backfilled(): void
    {
        $audit = $this->makeAudit();
        $audit->update(['status' => AuditStatus::Crawling, 'completed_at' => null]);

        // A snapshot is the record of a *finished* audit; writing one for a
        // run still in progress would capture a half-built result.
        $this->artisan('audits:backfill-snapshots')
            ->expectsOutputToContain('Every completed audit already has a snapshot.')
            ->assertSuccessful();

        $this->assertSame(0, AuditSnapshot::count());
    }

    public function test_the_report_pipeline_writes_a_snapshot_when_an_audit_completes(): void
    {
        $audit = $this->makeAudit();
        // Put it back into the state CalculateScoreJob leaves behind.
        $audit->update(['status' => AuditStatus::GeneratingReport, 'completed_at' => null]);

        GenerateReportJob::dispatchSync($audit->fresh());

        $audit->refresh();
        $this->assertSame(AuditStatus::Completed, $audit->status);
        $this->assertNotNull($audit->snapshot);
        $this->assertNotNull($audit->report);
    }
}
