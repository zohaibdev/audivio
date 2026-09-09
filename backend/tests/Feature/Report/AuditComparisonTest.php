<?php

namespace Tests\Feature\Report;

use App\Enums\AuditCategory;
use App\Enums\IssueSeverity;
use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\AuditPage;
use App\Models\AuditScore;
use App\Models\User;
use App\Models\Website;
use App\Services\Report\AuditComparison;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Comparing two audits of the same site (§52).
 *
 * The useful output is one sentence — "Performance improved from 48 to
 * 72" — and nearly every test here is about refusing to say that when it
 * would not be true.
 */
class AuditComparisonTest extends TestCase
{
    use RefreshDatabase;

    private Website $website;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'x']);

        $this->website = Website::create([
            'user_id' => $this->owner->id, 'name' => 'example.com',
            'primary_url' => 'https://example.com/', 'domain' => 'example.com',
            'normalized_domain' => 'example.com', 'platform' => 'unknown',
        ]);
    }

    /**
     * @param  array<string, int|null>  $scores
     * @param  string[]  $urls
     */
    private function makeAudit(array $scores = [], array $urls = ['https://example.com/'], int $pagesCrawled = 1): Audit
    {
        $audit = Audit::create([
            'website_id' => $this->website->id, 'user_id' => $this->owner->id,
            'status' => 'completed', 'max_pages' => 25,
            'pages_crawled' => $pagesCrawled, 'completed_at' => now(),
        ]);

        foreach ($scores as $category => $score) {
            AuditScore::create([
                'audit_id' => $audit->id, 'category' => $category,
                'score' => $score, 'is_applicable' => $score !== null,
            ]);
        }

        foreach ($urls as $url) {
            AuditPage::create(['audit_id' => $audit->id, 'url' => $url, 'http_status' => 200]);
        }

        return $audit->fresh();
    }

    private function addIssue(Audit $audit, string $code, IssueSeverity $severity, ?string $url = null): AuditIssue
    {
        $page = $url === null ? null : AuditPage::where('audit_id', $audit->id)->where('url', $url)->first();

        return AuditIssue::create([
            'audit_id' => $audit->id,
            'audit_page_id' => $page?->id,
            'category' => AuditCategory::Seo,
            'severity' => $severity,
            'code' => $code,
            'message' => "Finding {$code}",
            'meta' => $url === null ? [] : ['url' => $url],
        ]);
    }

    private function compare(Audit $previous, Audit $current): array
    {
        return app(AuditComparison::class)->compare($previous->fresh(), $current->fresh());
    }

    // --- Score movement ---

    public function test_a_score_movement_reads_the_way_the_spec_writes_it(): void
    {
        $before = $this->makeAudit(['performance' => 48]);
        $after = $this->makeAudit(['performance' => 72]);

        $insight = collect($this->compare($before, $after)['insights'])
            ->firstWhere('category', 'performance');

        $this->assertSame(
            'Page Speed & Performance improved from 48 to 72 (+24 points).',
            $insight['text'],
        );
        $this->assertSame('improved', $insight['type']);
    }

    public function test_a_decline_is_reported_as_plainly_as_an_improvement(): void
    {
        $insight = collect($this->compare(
            $this->makeAudit(['seo' => 80]),
            $this->makeAudit(['seo' => 61]),
        )['insights'])->firstWhere('category', 'seo');

        $this->assertSame('declined', $insight['type']);
        $this->assertStringContainsString('(-19 points)', $insight['text']);
    }

    public function test_the_biggest_movement_leads(): void
    {
        $insights = $this->compare(
            $this->makeAudit(['seo' => 70, 'performance' => 40]),
            $this->makeAudit(['seo' => 73, 'performance' => 75]),
        )['insights'];

        // The one worth acting on, not the one that sorts first
        // alphabetically.
        $this->assertSame('performance', $insights[0]['category']);
    }

    public function test_an_unchanged_score_produces_no_insight(): void
    {
        $insights = $this->compare(
            $this->makeAudit(['seo' => 70]),
            $this->makeAudit(['seo' => 70]),
        )['insights'];

        $this->assertSame([], $insights);
    }

    // --- Not Applicable is not a score ---

    public function test_a_service_that_stopped_applying_is_not_reported_as_a_collapse(): void
    {
        $before = $this->makeAudit(['ecommerce' => 64]);

        $after = $this->makeAudit(['ecommerce' => null]);
        AuditScore::where('audit_id', $after->id)->where('category', 'ecommerce')->update([
            'not_applicable_reason' => 'This looks like a service business rather than a shop.',
        ]);

        $row = collect($this->compare($before, $after)['scores'])->firstWhere('category', 'ecommerce');

        // 64 to "N/A" is not a 64-point drop, and a reader shown -64
        // would act on a decline that never happened.
        $this->assertFalse($row['is_comparable']);
        $this->assertNull($row['delta']);
        $this->assertStringContainsString('service business', $row['reason']);
    }

    public function test_a_service_that_started_applying_has_nothing_to_compare_against(): void
    {
        $row = collect($this->compare(
            $this->makeAudit(['ecommerce' => null]),
            $this->makeAudit(['ecommerce' => 55]),
        )['scores'])->firstWhere('category', 'ecommerce');

        $this->assertFalse($row['is_comparable']);
        $this->assertStringContainsString('did not apply to the site in the earlier audit', $row['reason']);
    }

    public function test_a_service_that_never_ran_is_not_described_as_inapplicable(): void
    {
        // No ecommerce score row at all on the later run — an older audit,
        // or a plan that did not include the service.
        $row = collect($this->compare(
            $this->makeAudit(['ecommerce' => 55]),
            $this->makeAudit(['seo' => 70]),
        )['scores'])->firstWhere('category', 'ecommerce');

        // "Did not apply to the site" is a finding about the site. Nobody
        // made that finding here — the service simply never ran.
        $this->assertStringContainsString('did not run in the later audit', $row['reason']);
        $this->assertStringNotContainsString('did not apply', $row['reason']);
    }

    public function test_a_service_that_could_not_be_assessed_says_so(): void
    {
        $before = $this->makeAudit(['aio' => 80]);
        $after = $this->makeAudit(['aio' => null]);

        AuditScore::where('audit_id', $after->id)->where('category', 'aio')->update([
            'breakdown' => json_encode(['error' => 'The AI provider timed out.']),
        ]);

        $row = collect($this->compare($before, $after)['scores'])->firstWhere('category', 'aio');

        // A failed measurement (§35), not a property of the site.
        $this->assertStringContainsString('could not be assessed', $row['reason']);
    }

    public function test_the_stored_not_applicable_reason_survives_when_neither_run_scored(): void
    {
        $before = $this->makeAudit(['ecommerce' => null]);
        $after = $this->makeAudit(['ecommerce' => null]);

        AuditScore::where('audit_id', $after->id)->where('category', 'ecommerce')->update([
            'not_applicable_reason' => 'There is no store to assess.',
        ]);

        $row = collect($this->compare($before, $after)['scores'])->firstWhere('category', 'ecommerce');

        // The auditor's own words beat a generic "neither audit produced a
        // score", which tells the reader nothing they can act on.
        $this->assertSame('There is no store to assess.', $row['reason']);
    }

    public function test_every_scored_service_appears_in_the_comparison(): void
    {
        $categories = collect($this->compare(
            $this->makeAudit(['landing_page' => 90, 'product_auditor' => 70]),
            $this->makeAudit(['landing_page' => 95, 'product_auditor' => 75]),
        )['scores'])->pluck('category');

        // Both were missing from the category list, so two services that
        // genuinely scored never appeared in the table at all.
        $this->assertContains('landing_page', $categories);
        $this->assertContains('product_auditor', $categories);
    }

    public function test_the_overall_score_is_named_like_a_service_not_an_enum(): void
    {
        $insight = collect($this->compare(
            $this->makeAudit(['overall' => 64]),
            $this->makeAudit(['overall' => 53]),
        )['insights'])->firstWhere('category', 'overall');

        // It read "overall declined from 64 to 53" — lowercase, in a
        // sentence written to be shown to a customer.
        $this->assertSame('Overall score declined from 64 to 53 (-11 points).', $insight['text']);
    }

    public function test_landing_page_is_not_labelled_the_same_as_cro(): void
    {
        $scores = collect($this->compare(
            $this->makeAudit(['conversion' => 80, 'landing_page' => 90]),
            $this->makeAudit(['conversion' => 85, 'landing_page' => 95]),
        )['scores']);

        // Both map to the CRO service, so both rows came out with the same
        // label and the table read as a duplicate.
        $this->assertSame('Landing Page', $scores->firstWhere('category', 'landing_page')['label']);
        $this->assertSame(
            'Conversion Rate Optimization',
            $scores->firstWhere('category', 'conversion')['label'],
        );
    }

    public function test_an_incomparable_service_never_becomes_an_insight(): void
    {
        $insights = $this->compare(
            $this->makeAudit(['ecommerce' => 64, 'seo' => 70]),
            $this->makeAudit(['ecommerce' => null, 'seo' => 75]),
        )['insights'];

        $this->assertSame(['seo'], collect($insights)->pluck('category')->filter()->all());
    }

    public function test_a_service_neither_audit_ran_is_left_out_entirely(): void
    {
        $categories = collect($this->compare(
            $this->makeAudit(['seo' => 70]),
            $this->makeAudit(['seo' => 75]),
        )['scores'])->pluck('category');

        // It is not part of this site's story, so it is not a row in the
        // table with two dashes in it.
        $this->assertNotContains('aio', $categories);
        $this->assertContains('seo', $categories);
    }

    // --- The coverage trap ---

    public function test_a_finding_on_a_page_that_was_not_recrawled_is_not_called_resolved(): void
    {
        $before = $this->makeAudit(
            ['seo' => 60],
            ['https://example.com/', 'https://example.com/about'],
            pagesCrawled: 2,
        );
        $this->addIssue($before, 'seo.missing_title', IssueSeverity::Critical, 'https://example.com/about');

        // The later run only reached the homepage.
        $after = $this->makeAudit(['seo' => 60], ['https://example.com/'], pagesCrawled: 1);

        $comparison = $this->compare($before, $after);

        // Nobody looked at /about. Reporting this as fixed is the worst
        // number this product could produce.
        $this->assertSame([], $comparison['issues']['resolved']);
        $this->assertCount(1, $comparison['issues']['not_rechecked']);
        $this->assertStringContainsString(
            'not crawled in the later audit',
            $comparison['issues']['not_rechecked'][0]['reason'],
        );
    }

    public function test_a_finding_on_a_page_that_was_recrawled_and_is_gone_is_resolved(): void
    {
        $urls = ['https://example.com/', 'https://example.com/about'];

        $before = $this->makeAudit(['seo' => 60], $urls, pagesCrawled: 2);
        $this->addIssue($before, 'seo.missing_title', IssueSeverity::Critical, 'https://example.com/about');

        $after = $this->makeAudit(['seo' => 80], $urls, pagesCrawled: 2);

        $comparison = $this->compare($before, $after);

        $this->assertCount(1, $comparison['issues']['resolved']);
        $this->assertSame('seo.missing_title', $comparison['issues']['resolved'][0]['code']);
        $this->assertSame([], $comparison['issues']['not_rechecked']);
    }

    public function test_a_site_wide_finding_is_never_excused_as_not_rechecked(): void
    {
        $before = $this->makeAudit(['seo' => 60], ['https://example.com/', 'https://example.com/about'], pagesCrawled: 2);
        // No URL: a site-wide finding, tied to no page that could go
        // missing.
        $this->addIssue($before, 'seo.missing_sitemap', IssueSeverity::Medium);

        $after = $this->makeAudit(['seo' => 70], ['https://example.com/'], pagesCrawled: 1);

        $comparison = $this->compare($before, $after);

        $this->assertSame(['seo.missing_sitemap'], collect($comparison['issues']['resolved'])->pluck('code')->all());
    }

    public function test_a_narrower_crawl_carries_a_caveat_ahead_of_every_other_insight(): void
    {
        $before = $this->makeAudit(['seo' => 60], ['https://example.com/', 'https://example.com/about'], pagesCrawled: 2);
        $after = $this->makeAudit(['seo' => 90], ['https://example.com/'], pagesCrawled: 1);

        $comparison = $this->compare($before, $after);

        $this->assertFalse($comparison['coverage']['is_like_for_like']);
        $this->assertSame(1, $comparison['coverage']['pages_not_recrawled']);
        // A caveat that changes how every other line should be read
        // belongs before them, not in a footnote.
        $this->assertSame('caveat', $comparison['insights'][0]['type']);
    }

    public function test_a_wider_crawl_says_new_findings_may_be_new_to_the_comparison(): void
    {
        $before = $this->makeAudit(['seo' => 60], ['https://example.com/'], pagesCrawled: 1);
        $after = $this->makeAudit(['seo' => 60], ['https://example.com/', 'https://example.com/new'], pagesCrawled: 2);

        $note = $this->compare($before, $after)['coverage']['note'];

        $this->assertStringContainsString('new to the comparison rather than new to the site', $note);
    }

    public function test_identical_coverage_is_reported_as_like_for_like(): void
    {
        $urls = ['https://example.com/', 'https://example.com/about'];

        $comparison = $this->compare(
            $this->makeAudit(['seo' => 60], $urls, pagesCrawled: 2),
            $this->makeAudit(['seo' => 70], $urls, pagesCrawled: 2),
        );

        $this->assertTrue($comparison['coverage']['is_like_for_like']);
        $this->assertNull($comparison['coverage']['note']);
        $this->assertNotSame('caveat', $comparison['insights'][0]['type']);
    }

    public function test_a_trailing_slash_does_not_make_a_page_look_uncrawled(): void
    {
        $before = $this->makeAudit(['seo' => 60], ['https://example.com/about'], pagesCrawled: 1);
        $after = $this->makeAudit(['seo' => 60], ['https://example.com/about/'], pagesCrawled: 1);

        // Same page. Treating these as different would invent a dropped
        // page and a caveat on every comparison.
        $this->assertTrue($this->compare($before, $after)['coverage']['is_like_for_like']);
    }

    // --- New and persisting ---

    public function test_a_finding_absent_before_and_present_now_is_new(): void
    {
        $urls = ['https://example.com/'];
        $before = $this->makeAudit(['seo' => 70], $urls);
        $after = $this->makeAudit(['seo' => 50], $urls);
        $this->addIssue($after, 'seo.missing_canonical', IssueSeverity::Medium, 'https://example.com/');

        $comparison = $this->compare($before, $after);

        $this->assertSame(['seo.missing_canonical'], collect($comparison['issues']['new'])->pluck('code')->all());
    }

    public function test_the_same_finding_on_both_runs_is_persisting_not_new_or_resolved(): void
    {
        $urls = ['https://example.com/'];
        $before = $this->makeAudit(['seo' => 70], $urls);
        $after = $this->makeAudit(['seo' => 70], $urls);

        $this->addIssue($before, 'seo.missing_title', IssueSeverity::Critical, 'https://example.com/');
        $this->addIssue($after, 'seo.missing_title', IssueSeverity::Critical, 'https://example.com/');

        $comparison = $this->compare($before, $after);

        $this->assertSame(1, $comparison['issues']['persisting']);
        $this->assertSame([], $comparison['issues']['new']);
        $this->assertSame([], $comparison['issues']['resolved']);
    }

    public function test_findings_are_listed_worst_first(): void
    {
        $urls = ['https://example.com/'];
        $before = $this->makeAudit(['seo' => 70], $urls);
        $after = $this->makeAudit(['seo' => 40], $urls);

        $this->addIssue($after, 'seo.low_thing', IssueSeverity::Low, 'https://example.com/');
        $this->addIssue($after, 'seo.critical_thing', IssueSeverity::Critical, 'https://example.com/');

        $this->assertSame(
            ['critical', 'low'],
            collect($this->compare($before, $after)['issues']['new'])->pluck('severity')->all(),
        );
    }

    public function test_severity_counts_move_with_the_findings(): void
    {
        $urls = ['https://example.com/'];
        $before = $this->makeAudit(['seo' => 70], $urls);
        $this->addIssue($before, 'seo.a', IssueSeverity::Critical, 'https://example.com/');
        $this->addIssue($before, 'seo.b', IssueSeverity::Critical, 'https://example.com/');

        $after = $this->makeAudit(['seo' => 80], $urls);
        $this->addIssue($after, 'seo.a', IssueSeverity::Critical, 'https://example.com/');

        $deltas = $this->compare($before, $after)['issues']['severity_deltas'];

        $this->assertSame(2, $deltas['critical']['previous']);
        $this->assertSame(1, $deltas['critical']['current']);
        $this->assertSame(-1, $deltas['critical']['delta']);
    }

    // --- Picking what to compare against ---

    public function test_the_default_comparison_is_the_previous_completed_run(): void
    {
        $first = $this->makeAudit(['seo' => 40]);
        $failed = Audit::create([
            'website_id' => $this->website->id, 'user_id' => $this->owner->id,
            'status' => 'failed', 'max_pages' => 25,
        ]);
        $latest = $this->makeAudit(['seo' => 80]);

        $previous = app(AuditComparison::class)->previousFor($latest);

        // A failed run has no scores, so comparing against one would
        // report every service as incomparable and every finding as new.
        $this->assertNotNull($previous);
        $this->assertSame($first->id, $previous->id);
        $this->assertNotSame($failed->id, $previous->id);
    }

    public function test_the_first_audit_of_a_site_has_nothing_to_compare_against(): void
    {
        $this->assertNull(app(AuditComparison::class)->previousFor($this->makeAudit(['seo' => 70])));
    }

    // --- Through the API ---

    public function test_the_endpoint_compares_against_the_previous_run(): void
    {
        $this->makeAudit(['performance' => 48, 'overall' => 50]);
        $latest = $this->makeAudit(['performance' => 72, 'overall' => 70]);

        $data = $this->withHeader('Authorization', 'Bearer '.$this->owner->createToken('api')->plainTextToken)
            ->getJson("/api/audits/{$latest->id}/comparison")
            ->assertStatus(200)
            ->json('data');

        $this->assertTrue($data['is_available']);
        $this->assertStringContainsString('48 to 72', collect($data['comparison']['insights'])->pluck('text')->implode(' '));
    }

    public function test_the_first_audit_reports_that_there_is_nothing_to_compare(): void
    {
        $audit = $this->makeAudit(['seo' => 70]);

        $data = $this->withHeader('Authorization', 'Bearer '.$this->owner->createToken('api')->plainTextToken)
            ->getJson("/api/audits/{$audit->id}/comparison")
            ->assertStatus(200)
            ->json('data');

        $this->assertFalse($data['is_available']);
        $this->assertNull($data['comparison']);
        $this->assertStringContainsString('first completed audit', $data['reason']);
    }

    public function test_a_stranger_cannot_read_a_comparison(): void
    {
        $this->makeAudit(['seo' => 40]);
        $latest = $this->makeAudit(['seo' => 80]);

        $stranger = User::create(['name' => 'Nosy', 'email' => 'nosy@example.com', 'password' => 'x']);

        // A comparison names which pages were and were not crawled —
        // exactly the detail the free view withholds.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$stranger->createToken('api')->plainTextToken)
            ->getJson("/api/audits/{$latest->id}/comparison")
            ->assertStatus(403);
    }

    public function test_a_comparison_cannot_be_pointed_at_another_websites_audit(): void
    {
        $other = Website::create([
            'user_id' => $this->owner->id, 'name' => 'other.test',
            'primary_url' => 'https://other.test/', 'domain' => 'other.test',
            'normalized_domain' => 'other.test', 'platform' => 'unknown',
        ]);

        $foreign = Audit::create([
            'website_id' => $other->id, 'user_id' => $this->owner->id,
            'status' => 'completed', 'max_pages' => 25, 'completed_at' => now(),
        ]);

        $this->makeAudit(['seo' => 40]);
        $latest = $this->makeAudit(['seo' => 80]);

        // Comparing across sites is meaningless, and quietly substituting
        // a different audit would be the same unannounced substitution
        // this endpoint exists to avoid.
        $this->withHeader('Authorization', 'Bearer '.$this->owner->createToken('api')->plainTextToken)
            ->getJson("/api/audits/{$latest->id}/comparison?previous={$foreign->id}")
            ->assertStatus(404);
    }

    public function test_a_failed_run_cannot_be_named_as_the_comparison_point(): void
    {
        $this->makeAudit(['seo' => 40]);
        $latest = $this->makeAudit(['seo' => 80]);

        $failed = Audit::create([
            'website_id' => $this->website->id, 'user_id' => $this->owner->id,
            'status' => 'failed', 'max_pages' => 25,
        ]);

        // It has no scores, so comparing against it would report every
        // service as incomparable and every finding as brand new.
        $this->withHeader('Authorization', 'Bearer '.$this->owner->createToken('api')->plainTextToken)
            ->getJson("/api/audits/{$latest->id}/comparison?previous={$failed->id}")
            ->assertStatus(404);
    }
}
