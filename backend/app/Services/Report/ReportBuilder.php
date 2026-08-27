<?php

namespace App\Services\Report;

use App\Enums\AuditCategory;
use App\Enums\IssueSeverity;
use App\Models\AiAnalysis;
use App\Models\Audit;
use App\Models\AuditIssue;
use Illuminate\Support\Collection;

/**
 * Assembles the full report content for a completed audit. Always
 * produces the complete picture — issues, recommendations, AI analysis
 * text, per-page findings — regardless of who will eventually see it.
 * ReportVisibilityFilter decides what a given viewer is shown.
 */
class ReportBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(Audit $audit): array
    {
        $issues = $audit->issues()->orderByDesc('id')->get();

        return [
            'overview' => $this->buildOverview($audit),
            'issues' => $issues->map(fn (AuditIssue $issue) => $this->serializeIssue($issue))->values()->all(),
            'recommendations' => $this->buildRecommendations($issues),
            'ai_analysis' => $this->buildAiAnalysis($audit),
            'pages' => $this->buildPageFindings($audit),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOverview(Audit $audit): array
    {
        $scores = $audit->scores->keyBy(fn ($s) => $s->category->value);

        return [
            'website_url' => $audit->website->url,
            'domain' => $audit->website->domain,
            'platform' => $audit->website->platform->value,
            'overall_score' => $scores->get(AuditCategory::Overall->value)?->score,
            'category_scores' => $scores
                ->reject(fn ($s) => $s->category === AuditCategory::Overall)
                ->mapWithKeys(fn ($s) => [$s->category->value => $s->score])
                ->all(),
            'pages_crawled' => $audit->pages_crawled,
            'audit_date' => $audit->completed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeIssue(AuditIssue $issue): array
    {
        return [
            'category' => $issue->category->value,
            'severity' => $issue->severity->value,
            'priority' => $this->priorityForSeverity($issue->severity),
            'code' => $issue->code,
            'message' => $issue->message,
            'recommendation' => $issue->recommendation,
            'page_url' => $issue->auditPage?->url,
            'meta' => $issue->meta,
        ];
    }

    private function priorityForSeverity(IssueSeverity $severity): string
    {
        return match ($severity) {
            IssueSeverity::Critical, IssueSeverity::High => 'high',
            IssueSeverity::Medium => 'medium',
            IssueSeverity::Low, IssueSeverity::Passed => 'low',
        };
    }

    /**
     * All issues that carry a recommendation, worst severity first —
     * the free/anonymous view further truncates this list (see
     * ReportVisibilityFilter), but the stored report always has all of them.
     *
     * @param  Collection<int, AuditIssue>  $issues
     * @return array<int, array<string, mixed>>
     */
    private function buildRecommendations($issues): array
    {
        $points = config('audit_scoring.severity_points');

        return $issues
            ->filter(fn (AuditIssue $issue) => ! empty($issue->recommendation))
            ->sortByDesc(fn (AuditIssue $issue) => $points[$issue->severity->value] ?? 0)
            ->map(fn (AuditIssue $issue) => [
                'category' => $issue->category->value,
                'severity' => $issue->severity->value,
                'priority' => $this->priorityForSeverity($issue->severity),
                'message' => $issue->message,
                'recommendation' => $issue->recommendation,
            ])
            ->values()
            ->all();
    }

    /**
     * Raw AI-generated analysis text per category, for categories where
     * an AI call actually produced content (ecommerce/landing_page/
     * conversion/aio — the AI-driven modules). Full-report-only content.
     *
     * @return array<string, string>
     */
    private function buildAiAnalysis(Audit $audit): array
    {
        return AiAnalysis::where('audit_id', $audit->id)
            ->whereNull('error')
            ->whereNotNull('response')
            ->get()
            ->mapWithKeys(fn (AiAnalysis $analysis) => [$analysis->category->value => $analysis->response])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildPageFindings(Audit $audit): array
    {
        return $audit->pages->map(fn ($page) => [
            'url' => $page->url,
            'http_status' => $page->http_status,
            'title' => $page->title,
            'issue_count' => $page->issues()->count(),
        ])->all();
    }
}
