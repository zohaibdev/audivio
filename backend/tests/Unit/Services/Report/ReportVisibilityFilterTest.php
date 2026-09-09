<?php

namespace Tests\Unit\Services\Report;

use App\Services\Plan\Entitlements;
use App\Services\Report\ReportVisibilityFilter;
use Tests\TestCase;

class ReportVisibilityFilterTest extends TestCase
{
    private function sampleContent(): array
    {
        return [
            'overview' => ['overall_score' => 75, 'category_scores' => ['seo' => 75]],
            'issues' => [
                ['category' => 'seo', 'severity' => 'critical', 'priority' => 'high', 'code' => 'a', 'message' => 'a'],
                ['category' => 'seo', 'severity' => 'high', 'priority' => 'high', 'code' => 'b', 'message' => 'b'],
                ['category' => 'seo', 'severity' => 'medium', 'priority' => 'medium', 'code' => 'c', 'message' => 'c'],
                ['category' => 'seo', 'severity' => 'low', 'priority' => 'low', 'code' => 'd', 'message' => 'd'],
            ],
            'recommendations' => [
                ['category' => 'seo', 'severity' => 'critical', 'priority' => 'high', 'message' => 'a', 'recommendation' => 'Fix a'],
                ['category' => 'seo', 'severity' => 'high', 'priority' => 'high', 'message' => 'b', 'recommendation' => 'Fix b'],
            ],
            'ai_analysis' => ['aio' => 'Some AI text'],
            'pages' => [['url' => 'https://example.com/', 'http_status' => 200, 'title' => 'Home', 'issue_count' => 4]],
        ];
    }

    /**
     * A viewer on a plan showing $percent of findings, capped at
     * $issuesPerService. Built straight from the guest-config shape so
     * these tests exercise the same construction path production uses.
     */
    private function viewer(int $percent, ?int $issuesPerService = null): Entitlements
    {
        return Entitlements::fromGuestConfig(array_merge(config('entitlements.guest'), [
            'report_visibility_percent' => $percent,
            'issues_per_service' => $issuesPerService,
        ]));
    }

    public function test_full_access_returns_everything_unmodified_plus_the_access_flag(): void
    {
        $result = (new ReportVisibilityFilter)->filter($this->sampleContent(), $this->viewer(100));

        $this->assertTrue($result['is_full_access']);
        $this->assertCount(4, $result['issues']);
        $this->assertCount(2, $result['recommendations']);
        $this->assertArrayHasKey('ai_analysis', $result);
        $this->assertArrayHasKey('pages', $result);
    }

    public function test_a_restricted_plan_never_yields_the_full_report(): void
    {
        // The filter has one input that decides how much is shown. Which
        // entitlements arrive is the caller's decision (an owner gets their
        // own, everyone else the guest tier), so a plan can never be a key
        // to an audit that is not the viewer's.
        $result = (new ReportVisibilityFilter)->filter($this->sampleContent(), $this->viewer(99));

        $this->assertFalse($result['is_full_access']);
    }

    public function test_restricted_view_only_includes_major_severity_issues(): void
    {
        $result = (new ReportVisibilityFilter)->filter(
            $this->sampleContent(),
            $this->viewer(100 - 1), // anything short of 100 restricts
        );

        $severities = array_column($result['issues'], 'severity');
        sort($severities);

        // Medium and low never appear in a restricted view, however high
        // the percentage — the split is field-based, and the percentage
        // only scales how much of the eligible part survives it.
        $this->assertSame(['critical', 'high'], $severities);
    }

    public function test_restricted_view_hides_full_only_sections(): void
    {
        $result = (new ReportVisibilityFilter)->filter(
            $this->sampleContent(),
            $this->viewer(50),
        );

        $this->assertFalse($result['is_full_access']);
        $this->assertArrayNotHasKey('ai_analysis', $result);
        $this->assertArrayNotHasKey('pages', $result);
        $this->assertArrayHasKey('overview', $result);
    }

