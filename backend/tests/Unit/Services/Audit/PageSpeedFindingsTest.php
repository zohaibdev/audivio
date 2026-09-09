<?php

namespace Tests\Unit\Services\Audit;

use App\Models\ApiProvider;
use App\Services\Audit\PageSpeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * How a Lighthouse response becomes opportunities, diagnostics and field
 * metrics (§45).
 *
 * The shape of that response is the riskiest input in the performance
 * audit: it is deeply nested, varies by Lighthouse version, and every
 * number in it ends up in front of a site owner.
 */
class PageSpeedFindingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ApiProvider::create([
            'slug' => 'pagespeed',
            'name' => 'PageSpeed',
            'provider_type' => 'audit',
            'is_enabled' => true,
            'api_key' => 'test-key',
        ]);
    }

    private function fakeLighthouse(array $audits, ?array $loadingExperience = null): void
    {
        Http::fake([
            '*' => Http::response(array_filter([
                'lighthouseResult' => [
                    'categories' => ['performance' => ['score' => 0.55]],
                    'audits' => $audits,
                ],
                'loadingExperience' => $loadingExperience,
            ]), 200),
        ]);
    }

    public function test_an_audit_with_a_measured_saving_is_an_opportunity(): void
    {
        $this->fakeLighthouse([
            'unused-javascript' => [
                'title' => 'Reduce unused JavaScript',
                'description' => 'Remove dead code.',
                'score' => 0.2,
                'displayValue' => 'Potential savings of 320 KiB',
                'details' => [
                    'overallSavingsMs' => 1780.4,
                    'overallSavingsBytes' => 327680,
                    'items' => [
                        ['url' => 'https://example.com/app.js', 'totalBytes' => 500000, 'wastedBytes' => 327680],
                    ],
                ],
            ],
        ]);

        $result = app(PageSpeedService::class)->analyze('https://example.com/');

        $this->assertCount(1, $result['opportunities']);
        $this->assertCount(0, $result['diagnostics']);

        $opportunity = $result['opportunities'][0];
        $this->assertSame(1780, $opportunity['savings_ms']);
        $this->assertSame('javascript', $opportunity['category']);
        // Lighthouse's own wording, not one we recomputed from the bytes.
        $this->assertSame('Potential savings of 320 KiB', $opportunity['display_value']);
        $this->assertSame('https://example.com/app.js', $opportunity['resources'][0]['url']);
    }

    public function test_a_failing_audit_without_a_saving_is_a_diagnostic(): void
    {
        $this->fakeLighthouse([
            'dom-size' => [
                'title' => 'Avoid an excessive DOM size',
                'score' => 0.4,
                'displayValue' => '2,400 elements',
                'details' => ['items' => []],
            ],
        ]);

        $result = app(PageSpeedService::class)->analyze('https://example.com/');

        $this->assertCount(1, $result['diagnostics']);
        $this->assertSame('dom', $result['diagnostics'][0]['category']);
        // No savings key at all — not a zero saving.
        $this->assertArrayNotHasKey('savings_ms', $result['diagnostics'][0]);
    }

    public function test_passing_and_informative_audits_are_not_findings(): void
    {
        $this->fakeLighthouse([
            'uses-text-compression' => ['title' => 'Enable text compression', 'score' => 1, 'details' => ['overallSavingsMs' => 0]],
            'network-requests' => ['title' => 'Network requests', 'score' => null, 'details' => ['items' => []]],
            'server-response-time' => ['title' => 'Reduce server response time', 'score' => 0.3, 'details' => ['items' => []]],
        ]);

        $result = app(PageSpeedService::class)->analyze('https://example.com/');

        // Only the genuinely failing audit survives: a passing check is
        // not an opportunity, and an informative one has no verdict.
        $this->assertCount(0, $result['opportunities']);
        $this->assertCount(1, $result['diagnostics']);
        $this->assertSame('server-response-time', $result['diagnostics'][0]['id']);
    }

    public function test_opportunities_are_ordered_by_the_biggest_measured_saving(): void
    {
        $this->fakeLighthouse([
            'small' => ['title' => 'Small', 'score' => 0.5, 'details' => ['overallSavingsMs' => 100]],
            'large' => ['title' => 'Large', 'score' => 0.1, 'details' => ['overallSavingsMs' => 2500]],
            'medium' => ['title' => 'Medium', 'score' => 0.3, 'details' => ['overallSavingsMs' => 800]],
        ]);

        $titles = collect(app(PageSpeedService::class)->analyze('https://example.com/')['opportunities'])
            ->pluck('title')
            ->all();

        $this->assertSame(['Large', 'Medium', 'Small'], $titles);
    }

    public function test_an_unrecognised_audit_is_grouped_as_other_rather_than_dropped(): void
    {
        $this->fakeLighthouse([
            'some-future-lighthouse-audit' => ['title' => 'New check', 'score' => 0.2, 'details' => ['items' => []]],
        ]);

        $result = app(PageSpeedService::class)->analyze('https://example.com/');

        // A Lighthouse release adding an audit must still surface it.
        $this->assertCount(1, $result['diagnostics']);
        $this->assertSame('other', $result['diagnostics'][0]['category']);
    }

    public function test_summary_rows_without_a_url_are_not_reported_as_resources(): void
    {
        $this->fakeLighthouse([
            'third-party-summary' => [
                'title' => 'Third-party usage', 'score' => 0.4,
                'details' => [
                    'overallSavingsMs' => 300,
                    'items' => [
                        ['entity' => 'Google Analytics', 'blockingTime' => 120],
                        ['url' => 'https://cdn.example.net/tag.js', 'totalBytes' => 40000],
                    ],
                ],
            ],
        ]);

        $resources = app(PageSpeedService::class)->analyze('https://example.com/')['opportunities'][0]['resources'];

        $this->assertCount(1, $resources);
        $this->assertSame('https://cdn.example.net/tag.js', $resources[0]['url']);
    }

    // --- Field data ---

    public function test_crux_field_metrics_including_inp_are_extracted(): void
    {
        $this->fakeLighthouse([], [
            'overall_category' => 'AVERAGE',
            'metrics' => [
                'INTERACTION_TO_NEXT_PAINT' => ['percentile' => 210, 'category' => 'AVERAGE'],
                'LARGEST_CONTENTFUL_PAINT_MS' => ['percentile' => 3100, 'category' => 'AVERAGE'],
                'CUMULATIVE_LAYOUT_SHIFT_SCORE' => ['percentile' => 12, 'category' => 'AVERAGE'],
            ],
        ]);

        $field = app(PageSpeedService::class)->analyze('https://example.com/')['fieldMetrics'];

        $this->assertSame('crux', $field['source']);
        $this->assertSame(210, $field['metrics']['inp_ms']['value']);
        $this->assertSame(3100, $field['metrics']['lcp_ms']['value']);
        // CrUX reports CLS as an integer 100x the real value.
        $this->assertSame(0.12, $field['metrics']['cls']['value']);
    }

    public function test_a_url_with_no_crux_record_reports_null_field_metrics(): void
    {
        $this->fakeLighthouse([]);

        $result = app(PageSpeedService::class)->analyze('https://example.com/');

        // Null, never zeros: a site with too little traffic has no field
        // record, which says nothing about how fast it is.
        $this->assertNull($result['fieldMetrics']);
    }

    public function test_an_empty_crux_metrics_block_is_treated_as_no_data(): void
    {
        $this->fakeLighthouse([], ['overall_category' => 'FAST', 'metrics' => []]);

        $this->assertNull(app(PageSpeedService::class)->analyze('https://example.com/')['fieldMetrics']);
    }

    public function test_a_missing_category_score_stays_null(): void
    {
        Http::fake([
            '*' => Http::response([
                'lighthouseResult' => [
                    'categories' => ['performance' => ['score' => null]],
                    'audits' => [],
                ],
            ], 200),
        ]);

        $this->assertNull(app(PageSpeedService::class)->analyze('https://example.com/')['score']);
    }
}
