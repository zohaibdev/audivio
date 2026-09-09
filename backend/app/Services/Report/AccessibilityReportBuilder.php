<?php

namespace App\Services\Report;

use App\Enums\AccessibilityCheck;
use App\Enums\IssueSeverity;
use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\AuditScore;
use Illuminate\Support\Collection;

/**
 * The accessibility service's own detail block (§43) — the counterpart to
 * PerformanceReportBuilder, expressing what the generic issue shape can't:
 * per-check-area coverage, WCAG citations, the failing elements, and the
 * disclaimer.
 *
 * The eight report fields the spec names all live here, derived from the
 * issues and the module's stored breakdown rather than recomputed from a
 * second scan: overall score, the four severity counts, passed checks,
 * affected pages, and affected elements.
 *
 * **The disclaimer is not optional and not paid detail.** It travels with
 * every accessibility report at every visibility level, because the risk
 * it guards against — a score being quoted as proof of ADA compliance — is
 * largest exactly where the detail is thinnest.
 */
class AccessibilityReportBuilder
{
    /**
     * @param  Collection<int, AuditIssue>  $issues
     * @return array<string, mixed>
     */
    public function build(Audit $audit, AuditScore $score, Collection $issues): array
    {
        $breakdown = is_array($score->breakdown) ? $score->breakdown : [];
        $coverage = $breakdown['coverage'] ?? [];

        return [
            // §43's eight report fields, in one place so a consumer never
            // has to re-derive them from the issue list and get a
            // different answer.
            'summary' => [
                'score' => $score->is_applicable ? $score->score : null,
                'critical_issues' => $this->countSeverity($issues, IssueSeverity::Critical),
                'high_issues' => $this->countSeverity($issues, IssueSeverity::High),
                'medium_issues' => $this->countSeverity($issues, IssueSeverity::Medium),
                'low_issues' => $this->countSeverity($issues, IssueSeverity::Low),
                // Rules axe ran that passed everywhere they applied — the
                // only honest source for "passed checks". Counting check
                // *areas* with no finding would count areas that were
                // never actually tested on this site.
                'passed_checks' => $coverage['passed_checks'] ?? 0,
                'affected_pages' => $this->affectedPageCount($issues),
                'affected_elements' => $issues->sum(fn (AuditIssue $i) => $i->meta['affected_element_count'] ?? 0),
            ],

            // What the scan actually looked at. `pages_scanned` below
            // `pages_crawled` means some pages have no accessibility
            // result at all, and the report must say so rather than let a
            // partial scan read as a complete one.
            'coverage' => [
                'pages_crawled' => $coverage['pages_crawled'] ?? 0,
                'pages_scanned' => $coverage['pages_scanned'] ?? 0,
                'is_partial' => ($coverage['pages_scanned'] ?? 0) < ($coverage['pages_crawled'] ?? 0),
                'needs_review_count' => count($coverage['needs_review'] ?? []),
                'needs_review' => $coverage['needs_review'] ?? [],
            ],

            // Every check area, including the ones with nothing found —
            // labelled "no automated check found a problem", never
            // "accessible".
            'checks' => $this->checks($breakdown, $issues),

            // WCAG success criteria this audit found failures against.
            // Best-practice-only findings contribute nothing here, since
            // they map to no criterion.
            'wcag_criteria' => $this->wcagCriteria($issues),

            'disclaimer' => $breakdown['disclaimer'] ?? config('accessibility.disclaimer'),
        ];
    }

    /**
     * The per-area rollup. Preferring the module's stored breakdown keeps
     * a re-rendered old report showing what that audit actually recorded;
     * the fallback covers audits stored before §43.
     *
     * @param  array<string, mixed>  $breakdown
     * @param  Collection<int, AuditIssue>  $issues
     * @return array<int, array<string, mixed>>
     */
    private function checks(array $breakdown, Collection $issues): array
    {
        if (! empty($breakdown['checks']) && is_array($breakdown['checks'])) {
            return $breakdown['checks'];
        }

        $byCheck = $issues->groupBy(fn (AuditIssue $i) => $i->meta['check'] ?? 'other');

        return collect(AccessibilityCheck::reportable())
            ->map(function (AccessibilityCheck $check) use ($byCheck) {
                $found = $byCheck->get($check->value, collect());

                return [
                    'check' => $check->value,
                    'label' => $check->label(),
                    'why_it_matters' => $check->whyItMatters(),
                    'issue_count' => $found->count(),
                    'element_count' => $found->sum(fn (AuditIssue $i) => $i->meta['affected_element_count'] ?? 0),
                    'passed' => $found->isEmpty(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, AuditIssue>  $issues
     * @return array<int, array<string, mixed>>
     */
    private function wcagCriteria(Collection $issues): array
    {
        return $issues
            ->flatMap(function (AuditIssue $issue) {
                $reference = $issue->meta['wcag'] ?? null;

                if (! is_array($reference) || empty($reference['criteria'])) {
                    return [];
                }

                return collect($reference['criteria'])->map(fn (string $criterion) => [
                    'criterion' => $criterion,
                    'level' => $reference['level'] ?? null,
                ]);
            })
            ->unique(fn (array $c) => $c['criterion'])
            ->sortBy('criterion', SORT_NATURAL)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, AuditIssue>  $issues
     */
    private function affectedPageCount(Collection $issues): int
    {
        // Distinct pages, not the sum per issue: the same page failing
        // four rules is one affected page, and summing would report a
        // five-page site as having twenty pages with problems.
        return $issues
            ->flatMap(fn (AuditIssue $i) => $i->meta['affected_pages'] ?? [])
            ->unique()
            ->count();
    }

    /**
     * @param  Collection<int, AuditIssue>  $issues
     */
    private function countSeverity(Collection $issues, IssueSeverity $severity): int
    {
        return $issues->filter(fn (AuditIssue $i) => $i->severity === $severity)->count();
    }
}