    public function test_restricted_view_strips_priority_from_issues_and_recommendations(): void
    {
        $result = (new ReportVisibilityFilter)->filter(
            $this->sampleContent(),
            $this->viewer(100 - 1),
        );

        foreach ($result['issues'] as $issue) {
            $this->assertArrayNotHasKey('priority', $issue);
        }
        foreach ($result['recommendations'] as $recommendation) {
            $this->assertArrayNotHasKey('priority', $recommendation);
        }
    }

    public function test_the_percentage_decides_how_many_findings_survive(): void
    {
        $content = $this->sampleContent();
        // Six major-severity issues, so percentages divide cleanly.
        $content['issues'] = array_map(
            fn (int $i) => ['category' => 'seo', 'severity' => 'critical', 'priority' => 'high', 'code' => "c{$i}", 'message' => "m{$i}"],
            range(1, 6),
        );

        $filter = new ReportVisibilityFilter;

        $this->assertCount(3, $filter->filter($content, $this->viewer(50))['issues']);
        $this->assertCount(2, $filter->filter($content, $this->viewer(25))['issues']);
    }

    public function test_a_single_finding_survives_a_low_percentage(): void
    {
        $content = $this->sampleContent();
        $content['issues'] = [
            ['category' => 'seo', 'severity' => 'critical', 'priority' => 'high', 'code' => 'a', 'message' => 'a'],
        ];

        $result = (new ReportVisibilityFilter)->filter($content, $this->viewer(10));

        // Rounded up on purpose: a restricted report exists to show the
        // audit found something real, and 10% of one finding rounding to
        // zero would defeat that entirely.
        $this->assertCount(1, $result['issues']);
    }

    public function test_zero_percent_shows_no_findings_but_still_shows_the_overview(): void
    {
        $result = (new ReportVisibilityFilter)->filter($this->sampleContent(), $this->viewer(0));

        $this->assertSame([], $result['issues']);
        $this->assertSame([], $result['recommendations']);
        // The scores still prove the audit ran.
        $this->assertArrayHasKey('overview', $result);
    }

    public function test_the_per_service_cap_can_lower_what_the_percentage_allowed(): void
    {
        $content = $this->sampleContent();
        $content['issues'] = array_map(
            fn (int $i) => ['category' => 'seo', 'severity' => 'critical', 'priority' => 'high', 'code' => "c{$i}", 'message' => "m{$i}"],
            range(1, 10),
        );

        // 50% of ten is five, but the plan caps a service at two.
        $result = (new ReportVisibilityFilter)->filter($content, $this->viewer(50, issuesPerService: 2));

        $this->assertCount(2, $result['issues']);
    }

    public function test_restricted_view_reports_hidden_counts_against_the_whole_report(): void
    {
        $result = (new ReportVisibilityFilter)->filter($this->sampleContent(), $this->viewer(50));

        // Four issues exist in total; the count of what is withheld is
        // measured against all of them, not only the major-severity subset
        // that was eligible — otherwise "2 more issues" would understate
        // what the reader is not seeing.
        $this->assertSame(4 - count($result['issues']), $result['hidden_issue_count']);
        $this->assertSame(2 - count($result['recommendations']), $result['hidden_recommendation_count']);
    }

    public function test_restricted_view_reports_the_percentage_it_applied(): void
    {
        $result = (new ReportVisibilityFilter)->filter($this->sampleContent(), $this->viewer(40));

        $this->assertSame(40, $result['visibility_percent']);
    }

    public function test_restricted_view_always_shows_the_overview(): void
    {
        $result = (new ReportVisibilityFilter)->filter($this->sampleContent(), $this->viewer(50));

        $this->assertSame(75, $result['overview']['overall_score']);
        $this->assertSame(['seo' => 75], $result['overview']['category_scores']);
    }
}
