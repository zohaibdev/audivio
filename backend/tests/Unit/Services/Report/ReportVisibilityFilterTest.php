<?php

namespace Tests\Unit\Services\Report;

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

    public function test_full_access_returns_everything_unmodified_plus_the_access_flag(): void
    {
        $result = (new ReportVisibilityFilter)->filter($this->sampleContent(), hasFullAccess: true);

        $this->assertTrue($result['is_full_access']);
        $this->assertCount(4, $result['issues']);
        $this->assertCount(2, $result['recommendations']);
        $this->assertArrayHasKey('ai_analysis', $result);
        $this->assertArrayHasKey('pages', $result);
    }

    public function test_anonymous_view_only_includes_major_severity_issues(): void
    {
        $result = (new ReportVisibilityFilter)->filter($this->sampleContent(), hasFullAccess: false);

        $this->assertFalse($result['is_full_access']);
        $severities = collect($result['issues'])->pluck('severity');
        $this->assertTrue($severities->every(fn ($s) => in_array($s, ['critical', 'high'], true)));
        $this->assertFalse($severities->contains('medium'));
        $this->assertFalse($severities->contains('low'));
    }

    public function test_anonymous_view_hides_full_only_sections(): void
    {
        $result = (new ReportVisibilityFilter)->filter($this->sampleContent(), hasFullAccess: false);

        $this->assertArrayNotHasKey('ai_analysis', $result);
        $this->assertArrayNotHasKey('pages', $result);
    }

    public function test_anonymous_view_strips_priority_from_issues_and_recommendations(): void
    {
        $result = (new ReportVisibilityFilter)->filter($this->sampleContent(), hasFullAccess: false);

        foreach ($result['issues'] as $issue) {
            $this->assertArrayNotHasKey('priority', $issue);
        }
        foreach ($result['recommendations'] as $rec) {
            $this->assertArrayNotHasKey('priority', $rec);
        }
    }

    public function test_anonymous_view_respects_configured_limits(): void
    {
        config(['report.anonymous_issue_limit' => 1, 'report.anonymous_recommendation_limit' => 1]);

        $result = (new ReportVisibilityFilter)->filter($this->sampleContent(), hasFullAccess: false);

        $this->assertCount(1, $result['issues']);
        $this->assertCount(1, $result['recommendations']);
    }

    public function test_anonymous_view_reports_hidden_counts(): void
    {
        config(['report.anonymous_issue_limit' => 1, 'report.anonymous_recommendation_limit' => 1]);

        $result = (new ReportVisibilityFilter)->filter($this->sampleContent(), hasFullAccess: false);

        // hidden_issue_count reflects ALL hidden issues, not just excess
        // major-severity ones — the medium/low issues excluded entirely
        // are part of the "N more issues, sign up to see them" signal too.
        // 4 issues total, 1 shown -> 3 hidden.
        $this->assertSame(3, $result['hidden_issue_count']);
        // 2 recommendations exist, 1 shown -> 1 hidden.
        $this->assertSame(1, $result['hidden_recommendation_count']);
    }

    public function test_anonymous_view_always_shows_the_overview(): void
    {
        $result = (new ReportVisibilityFilter)->filter($this->sampleContent(), hasFullAccess: false);

        $this->assertSame(75, $result['overview']['overall_score']);
    }
}
