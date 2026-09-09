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
            // The combined report shows an accessibility score alongside
            // the others, so it carries the same caveat the per-service
            // report does (§43): a number labelled "accessibility" must
            // never travel without it.
            'disclaimers' => $this->buildDisclaimers($audit),
        ];
    }

    /**
     * Caveats that apply to this audit's results, keyed by the service
     * they qualify. Present only when that service actually ran — an
     * audit with no accessibility score has nothing to disclaim.
     *
     * @return array<string, mixed>
     */
    private function buildDisclaimers(Audit $audit): array
    {
        $accessibility = $audit->scores->first(
            fn ($s) => $s->category === AuditCategory::Accessibility
        );

        if ($accessibility === null) {
            return [];
        }

        return [
            // Prefer what the module stored, so a report re-rendered years
            // later shows the wording that audit was actually issued with.
            'accessibility' => $accessibility->breakdown['disclaimer']
                ?? config('accessibility.disclaimer'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOverview(Audit $audit): array
    {
        $scores = $audit->scores->keyBy(fn ($s) => $s->category->value);

        return [
            'website_url' => $audit->website->primary_url,
            'domain' => $audit->website->domain,
            'platform' => $audit->website->platform->value,
            'overall_score' => $scores->get(AuditCategory::Overall->value)?->score,
            'category_scores' => $scores
                ->reject(fn ($s) => $s->category === AuditCategory::Overall)
                ->mapWithKeys(fn ($s) => [$s->category->value => $s->score])
                ->all(),
            'pages_crawled' => $audit->pages_crawled,
            // What kind of site this audit concluded it was looking at,
            // and why (§46). It decides which services were reported at
            // all, so it is stated up front rather than left implicit in
            // a set of missing scores.
            'site_type' => $audit->site_type?->value,
            'site_type_label' => $audit->site_type?->label(),
            'site_type_description' => $audit->site_type?->description(),
            'site_type_confidence' => $audit->site_type_confidence,
            'site_type_signals' => $audit->site_type_signals ?? [],
            // Falls back to now() because the report is built *before*
            // the audit is flipped to completed — deliberately, so that
            // `completed` means "there is a report to read" (§38). On this
            // first build the two are milliseconds apart; on a later
            // regeneration `completed_at` is set and wins.
            'audit_date' => ($audit->completed_at ?? now())->toIso8601String(),
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
