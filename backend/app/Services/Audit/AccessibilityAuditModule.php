<?php

namespace App\Services\Audit;

use App\Contracts\AuditModule;
use App\DTOs\AuditIssueData;
use App\DTOs\AuditModuleResult;
use App\Enums\AccessibilityCheck;
use App\Enums\AuditCategory;
use App\Enums\IssueSeverity;
use App\Models\Audit;
use App\Models\AuditPage;

/**
 * Automated accessibility assessment (§43).
 *
 * axe-core runs inside the crawler's own Playwright page load (not a
 * second pass). Violations are **aggregated by rule ID across all pages,
 * not per-page-per-violation** — the same site-wide contrast issue found
 * on every page becomes one `AuditIssue`, not one per page, so score
 * damage scales with the number of distinct problems rather than page
 * count. Highest impact seen for a rule across pages wins.
 *
 * **This module never claims compliance.** Every result carries
 * `config('accessibility.disclaimer')`, and a WCAG criterion is cited only
 * where axe's own tags name one — see WcagReference.
 */
class AccessibilityAuditModule implements AuditModule
{
    public function category(): AuditCategory
    {
        return AuditCategory::Accessibility;
    }

    public function run(Audit $audit): AuditModuleResult
    {
        $impactMap = config('audit_scoring.accessibility.impact_severity_map');
        $maxScored = config('audit_scoring.accessibility.max_scored_violations');

        $byRule = $this->aggregateByRule($audit);
        $coverage = $this->coverage($audit);

        $issues = [];

        foreach ($byRule as $ruleId => $data) {
            $check = AccessibilityCheck::forAxeRule($ruleId);
            $reference = WcagReference::fromAxeTags($data['tags']);
            $pageIds = array_values(array_unique($data['pageIds']));
            $pages = array_values(array_unique($data['pages']));

            $issues[] = new AuditIssueData(
                severity: IssueSeverity::from($impactMap[$data['impact']] ?? 'medium'),
                code: "axe.{$ruleId}",
                message: $data['help'] !== '' ? $data['help'] : $data['description'],
                recommendation: $this->recommendation($data),
                // A rule violated on exactly one page still links directly
                // via audit_page_id (consistent with every other module),
                // rather than only through the issue_pages pivot.
                auditPageId: count($pageIds) === 1 ? $pageIds[0] : null,
                meta: [
                    'check' => $check->value,
                    'check_label' => $check->label(),
                    'why_it_matters' => $check->whyItMatters(),
                    'description' => $data['description'],

                    // Null when axe's tags name no success criterion —
                    // a best-practice rule carries no WCAG reference, and
                    // inventing one would be a standards claim we cannot
                    // support.
                    'wcag' => $reference,
                    'wcag_label' => WcagReference::label($reference),
                    'is_best_practice' => $reference['is_best_practice'],

                    'help_url' => $data['helpUrl'],
                    'affected_pages' => array_slice($pages, 0, config('accessibility.max_pages_listed_per_issue')),
                    'affected_page_count' => count($pages),
                    'affected_element_count' => $data['nodeCount'],
                    'node_count' => $data['nodeCount'],

                    // The concrete failing elements: selector, markup, and
                    // axe's own explanation of why *that* element failed.
                    // This is what makes a finding fixable rather than a
                    // category to go hunting through.
                    'elements' => $data['elements'],
                ],
                affectedPageIds: count($pageIds) > 1 ? $pageIds : [],
            );
        }

        [$score, $breakdown] = ScoreCalculator::deductPoints($issues, $maxScored);

        $breakdown['coverage'] = $coverage;
        $breakdown['checks'] = $this->checkSummary($issues);
        // Carried on the score itself, so any consumer that reads the
        // score reads the caveat with it and cannot present one without
        // the other.
        $breakdown['disclaimer'] = config('accessibility.disclaimer');

        return new AuditModuleResult(
            category: $this->category(),
            // A site with no detected violations still scores 100 — but the
            // disclaimer above is what stops that number being read as
            // "accessible".
            score: $issues === [] ? 100 : $score,
            issues: $issues,
            breakdown: $breakdown,
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function aggregateByRule(Audit $audit): array
    {
        $maxElements = config('accessibility.max_elements_per_issue');
        $byRule = [];

        foreach ($audit->pages as $page) {
            if (! is_array($page->accessibility_violations)) {
                continue;
            }

            foreach ($page->accessibility_violations as $violation) {
                $ruleId = $violation['id'] ?? 'unknown';
                $impact = $violation['impact'] ?? 'minor';

                $byRule[$ruleId] ??= [
                    'impact' => $impact,
                    'description' => $violation['description'] ?? '',
                    'help' => $violation['help'] ?? '',
                    'helpUrl' => $violation['helpUrl'] ?? '',
                    'tags' => $violation['tags'] ?? [],
                    'pages' => [],
                    'pageIds' => [],
                    'nodeCount' => 0,
                    'elements' => [],
                ];

                if ($this->impactRank($impact) > $this->impactRank($byRule[$ruleId]['impact'])) {
                    $byRule[$ruleId]['impact'] = $impact;
                }

                $byRule[$ruleId]['pages'][] = $page->url;
                $byRule[$ruleId]['pageIds'][] = $page->id;
                $byRule[$ruleId]['nodeCount'] += $violation['nodeCount'] ?? 0;

                foreach ($this->elementsFrom($violation, $page) as $element) {
                    if (count($byRule[$ruleId]['elements']) >= $maxElements) {
                        break;
                    }
                    $byRule[$ruleId]['elements'][] = $element;
                }
            }
        }

        return $byRule;
    }

    /**
     * The failing elements axe recorded, each tied back to the page it was
     * found on — a selector with no page is not actionable.
     *
     * @param  array<string, mixed>  $violation
     * @return array<int, array<string, mixed>>
     */
    private function elementsFrom(array $violation, AuditPage $page): array
    {
        // `nodes` arrives from crawls since §43. Older audits have only
        // `targets`, so those still yield a selector and a page — just no
        // markup or per-node explanation.
        $nodes = $violation['nodes'] ?? null;

        if (! is_array($nodes)) {
            return collect($violation['targets'] ?? [])
                ->map(fn ($target) => [
                    'page_url' => $page->url,
                    'selector' => $this->selectorOf($target),
                    'html' => null,
                    'evidence' => null,
                ])
                ->all();
        }

        return collect($nodes)
            ->map(fn ($node) => [
                'page_url' => $page->url,
                'selector' => $this->selectorOf($node['target'] ?? null),
                'html' => $node['html'] ?? null,
                'evidence' => $node['failureSummary'] ?? null,
            ])
            ->filter(fn ($element) => $element['selector'] !== null)
            ->values()
            ->all();
    }

    /**
     * axe returns a target as an array of selectors (one per frame level,
     * outermost first). Joined so a reader gets one string they can paste
     * into devtools.
     */
    private function selectorOf(mixed $target): ?string
    {
        if (is_string($target)) {
            return $target;
        }

        if (! is_array($target) || $target === []) {
            return null;
        }

        return implode(' ', array_map(
            fn ($part) => is_array($part) ? implode(' ', $part) : (string) $part,
            $target,
        ));
    }

    /**
     * What the scan actually managed to look at.
     *
     * `pages_scanned` vs `pages_crawled` matters: a page whose axe run
     * failed contributes no violations, and without this the report would
     * present a partial scan as a complete one.
     *
     * @return array<string, mixed>
     */
    private function coverage(Audit $audit): array
    {
        $pages = $audit->pages;
        $scanned = $pages->filter(fn (AuditPage $p) => is_array($p->accessibility_violations));

        $passedRules = $scanned
            ->flatMap(fn (AuditPage $p) => is_array($p->accessibility_passes) ? $p->accessibility_passes : [])
            ->unique()
            ->values();

        return [
            'pages_crawled' => $pages->count(),
            'pages_scanned' => $scanned->count(),
            // Rules axe ran and that passed everywhere they applied.
            'passed_checks' => $passedRules->count(),
            'passed_rules' => $passedRules->take(50)->all(),
            // Rules axe could not decide — reported separately rather than
            // counted as passes, because "needs review" is not "fine".
            'needs_review' => $scanned
                ->flatMap(fn (AuditPage $p) => is_array($p->accessibility_incomplete) ? $p->accessibility_incomplete : [])
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * Per-check-area rollup, including areas with nothing found — which is
     * the only way a report can say a check area passed.
     *
     * @param  AuditIssueData[]  $issues
     * @return array<int, array<string, mixed>>
     */
    private function checkSummary(array $issues): array
    {
        $byCheck = collect($issues)->groupBy(fn (AuditIssueData $i) => $i->meta['check']);

        return collect(AccessibilityCheck::reportable())
            ->map(function (AccessibilityCheck $check) use ($byCheck) {
                $found = $byCheck->get($check->value, collect());

                return [
                    'check' => $check->value,
                    'label' => $check->label(),
                    'why_it_matters' => $check->whyItMatters(),
                    'issue_count' => $found->count(),
                    'element_count' => $found->sum(fn (AuditIssueData $i) => $i->meta['affected_element_count'] ?? 0),
                    'severities' => collect(IssueSeverity::cases())
                        ->mapWithKeys(fn (IssueSeverity $s) => [
                            $s->value => $found->filter(fn (AuditIssueData $i) => $i->severity === $s)->count(),
                        ])
                        ->filter()
                        ->all(),
                    // "No automated check in this area found a problem" —
                    // deliberately not "this area is accessible".
                    'passed' => $found->isEmpty(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * axe's `help` states the problem; `helpUrl` is where the fix is
     * documented. The old module put the bare URL in `recommendation`,
     * which read as an instruction to visit a link rather than as advice.
     *
     * @param  array<string, mixed>  $data
     */
    private function recommendation(array $data): string
    {
        $help = $data['help'] !== '' ? rtrim($data['help'], '.') : 'Fix this accessibility issue';
        $url = $data['helpUrl'];

        return $url !== ''
            ? "{$help}. See {$url} for the specific technique and examples."
            : "{$help}.";
    }

    private function impactRank(string $impact): int
    {
        return match ($impact) {
            'critical' => 4,
            'serious' => 3,
            'moderate' => 2,
            'minor' => 1,
            default => 0,
        };
    }
}
