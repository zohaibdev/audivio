<?php

namespace App\Services\Report;

use App\Enums\AuditCategory;
use App\Enums\IssueSeverity;
use App\Models\AiAnalysis;
use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\AuditPage;
use App\Models\AuditScore;
use App\Models\Screenshot;
use Illuminate\Support\Collection;

/**
 * Builds the normalized per-service report model — the single structure
 * both the web report and the per-service PDFs render from, so the two can
 * never disagree about what the audit found.
 *
 * One audit produces N independent service reports (SEO, Performance, …),
 * each with its own score, its own issues grouped by category and
 * severity, its own recommendations, and its own page-level findings.
 * Nothing here invents data: a service with no score row is omitted
 * entirely (it never ran), and a service whose module reported
 * Not Applicable (§34) keeps that state rather than being shown a number.
 */
class ServiceReportBuilder
{
    public function __construct(
        private readonly IssueTaxonomy $taxonomy,
        private readonly PerformanceReportBuilder $performance,
    ) {}

    /**
     * Every service report for an audit, keyed by service.
     *
     * @return array<string, array<string, mixed>>
     */
    public function buildAll(Audit $audit): array
    {
        $audit->loadMissing(['issues.auditPage', 'issues.pages', 'pages.screenshots', 'scores']);

        $issuesByService = $audit->issues->groupBy(
            fn (AuditIssue $issue) => $this->taxonomy->serviceFor($issue->category) ?? 'other'
        );

        $scoresByService = $this->scoresByService($audit);
        $reports = [];

        foreach (IssueTaxonomy::SERVICES as $serviceKey => $serviceName) {
            $score = $scoresByService->get($serviceKey);

            // No score row at all means the module never ran for this
            // audit (e.g. the plan disables it, §33) — the service is
            // omitted rather than rendered as an empty or zero report.
            if ($score === null) {
                continue;
            }

            $reports[$serviceKey] = $this->buildService(
                $audit,
                $serviceKey,
                $serviceName,
                $score,
                $issuesByService->get($serviceKey, collect()),
            );
        }

        return $reports;
    }

    /**
     * A service's score row. CRO is backed by two internal categories
     * (conversion + landing_page, see IssueTaxonomy) so its score is the
     * mean of whichever of the two actually ran — a plain lookup would
     * arbitrarily pick one and silently discard the other.
     *
     * @return Collection<string, AuditScore>
     */
    private function scoresByService(Audit $audit): Collection
    {
        $byService = [];

        foreach ($audit->scores as $score) {
            if ($score->category === AuditCategory::Overall) {
                continue;
            }

            $service = $this->taxonomy->serviceFor($score->category);

            if ($service !== null) {
                $byService[$service][] = $score;
            }
        }

        return collect($byService)->map(function (array $scores) {
            if (count($scores) === 1) {
                return $scores[0];
            }

            $applicable = array_filter($scores, fn (AuditScore $s) => $s->is_applicable && $s->score !== null);

            if ($applicable === []) {
                return $scores[0];
            }

            // Synthesize a combined row rather than mutating a stored one.
            $merged = new AuditScore([
                'audit_id' => $scores[0]->audit_id,
                'category' => $scores[0]->category->value,
                'score' => (int) round(array_sum(array_map(fn (AuditScore $s) => $s->score, $applicable)) / count($applicable)),
                'is_applicable' => true,
            ]);
            $merged->exists = false;

            return $merged;
        });
    }

