<?php

namespace App\Services\Audit;

use App\Contracts\AuditModule;
use App\DTOs\AuditIssueData;
use App\DTOs\AuditModuleResult;
use App\Enums\AuditCategory;
use App\Enums\IssueSeverity;
use App\Models\Audit;

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

        /** @var array<string, array{impact: string, description: string, help: string, helpUrl: string, pages: array, pageIds: array, nodeCount: int}> $byRule */
        $byRule = [];

        foreach ($audit->pages as $page) {
            if (! is_array($page->accessibility_violations)) {
                continue;
            }

            foreach ($page->accessibility_violations as $violation) {
                $ruleId = $violation['id'] ?? 'unknown';
                $impact = $violation['impact'] ?? 'minor';

                if (! isset($byRule[$ruleId])) {
                    $byRule[$ruleId] = [
                        'impact' => $impact,
                        'description' => $violation['description'] ?? '',
                        'help' => $violation['help'] ?? '',
                        'helpUrl' => $violation['helpUrl'] ?? '',
                        'pages' => [],
                        'pageIds' => [],
                        'nodeCount' => 0,
                    ];
                }

                if ($this->impactRank($impact) > $this->impactRank($byRule[$ruleId]['impact'])) {
                    $byRule[$ruleId]['impact'] = $impact;
                }

                $byRule[$ruleId]['pages'][] = $page->url;
                $byRule[$ruleId]['pageIds'][] = $page->id;
                $byRule[$ruleId]['nodeCount'] += $violation['nodeCount'] ?? 0;
            }
        }

        if ($byRule === []) {
            return new AuditModuleResult(category: $this->category(), score: 100, issues: [], breakdown: []);
        }

        $issues = [];
        foreach ($byRule as $ruleId => $data) {
            $severity = IssueSeverity::from($impactMap[$data['impact']] ?? 'medium');
            $pageIds = array_values(array_unique($data['pageIds']));

            $issues[] = new AuditIssueData(
                severity: $severity,
                code: "axe.{$ruleId}",
                message: $data['help'] !== '' ? $data['help'] : $data['description'],
                recommendation: $data['helpUrl'],
                // A rule violated on exactly one page still links directly
                // via audit_page_id (consistent with every other module),
                // rather than only through the issue_pages pivot.
                auditPageId: count($pageIds) === 1 ? $pageIds[0] : null,
                meta: [
                    'affected_pages' => array_slice(array_unique($data['pages']), 0, 10),
                    'affected_page_count' => count(array_unique($data['pages'])),
                    'node_count' => $data['nodeCount'],
                ],
                affectedPageIds: count($pageIds) > 1 ? $pageIds : [],
            );
        }

        [$score, $breakdown] = ScoreCalculator::deductPoints($issues, $maxScored);

        return new AuditModuleResult(category: $this->category(), score: $score, issues: $issues, breakdown: $breakdown);
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
