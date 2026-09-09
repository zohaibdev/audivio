<?php

namespace Tests\Unit\Services\Audit;

use App\DTOs\AuditIssueData;
use App\Enums\AuditCategory;
use App\Enums\IssueSeverity;
use App\Models\Audit;
use App\Models\AuditScore;
use App\Models\Website;
use App\Services\Audit\ScoreCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScoreCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_deduct_points_sums_by_severity(): void
    {
        $issues = [
            new AuditIssueData(IssueSeverity::Critical, 'a', 'msg'),
            new AuditIssueData(IssueSeverity::Medium, 'b', 'msg'),
        ];

        [$score, $breakdown] = ScoreCalculator::deductPoints($issues);

        $this->assertSame(75, $score); // 100 - 20 (critical) - 5 (medium)
        $this->assertSame(['a' => 20, 'b' => 5], $breakdown);
    }

    public function test_deduct_points_floors_at_zero(): void
    {
        $issues = array_fill(0, 10, new AuditIssueData(IssueSeverity::Critical, 'x', 'msg'));

        [$score] = ScoreCalculator::deductPoints($issues);

        $this->assertSame(0, $score);
    }

    public function test_deduct_points_caps_at_max_deductions_worst_first(): void
    {
        $issues = [
            new AuditIssueData(IssueSeverity::Low, 'low', 'msg'),
            new AuditIssueData(IssueSeverity::Critical, 'critical', 'msg'),
            new AuditIssueData(IssueSeverity::Medium, 'medium', 'msg'),
        ];

        [$score, $breakdown] = ScoreCalculator::deductPoints($issues, maxDeductions: 1);

        // Only the worst (critical, 20pts) should count toward the score.
        $this->assertSame(80, $score);
        $this->assertSame(['critical' => 20], $breakdown);
    }

    public function test_overall_score_is_weighted_and_renormalized_over_present_categories(): void
    {
        $website = Website::create(['primary_url' => 'https://example.com', 'domain' => 'example.com', 'normalized_domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'status' => 'analyzing', 'max_pages' => 5]);

        // Only seo (weight 25) and accessibility (weight 20) ran — performance
        // hasn't finished yet. Overall must be the weighted average over just
        // those two, not diluted by categories that never ran.
        AuditScore::create(['audit_id' => $audit->id, 'category' => AuditCategory::Seo->value, 'score' => 80]);
        AuditScore::create(['audit_id' => $audit->id, 'category' => AuditCategory::Accessibility->value, 'score' => 100]);

        $overall = (new ScoreCalculator)->calculateOverall($audit->scores);

        // (80*25 + 100*20) / (25+20) = 4000/45 = 88.89 -> 89
        $this->assertSame(89, $overall);
    }
}