    /**
     * @param  Collection<int, AuditIssue>  $issues
     * @return array<string, mixed>
     */
    private function buildService(Audit $audit, string $serviceKey, string $serviceName, AuditScore $score, Collection $issues): array
    {
        $applicable = $score->is_applicable && $score->score !== null;
        $serialized = $issues->map(fn (AuditIssue $issue) => $this->serializeIssue($issue))->values();
        $ai = $this->aiState($audit, $serviceKey);

        // A service whose assessment depends on AI, where the AI did not
        // actually run, has only partial coverage — its deterministic
        // checks are real and worth keeping, but the score must never be
        // presented as a clean bill of health. Without this, an audit
        // where the provider was down reports "CRO 100 — good" on the
        // strength of a single form check, which is precisely the
        // manufactured score this engine must not produce.
        $partial = $applicable && $ai['used'] === true && $ai['available'] !== true;

        return [
            'service' => $serviceKey,
            'name' => $serviceName,
            'score' => $applicable ? $score->score : null,
            'is_applicable' => $applicable,
            'not_applicable_reason' => $applicable ? null : $this->notApplicableReason($audit, $serviceKey),
            'confidence' => $applicable ? ($partial ? 'partial' : 'complete') : 'unavailable',
            'coverage_note' => $partial ? $this->partialCoverageNote($serviceKey) : null,
            'status' => match (true) {
                ! $applicable => 'not_applicable',
                $partial => 'partial',
                default => $this->statusForScore($score->score),
            },
            'what_this_means' => $applicable ? $this->scoreExplanation($serviceKey, $score->score, $partial) : null,
            'severity_summary' => $this->severitySummary($serialized),
            'categories' => $this->groupByCategory($serialized),
            'issues' => $serialized->all(),
            'recommendations' => $this->buildRecommendations($serialized),
            'pages' => $applicable ? $this->pageFindings($audit, $issues) : [],
            'ai' => $ai,
            // Service-specific detail. Only performance has one today —
            // the deep Core Web Vitals / resource breakdown that the
            // generic issue shape can't express.
            'performance' => $serviceKey === 'performance' && $applicable
                ? $this->performance->build($audit)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeIssue(AuditIssue $issue): array
    {
        $meta = $issue->meta ?? [];

        // Single-page issues carry their page via audit_page_id;
        // multi-page ones carry it via the issue_pages pivot, with the URL
        // list mirrored into meta by PerPageIssueAggregator (§34).
        $affectedPages = $meta['affected_pages'] ?? [];
        if ($affectedPages === [] && $issue->auditPage !== null) {
            $affectedPages = [$issue->auditPage->url];
        }
        if ($affectedPages === [] && $issue->pages->isNotEmpty()) {
            $affectedPages = $issue->pages->pluck('url')->all();
        }

        $affectedCount = $meta['affected_page_count'] ?? count($affectedPages);

        return [
            'code' => $issue->code,
            'category' => $this->taxonomy->categoryFor($issue->category, $issue->code),
            'severity' => $issue->severity->value,
            'title' => $this->titleForIssue($issue),
            'message' => $issue->message,
            'recommendation' => $issue->recommendation,
            'evidence' => $meta,
            'affected_pages' => $affectedPages,
            'affected_page_count' => $affectedCount,
            'is_site_wide' => $issue->audit_page_id === null && $affectedCount !== 1,
            'detected_by' => $this->taxonomy->isAiGenerated($issue->code) ? 'ai' : 'rule',
        ];
    }

    /**
     * A short human title, distinct from the full message (which carries
     * counts/URLs). Derived from the code so it stays stable and readable
     * even for AI-generated findings.
     */
    private function titleForIssue(AuditIssue $issue): string
    {
        $code = $issue->code;

        if (str_starts_with($code, 'axe.')) {
            return ucfirst(str_replace('-', ' ', substr($code, 4)));
        }

        $last = substr($code, strrpos($code, '.') + 1);
        $words = explode(' ', str_replace('_', ' ', $last));

        // Keep well-known acronyms uppercase — "Lcp"/"Cls"/"Ttfb" read as
        // typos rather than as the metrics practitioners recognize.
        $acronyms = ['lcp', 'cls', 'tbt', 'ttfb', 'fcp', 'inp', 'seo', 'cta', 'aio', 'dom', 'css', 'html', 'url', 'ada'];

        return implode(' ', array_map(
            fn (string $word) => in_array($word, $acronyms, true) ? strtoupper($word) : ucfirst($word),
            $words,
        ));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $issues
     * @return array<string, int>
     */
    private function severitySummary(Collection $issues): array
    {
        $summary = [];

        foreach (IssueSeverity::cases() as $severity) {
            $summary[$severity->value] = $issues->where('severity', $severity->value)->count();
        }

        $summary['total'] = $issues->count();

        return $summary;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $issues
     * @return array<int, array<string, mixed>>
     */
    private function groupByCategory(Collection $issues): array
    {
        $order = array_flip(['critical', 'high', 'medium', 'low', 'passed']);

        return $issues
            ->groupBy('category')
            ->map(fn (Collection $group, string $category) => [
                'name' => $category,
                'issue_count' => $group->count(),
                'severity_summary' => $this->severitySummary($group),
                'issues' => $group
                    ->sortBy(fn (array $i) => $order[$i['severity']] ?? 99)
                    ->values()
                    ->all(),
            ])
            ->sortBy(fn (array $c) => -$c['issue_count'])
            ->values()
            ->all();
    }

    /**
     * Recommendations are derived strictly from issues that actually
     * carry one — never generated to fill space, so an empty list here
     * means the audit genuinely produced no actionable recommendation for
     * this service.
     *
     * @param  Collection<int, array<string, mixed>>  $issues
     * @return array<int, array<string, mixed>>
     */
    private function buildRecommendations(Collection $issues): array
    {
        $points = config('audit_scoring.severity_points');

        return $issues
            ->filter(fn (array $i) => ! empty($i['recommendation']))
            ->sortByDesc(fn (array $i) => $points[$i['severity']] ?? 0)
            ->map(fn (array $i) => [
                'title' => $i['title'],
                'category' => $i['category'],
                'severity' => $i['severity'],
                'priority' => $this->priorityForSeverity($i['severity']),
                'problem' => $i['message'],
                'recommended_action' => $i['recommendation'],
                'affected_pages' => $i['affected_pages'],
                'affected_page_count' => $i['affected_page_count'],
                'evidence' => $i['evidence'],
                'effort' => $this->effortForCode($i['code']),
                'detected_by' => $i['detected_by'],
            ])
            ->values()
            ->all();
    }

    private function priorityForSeverity(string $severity): string
    {
        return match ($severity) {
            'critical' => 'Immediate',
            'high' => 'High',
            'medium' => 'Medium',
            default => 'Low',
        };
    }

    /**
     * Effort is a coarse, rule-based hint from the kind of fix the code
     * implies — deliberately not AI-generated, since an invented effort
     * estimate would be indistinguishable from a measured one.
     */
    private function effortForCode(string $code): string
    {
        return match (true) {
            str_contains($code, 'meta_description'), str_contains($code, 'title'),
            str_contains($code, 'canonical'), str_contains($code, 'image_alt'),
            str_contains($code, 'h1') => 'Low',
            str_contains($code, 'pagespeed'), str_contains($code, 'lcp'),
            str_contains($code, 'cls'), str_contains($code, 'slow_load') => 'High',
            default => 'Medium',
        };
    }

    private function statusForScore(int $score): string
    {
        return match (true) {
            $score >= 90 => 'good',
            $score >= 50 => 'needs_improvement',
            default => 'poor',
        };
    }

    private function scoreExplanation(string $serviceKey, int $score, bool $partial): string
    {
        $name = $this->taxonomy->serviceName($serviceKey);

        if ($partial) {
            return "This {$name} score of {$score} reflects only the automated checks that completed. "
                .'The AI-assisted part of this assessment did not run for this audit, so treat this score as incomplete '
                .'rather than as a clean result — see the coverage note below.';
        }

        $band = match (true) {
            $score >= 90 => 'in good shape',
            $score >= 50 => 'workable but has clear room to improve',
            default => 'in need of significant work',
        };

        return "Your {$name} score is {$score} out of 100, which means this area is {$band}. "
            .'The score starts at 100 and drops based on the severity of each issue found below.';
    }

    /**
     * Names what was actually skipped, so "partial" is never a vague
     * disclaimer the reader can't act on.
     */
    private function partialCoverageNote(string $serviceKey): string
    {
        $skipped = match ($serviceKey) {
            'cro' => 'value proposition, trust signals, social proof and CTA clarity',
            'aio' => 'semantic content structure and entity clarity',
            'ecommerce' => 'product presentation and purchase-confidence checks',
            default => 'the AI-assisted checks',
        };

        return "The AI provider was unavailable for this audit, so {$skipped} were not assessed. "
            .'Only the rule-based checks contributed to this score. Configure an AI provider and re-run the audit for full coverage.';
    }

    private function notApplicableReason(Audit $audit, string $serviceKey): string
    {
        return match ($serviceKey) {
            'ecommerce' => 'This site did not show enough ecommerce signals to audit as a store, so no ecommerce score was generated.',
            default => 'There was not enough data to score this area for this site.',
        };
    }

    /**
     * Per-page findings for one service: only pages that actually have an
     * issue in this service, each with its own issue breakdown and both
     * screenshots.
     *
     * @param  Collection<int, AuditIssue>  $issues
     * @return array<int, array<string, mixed>>
     */
    private function pageFindings(Audit $audit, Collection $issues): array
    {
        $issueIds = $issues->pluck('id')->all();
        $rows = [];

        foreach ($audit->pages as $page) {
            $pageIssues = $page->allIssues()->filter(
                fn (AuditIssue $i) => in_array($i->id, $issueIds, true)
            );

            if ($pageIssues->isEmpty()) {
                continue;
            }

            $serialized = $pageIssues->map(fn (AuditIssue $i) => $this->serializeIssue($i))->values();

            $rows[] = [
                'url' => $page->url,
                'title' => $page->title,
                'http_status' => $page->http_status,
                'issue_count' => $serialized->count(),
                'severity_summary' => $this->severitySummary($serialized),
                'issues' => $serialized->all(),
                'screenshots' => $this->screenshotsFor($page),
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['issue_count'] <=> $a['issue_count']);

        return $rows;
    }

    /**
     * @return array<string, string|null>
     */
    private function screenshotsFor(AuditPage $page): array
    {
        $byViewport = $page->screenshots->keyBy(fn (Screenshot $s) => $s->viewport->value);

        return [
            'desktop' => $byViewport->get('desktop')?->storage_path,
            'mobile' => $byViewport->get('mobile')?->storage_path,
        ];
    }

    /**
     * Whether AI analysis is genuinely available for this service, and if
     * not, why. An unavailable provider must read as unavailable — never
     * as an absent or empty finding that could be mistaken for "the AI
     * looked and found nothing" (§34).
     *
     * @return array<string, mixed>
     */
    private function aiState(Audit $audit, string $serviceKey): array
    {
        $categories = array_keys(array_filter(
            ['conversion' => 'cro', 'landing_page' => 'cro', 'ecommerce' => 'ecommerce', 'aio' => 'aio'],
            fn (string $s) => $s === $serviceKey,
        ));

        if ($categories === []) {
            return ['used' => false, 'available' => null, 'analysis' => null, 'error' => null];
        }

        $records = AiAnalysis::where('audit_id', $audit->id)
            ->whereIn('category', $categories)
            ->get();

        if ($records->isEmpty()) {
            return ['used' => false, 'available' => null, 'analysis' => null, 'error' => null];
        }

        $successful = $records->firstWhere('error', null);

        if ($successful !== null) {
            return ['used' => true, 'available' => true, 'analysis' => $successful->response, 'error' => null];
        }

        return [
            'used' => true,
            'available' => false,
            'analysis' => null,
            'error' => $records->first()->error,
        ];
    }
}
