<?php

namespace Tests\Feature\Audit;

use App\Enums\MetricSource;
use App\Enums\MetricStrategy;
use App\Models\Audit;
use App\Models\AuditPage;
use App\Models\PagePerformanceMetric;
use App\Models\Website;
use App\Services\Audit\PerformanceAuditModule;
use App\Services\Plan\Entitlements;
use App\Services\Report\BrandingResolver;
use App\Services\Report\PerformanceReportBuilder;
use App\Services\Report\ServiceReportVisibilityFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The professional performance audit (§45).
 *
 * The rules this file exists to hold: never fabricate a score, use only
 * measured data, mobile leads, and mobile and desktop data never mix.
 */
class ProfessionalPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private function makeAudit(): Audit
    {
        $website = Website::create([
            'name' => 'example.com', 'primary_url' => 'https://example.com/',
            'domain' => 'example.com', 'normalized_domain' => 'example.com',
        ]);

        return Audit::create(['website_id' => $website->id, 'status' => 'analyzing', 'max_pages' => 5]);
    }

    private function addPage(Audit $audit, string $url = 'https://example.com/'): AuditPage
    {
        return AuditPage::create([
            'audit_id' => $audit->id, 'url' => $url, 'http_status' => 200,
            'title' => 'Home', 'load_time_ms' => 900,
        ]);
    }

    private function addMetric(AuditPage $page, MetricSource $source, MetricStrategy $strategy, array $attributes = []): PagePerformanceMetric
    {
        return PagePerformanceMetric::create(array_merge([
            'audit_id' => $page->audit_id,
            'audit_page_id' => $page->id,
            'source' => $source->value,
            'strategy' => $strategy->value,
        ], $attributes));
    }

    private function report(Audit $audit): array
    {
        return app(PerformanceReportBuilder::class)->build($audit->fresh());
    }

    // --- Opportunities vs diagnostics ---

    public function test_opportunities_carry_their_measured_savings_and_the_resources_they_blame(): void
    {
        $audit = $this->makeAudit();
        $page = $this->addPage($audit);

        $this->addMetric($page, MetricSource::PageSpeed, MetricStrategy::Mobile, [
            'score' => 48,
            'opportunities' => [[
                'id' => 'unused-javascript', 'title' => 'Reduce unused JavaScript',
                'category' => 'javascript', 'score' => 0.2,
                'display_value' => 'Potential savings of 320 KiB',
                'savings_ms' => 1800, 'savings_bytes' => 327680,
                'resources' => [
                    ['url' => 'https://example.com/app.js', 'total_bytes' => 500000, 'wasted_bytes' => 320000],
                ],
            ]],
        ]);

        $mobile = $this->report($audit)['mobile'];

        $this->assertSame(1800, $mobile['opportunities'][0]['savings_ms']);
        $this->assertSame('https://example.com/app.js', $mobile['opportunities'][0]['resources'][0]['url']);
        // The total is what a reader acts on first.
        $this->assertSame(1800, $mobile['potential_savings']['ms']);
        $this->assertSame(327680, $mobile['potential_savings']['bytes']);
    }

    public function test_a_finding_with_no_measured_saving_is_a_diagnostic_not_an_opportunity(): void
    {
        $audit = $this->makeAudit();
        $page = $this->addPage($audit);

        $this->addMetric($page, MetricSource::PageSpeed, MetricStrategy::Mobile, [
            'score' => 60,
            'opportunities' => [],
            'diagnostics' => [[
                'id' => 'dom-size', 'title' => 'Avoid an excessive DOM size',
                'category' => 'dom', 'score' => 0.4, 'display_value' => '2,400 elements',
                'resources' => [],
            ]],
        ]);

        $mobile = $this->report($audit)['mobile'];

        $this->assertCount(1, $mobile['diagnostics']);
        $this->assertCount(0, $mobile['opportunities']);
        // Attaching a saving Lighthouse never computed would invent the
        // one number the reader would act on.
        $this->assertArrayNotHasKey('savings_ms', $mobile['diagnostics'][0]);
    }

    public function test_no_opportunities_reports_null_savings_not_zero(): void
    {
        $audit = $this->makeAudit();
        $page = $this->addPage($audit);
        $this->addMetric($page, MetricSource::PageSpeed, MetricStrategy::Mobile, ['score' => 99]);

        // "Lighthouse found nothing to save" and "Lighthouse never ran"
        // must not render as the same figure.
        $this->assertNull($this->report($audit)['mobile']['potential_savings']['ms']);
    }

    // --- Mobile and desktop stay separate ---

    public function test_mobile_and_desktop_findings_never_mix(): void
    {
        $audit = $this->makeAudit();
        $page = $this->addPage($audit);

        $this->addMetric($page, MetricSource::PageSpeed, MetricStrategy::Mobile, [
            'score' => 40,
            'opportunities' => [['id' => 'a', 'title' => 'Mobile only', 'category' => 'images', 'savings_ms' => 900, 'resources' => []]],
        ]);
        $this->addMetric($page, MetricSource::PageSpeed, MetricStrategy::Desktop, [
            'score' => 91,
            'opportunities' => [['id' => 'b', 'title' => 'Desktop only', 'category' => 'css', 'savings_ms' => 100, 'resources' => []]],
        ]);

        $report = $this->report($audit);

        $this->assertSame('Mobile only', $report['mobile']['opportunities'][0]['title']);
        $this->assertSame('Desktop only', $report['desktop']['opportunities'][0]['title']);
        $this->assertSame(40, $report['mobile']['score']);
        $this->assertSame(91, $report['desktop']['score']);

        // Same separation in the per-page rows.
        $row = $report['pages'][0];
        $this->assertSame(900, $row['findings']['mobile']['potential_savings']['ms']);
        $this->assertSame(100, $row['findings']['desktop']['potential_savings']['ms']);
    }

    public function test_a_page_measured_only_on_desktop_reports_no_mobile_findings(): void
    {
        $audit = $this->makeAudit();
        $page = $this->addPage($audit);
        $this->addMetric($page, MetricSource::PageSpeed, MetricStrategy::Desktop, ['score' => 88]);

        $row = $this->report($audit)['pages'][0];

        // Null, not an empty finding set — borrowing the desktop result
        // would present a device profile that was never measured.
        $this->assertNull($row['findings']['mobile']);
        $this->assertNotNull($row['findings']['desktop']);
        $this->assertNull($row['mobile_score']);
    }

    public function test_the_category_rollup_uses_mobile_when_both_exist(): void
    {
        $audit = $this->makeAudit();
        $page = $this->addPage($audit);

        $this->addMetric($page, MetricSource::PageSpeed, MetricStrategy::Mobile, [
            'score' => 40,
            'opportunities' => [['id' => 'a', 'title' => 'Images', 'category' => 'images', 'savings_ms' => 900, 'savings_bytes' => 1000, 'resources' => []]],
        ]);
        $this->addMetric($page, MetricSource::PageSpeed, MetricStrategy::Desktop, [
            'score' => 95,
            'opportunities' => [['id' => 'b', 'title' => 'CSS', 'category' => 'css', 'savings_ms' => 50, 'resources' => []]],
        ]);

        $categories = collect($this->report($audit)['categories'])->keyBy('category');

        // Mobile is the priority, so mobile is what the grouping reports —
        // and it says which strategy it used rather than leaving a reader
        // to assume.
        $this->assertSame('mobile', $categories['images']['strategy']);
        $this->assertSame(1, $categories['images']['finding_count']);
        $this->assertSame(0, $categories['css']['finding_count']);
    }

    public function test_all_twelve_categories_are_reported_even_when_empty(): void
    {
        $audit = $this->makeAudit();
        $page = $this->addPage($audit);
        $this->addMetric($page, MetricSource::PageSpeed, MetricStrategy::Mobile, ['score' => 100]);

        $keys = collect($this->report($audit)['categories'])->pluck('category');

        foreach ([
            'core_web_vitals', 'server', 'images', 'javascript', 'css', 'fonts',
            'caching', 'compression', 'render_blocking', 'third_party', 'network', 'dom',
        ] as $expected) {
            $this->assertContains($expected, $keys, "category {$expected}");
        }
    }

    // --- INP and field data ---

    public function test_inp_comes_from_real_user_data_and_never_from_the_lab_run(): void
    {
        $audit = $this->makeAudit();
        $page = $this->addPage($audit);

        $this->addMetric($page, MetricSource::PageSpeed, MetricStrategy::Mobile, [
            'score' => 70, 'tbt_ms' => 400,
            'field_metrics' => [
                'source' => 'crux',
                'overall_category' => 'AVERAGE',
                'metrics' => ['inp_ms' => ['value' => 210, 'category' => 'AVERAGE']],
            ],
        ]);

        $field = $this->report($audit)['mobile']['field'];

        $this->assertTrue($field['available']);
        $this->assertSame(210, $field['metrics']['inp_ms']['value']);
        $this->assertStringContainsString('real Chrome users', $field['note']);
    }

    public function test_a_url_with_no_field_data_says_why_rather_than_omitting_inp(): void
    {
        $audit = $this->makeAudit();
        $page = $this->addPage($audit);
        $this->addMetric($page, MetricSource::PageSpeed, MetricStrategy::Mobile, ['score' => 70, 'tbt_ms' => 400]);

        $field = $this->report($audit)['mobile']['field'];

        // Silently dropping INP would read as "no INP problem here".
        $this->assertFalse($field['available']);
        $this->assertStringContainsString('INP can only be measured from real interactions', $field['reason']);
        $this->assertSame([], $field['metrics']);
    }

    // --- Caching and compression ---

    public function test_assets_without_a_cache_lifetime_are_reported_with_the_files_responsible(): void
    {
        $audit = $this->makeAudit();
        $page = $this->addPage($audit);

        $this->addMetric($page, MetricSource::Crawler, MetricStrategy::Desktop, [
            'caching' => [
                'consideredCount' => 20, 'cachedCount' => 8, 'uncachedCount' => 12,
                'uncachedBytes' => 400000,
                'uncachedSamples' => [['url' => 'https://example.com/app.js', 'bytes' => 200000, 'cacheControl' => 'no-store']],
            ],
        ]);

        $issue = collect(app(PerformanceAuditModule::class)->run($audit->fresh())->issues)
            ->firstWhere('code', 'performance.no_cache_headers');

        $this->assertNotNull($issue);
        $this->assertSame(12, $issue->meta['uncached_count']);
        $this->assertSame('https://example.com/app.js', $issue->meta['resources'][0]['url']);
        $this->assertSame('no-store', $issue->meta['resources'][0]['cacheControl']);
    }

    public function test_a_handful_of_uncached_assets_is_not_reported(): void
    {
        $audit = $this->makeAudit();
        $page = $this->addPage($audit);

        $this->addMetric($page, MetricSource::Crawler, MetricStrategy::Desktop, [
            'caching' => [
                'consideredCount' => 20, 'cachedCount' => 18, 'uncachedCount' => 2,
                'uncachedBytes' => 5000, 'uncachedSamples' => [],
            ],
        ]);

        $codes = collect(app(PerformanceAuditModule::class)->run($audit->fresh())->issues)->pluck('code');

        // A cache-busting build artefact or a tracking pixel is normal.
        $this->assertNotContains('performance.no_cache_headers', $codes);
    }

    public function test_uncompressed_text_resources_are_reported(): void
    {
        $audit = $this->makeAudit();
        $page = $this->addPage($audit);

        $this->addMetric($page, MetricSource::Crawler, MetricStrategy::Desktop, [
            'compression' => [
                'consideredCount' => 10, 'compressedCount' => 2, 'uncompressedCount' => 8,
                'uncompressedBytes' => 300000,
                'uncompressedSamples' => [['url' => 'https://example.com/style.css', 'bytes' => 120000]],
            ],
        ]);

        $issue = collect(app(PerformanceAuditModule::class)->run($audit->fresh())->issues)
            ->firstWhere('code', 'performance.no_compression');

        $this->assertNotNull($issue);
        $this->assertSame(300000, $issue->meta['uncompressed_bytes']);
        $this->assertSame('https://example.com/style.css', $issue->meta['resources'][0]['url']);
    }

    public function test_an_audit_crawled_before_headers_were_captured_reports_nothing(): void
    {
        $audit = $this->makeAudit();
        $page = $this->addPage($audit);

        // Pre-§45 crawl: no caching or compression summary at all.
        $this->addMetric($page, MetricSource::Crawler, MetricStrategy::Desktop, ['total_requests' => 30]);

        $codes = collect(app(PerformanceAuditModule::class)->run($audit->fresh())->issues)->pluck('code');

        // "Not measured" must not be reported as "measured and bad".
        $this->assertNotContains('performance.no_cache_headers', $codes);
        $this->assertNotContains('performance.no_compression', $codes);
    }

    // --- Visibility ---

    public function test_a_free_viewer_keeps_the_score_and_the_savings_total_but_not_the_list(): void
    {
        $audit = $this->makeAudit();
        $page = $this->addPage($audit);

        $this->addMetric($page, MetricSource::PageSpeed, MetricStrategy::Mobile, [
            'score' => 40,
            'opportunities' => [
                ['id' => 'a', 'title' => 'Reduce unused JavaScript', 'category' => 'javascript', 'savings_ms' => 900, 'resources' => []],
                ['id' => 'b', 'title' => 'Serve modern images', 'category' => 'images', 'savings_ms' => 400, 'resources' => []],
            ],
        ]);

        $filtered = app(ServiceReportVisibilityFilter::class)->filter(
            [
                'service' => 'performance', 'name' => 'Performance', 'score' => 40,
                'is_applicable' => true, 'not_applicable_reason' => null,
                'confidence' => 'complete', 'coverage_note' => null, 'status' => 'poor',
                'what_this_means' => null, 'severity_summary' => ['total' => 0],
                'categories' => [], 'issues' => [], 'recommendations' => [], 'pages' => [],
                'ai' => ['used' => false, 'available' => null],
                'performance' => $this->report($audit),
            ],
            Entitlements::fromGuestConfig(config('entitlements.guest')),
        );

        $mobile = $filtered['performance']['mobile'];

        $this->assertSame(40, $mobile['score'], 'the score proves something real was measured');
        // The total stays: withholding it would make the free report
        // understate the problem, which is not the same as withholding
        // paid detail.
        $this->assertSame(1300, $mobile['potential_savings']['ms']);
        $this->assertSame(2, $mobile['opportunity_count']);
        $this->assertSame([], $mobile['opportunities']);
    }

    public function test_a_free_viewer_still_sees_why_inp_is_missing(): void
    {
        $audit = $this->makeAudit();
        $page = $this->addPage($audit);
        $this->addMetric($page, MetricSource::PageSpeed, MetricStrategy::Mobile, ['score' => 70]);

        $filtered = app(ServiceReportVisibilityFilter::class)->filter(
            [
                'service' => 'performance', 'name' => 'Performance', 'score' => 70,
                'is_applicable' => true, 'not_applicable_reason' => null,
                'confidence' => 'complete', 'coverage_note' => null, 'status' => 'fair',
                'what_this_means' => null, 'severity_summary' => ['total' => 0],
                'categories' => [], 'issues' => [], 'recommendations' => [], 'pages' => [],
                'ai' => ['used' => false, 'available' => null],
                'performance' => $this->report($audit),
            ],
            Entitlements::fromGuestConfig(config('entitlements.guest')),
        );

        // A reader who cannot see the explanation would read the gap as
        // "this site has no INP problem".
        $this->assertFalse($filtered['performance']['mobile']['field']['available']);
        $this->assertNotEmpty($filtered['performance']['mobile']['field']['reason']);
    }

    public function test_the_pdf_shows_savings_for_opportunities_and_none_for_diagnostics(): void
    {
        $audit = $this->makeAudit();
        $page = $this->addPage($audit);

        $this->addMetric($page, MetricSource::PageSpeed, MetricStrategy::Mobile, [
            'score' => 45,
            'opportunities' => [[
                'id' => 'unused-javascript', 'title' => 'Reduce unused JavaScript',
                'category' => 'javascript', 'score' => 0.2, 'display_value' => null,
                'savings_ms' => 2000, 'savings_bytes' => 204800,
                'resources' => [['url' => 'https://example.com/app.js']],
            ]],
            'diagnostics' => [[
                'id' => 'dom-size', 'title' => 'Avoid an excessive DOM size',
                'category' => 'dom', 'score' => 0.4,
                'display_value' => '2,400 elements', 'resources' => [],
            ]],
        ]);

        $html = view('reports.service-pdf', [
            'audit' => $audit->fresh(),
            'report' => [
                'name' => 'Performance', 'service' => 'performance', 'score' => 45,
                'is_applicable' => true, 'not_applicable_reason' => null,
                'what_this_means' => null, 'coverage_note' => null,
                'severity_summary' => ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'total' => 0],
                'categories' => [], 'recommendations' => [], 'pages' => [],
                'ai' => ['used' => false, 'available' => null],
                'performance' => $this->report($audit),
            ],
            'branding' => app(BrandingResolver::class)->forAudit($audit->fresh()),
        ])->render();

        $this->assertStringContainsString('Opportunities', $html);
        $this->assertStringContainsString('Reduce unused JavaScript', $html);
        $this->assertStringContainsString('2s', $html);
        $this->assertStringContainsString('https://example.com/app.js', $html);

        // The diagnostics table has no savings column at all, so there is
        // nowhere for an invented figure to appear.
        $this->assertStringContainsString('Avoid an excessive DOM size', $html);
        $this->assertStringContainsString('2,400 elements', $html);
    }

    // --- Never fabricate ---

    public function test_a_page_with_no_measurement_is_excluded_rather_than_scored_zero(): void
    {
        $audit = $this->makeAudit();
        $this->addPage($audit, 'https://example.com/');
        $measured = $this->addPage($audit, 'https://example.com/measured');
        $this->addMetric($measured, MetricSource::PageSpeed, MetricStrategy::Mobile, ['score' => 55]);

        $rows = $this->report($audit)['pages'];

        $this->assertCount(1, $rows);
        $this->assertSame('https://example.com/measured', $rows[0]['url']);
    }

    public function test_a_missing_lighthouse_score_stays_null(): void
    {
        $audit = $this->makeAudit();
        $page = $this->addPage($audit);

        // Lighthouse answered but reported no category score.
        $this->addMetric($page, MetricSource::PageSpeed, MetricStrategy::Mobile, [
            'score' => null, 'lcp_ms' => 3200,
        ]);

        $mobile = $this->report($audit)['mobile'];

        $this->assertTrue($mobile['available']);
        $this->assertNull($mobile['score'], 'no reported score is not a score of zero');
    }
}
