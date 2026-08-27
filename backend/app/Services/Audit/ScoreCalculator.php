<?php

namespace App\Services\Audit;

use App\DTOs\AuditIssueData;
use App\Models\AuditScore;
use Illuminate\Support\Collection;

class ScoreCalculator
{
    /**
     * Shared scoring rule for deterministic modules: start at 100, deduct
     * configured points per issue severity, worst issues first, capped at
     * $maxDeductions issues so a long tail of low-severity findings on a
     * large site doesn't bottom the score out at 0. Returns [score, breakdown].
     *
     * @param  AuditIssueData[]  $issues
     * @return array{0: int, 1: array<string, int>}
     */
    public static function deductPoints(array $issues, ?int $maxDeductions = null): array
    {
        $points = config('audit_scoring.severity_points');

        $sorted = collect($issues)->sortByDesc(fn (AuditIssueData $issue) => $points[$issue->severity->value] ?? 0);

        if ($maxDeductions !== null) {
            $sorted = $sorted->take($maxDeductions);
        }

        $breakdown = [];
        $total = 0;

        foreach ($sorted as $issue) {
            $deduction = $points[$issue->severity->value] ?? 0;
            $breakdown[$issue->code] = $deduction;
            $total += $deduction;
        }

        return [max(0, 100 - $total), $breakdown];
    }

    /**
     * Weighted average of category scores, renormalized over only the
     * categories that actually ran for this audit — so an audit that
     * hasn't run every module yet still gets a fair overall score.
     */
    public function calculateOverall(Collection $categoryScores): int
    {
        $weights = config('audit_scoring.category_weights');

        $totalWeight = 0;
        $weightedSum = 0;

        foreach ($categoryScores as $categoryScore) {
            /** @var AuditScore $categoryScore */
            $weight = $weights[$categoryScore->category->value] ?? 0;
            $totalWeight += $weight;
            $weightedSum += $weight * $categoryScore->score;
        }

        if ($totalWeight === 0) {
            return 0;
        }

        return (int) round($weightedSum / $totalWeight);
    }
}
