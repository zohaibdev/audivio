<?php

namespace App\Services\Audit;

use App\Enums\AuditCategory;
use App\Enums\AuditStage;
use App\Enums\AuditStatus;
use App\Models\Audit;
use App\Models\AuditScore;
use App\Models\Screenshot;
use App\Services\Report\IssueTaxonomy;

/**
 * What an audit is actually doing, and how much longer it will take (§51).
 *
 * The rule that shapes every method here: **never show fake progress.**
 * No timer standing in for work, no bar that creeps toward 90% because
 * someone is waiting, no estimate before there is anything to estimate
 * from. Where the honest answer is "we do not know yet", these methods
 * return null and the UI says so — a missing estimate is a smaller
 * betrayal than a wrong one, because a wrong one is still believed at the
 * five-minute mark.
 *
 * Every figure is read from something that happened: rows that exist,
 * counters the crawler moved, score rows the modules wrote.
 */
class AuditProgressCalculator
{
    /**
     * @return array<string, mixed>
     */
    public function forAudit(Audit $audit): array
    {
        $audit->loadMissing(['crawlJob', 'scores']);

        $stage = $this->currentStage($audit);
        $crawl = $this->crawlProgress($audit);
        $services = $this->services($audit);

        return [
            'stage' => $stage?->value,
            'stage_label' => $stage?->label(),
            'stages' => $this->stageList($audit, $stage),
            'pages_discovered' => $crawl['discovered'],
            'pages_analyzed' => $crawl['crawled'],
            'screenshots_captured' => $crawl['screenshots'],
            'services' => $services,
            'services_running' => collect($services)->where('state', 'running')->pluck('label')->values()->all(),
            'estimate' => $this->estimate($audit, $crawl, $services),
            // Said plainly rather than left for the reader to infer from a
            // slow bar: the page limit is the single biggest driver of how
            // long this takes.
            'scale_note' => $this->scaleNote($audit),
        ];
    }

    /**
     * The stage this audit is in right now.
     *
     * Ordered by the narrowest evidence first: a screenshot that exists
     * says more about where we are than a status column that covers three
     * stages at once.
     */
    public function currentStage(Audit $audit): ?AuditStage
    {
        return match ($audit->status) {
            AuditStatus::Queued => AuditStage::Preparing,
            AuditStatus::Validating => AuditStage::Validating,
            AuditStatus::Crawling => $this->crawlStage($audit),
            AuditStatus::Analyzing => $this->analysisStage($audit),
            AuditStatus::GeneratingReport => AuditStage::BuildingReport,
            // Terminal states have no current stage. Reporting one would
            // suggest something is still happening.
            default => null,
        };
    }

    private function crawlStage(Audit $audit): AuditStage
    {
        $job = $audit->crawlJob;

        // No crawl job, or nothing discovered yet: still working out what
        // pages exist.
        if ($job === null || $job->total_urls <= 1) {
            return AuditStage::Discovering;
        }

        // Screenshots are captured during the same page load as the
        // crawl, so this is not a later phase — it is the part of the
        // crawl worth naming once it is visibly happening.
        $crawled = $audit->pages_crawled ?? 0;

        return $crawled > 0 && Screenshot::where('audit_id', $audit->id)->exists()
            ? AuditStage::Screenshots
            : AuditStage::Crawling;
    }

    /**
     * Which analysis is outstanding.
     *
     * The modules run in parallel on the queue, so "the current stage" is
     * a simplification — this reports the first one still unfinished, in
     * display order, which is what a reader needs to see moving.
     */
    private function analysisStage(Audit $audit): AuditStage
    {
        $done = $this->completedCategories($audit);

        foreach (AuditStage::ordered() as $stage) {
            $category = $stage->category();

            if ($category !== null && ! in_array($category->value, $done, true)) {
                return $stage;
            }
        }

        // Every named analysis is done but the audit has not moved on:
        // the AI-driven modules are still producing their findings.
        return AuditStage::Recommendations;
    }

    /**
     * @return array{discovered: int, crawled: int, screenshots: int, remaining: int}
     */
    private function crawlProgress(Audit $audit): array
    {
        $job = $audit->crawlJob;

        // `total_urls` is what the crawler has actually discovered so far,
        // not the plan's page cap. Showing the cap would promise pages
        // that may not exist.
        $discovered = $job?->total_urls ?? 0;
        $crawled = $audit->pages_crawled ?? 0;

        return [
            'discovered' => $discovered,
            'crawled' => $crawled,
            'screenshots' => Screenshot::where('audit_id', $audit->id)->count(),
            'remaining' => max(0, $discovered - $crawled),
        ];
    }

    /**
     * Every service this audit will run, and where each one is.
     *
     * Derived from the plan's allowed modules and the score rows written
     * so far — a service is "done" because its result exists, not because
     * enough time has passed.
     *
     * @return array<int, array<string, mixed>>
     */
    private function services(Audit $audit): array
    {
        $allowed = collect(AuditModuleRegistry::modulesFor($audit))
            ->map(fn (string $class) => app($class)->category())
            ->reject(fn (AuditCategory $category) => $category === AuditCategory::Overall);

        $done = $this->completedCategories($audit);
        $isAnalyzing = $audit->status === AuditStatus::Analyzing;

        // Names come from IssueTaxonomy, the one place service names
        // live. A second label map on the enum would be the same drift
        // that put a rule in two grouping tables at once (§28).
        $taxonomy = app(IssueTaxonomy::class);

        return $allowed->map(fn (AuditCategory $category) => [
            'category' => $category->value,
            'label' => $taxonomy->serviceName($taxonomy->serviceFor($category) ?? $category->value),
            'state' => match (true) {
                in_array($category->value, $done, true) => 'done',
                $isAnalyzing => 'running',
                default => 'pending',
            },
        ])->values()->all();
    }

    /**
     * @return string[]
     */
    private function completedCategories(Audit $audit): array
    {
        return $audit->scores
            ->map(fn (AuditScore $score) => $score->category->value)
            ->reject(fn (string $category) => $category === AuditCategory::Overall->value)
            ->values()
            ->all();
    }

    /**
     * How much longer, measured from this audit's own pace.
     *
     * The only input is what this crawl has actually done: seconds
     * elapsed divided by pages completed gives a real rate, and the
     * remaining pages give a real projection. Before the first page
     * finishes there is no rate, so there is no estimate — a number
     * invented at that moment would be a guess dressed as a measurement,
     * and the reader has no way to tell the difference.
     *
     * @param  array{discovered: int, crawled: int, screenshots: int, remaining: int}  $crawl
     * @param  array<int, array<string, mixed>>  $services
     * @return array<string, mixed>
     */
    private function estimate(Audit $audit, array $crawl, array $services): array
    {
        $unknown = [
            'is_available' => false,
            'reason' => 'The estimate appears once the first few pages have been analyzed — it is measured from this audit\'s own pace rather than guessed up front.',
            'remaining_seconds' => null,
            'elapsed_seconds' => null,
            'total_seconds' => null,
        ];

        $startedAt = $audit->started_at ?? $audit->created_at;

        if ($startedAt === null || ! $audit->status->isActive()) {
            return $unknown;
        }

        $elapsed = max(1, now()->diffInSeconds($startedAt, absolute: true));

        if ($crawl['crawled'] < 1) {
            return array_merge($unknown, ['elapsed_seconds' => $elapsed]);
        }

        $secondsPerPage = $elapsed / $crawl['crawled'];
        $crawlRemaining = (int) round($secondsPerPage * $crawl['remaining']);

        // Analysis is not per-page: it is a roughly fixed cost per service
        // once the crawl is done, so it is projected from the services
        // still outstanding rather than from the page rate.
        $outstanding = collect($services)->where('state', '!=', 'done')->count();
        $analysisRemaining = $outstanding * (int) config('audit_progress.seconds_per_service');

        $remaining = $crawlRemaining + $analysisRemaining;

        return [
            'is_available' => true,
            'reason' => null,
            'remaining_seconds' => $remaining,
            'elapsed_seconds' => $elapsed,
            'total_seconds' => $elapsed + $remaining,
            // Stated so the number is read as what it is — a projection
            // from this run's own measured rate, which will move as the
            // rate does.
            'basis' => "Projected from {$crawl['crawled']} page(s) analyzed so far.",
        ];
    }

    private function scaleNote(Audit $audit): ?string
    {
        $limit = (int) $audit->max_pages;

        if ($limit <= (int) config('audit_progress.large_site_pages')) {
            return null;
        }

        return "This audit covers up to {$limit} pages. Larger sites take proportionally longer — every page is crawled, screenshotted and analyzed individually.";
    }

    /**
     * The whole journey, with each stage marked done, current or ahead.
     *
     * @return array<int, array<string, mixed>>
     */
    private function stageList(Audit $audit, ?AuditStage $current): array
    {
        $currentIndex = $current === null ? null : array_search($current, AuditStage::ordered(), true);
        $isComplete = $audit->status === AuditStatus::Completed;

        return collect(AuditStage::ordered())
            ->map(function (AuditStage $stage, int $index) use ($currentIndex, $isComplete) {
                return [
                    'stage' => $stage->value,
                    'label' => $stage->label(),
                    'state' => match (true) {
                        $isComplete => 'done',
                        $currentIndex === null => 'pending',
                        $index < $currentIndex => 'done',
                        $index === $currentIndex => 'current',
                        default => 'pending',
                    },
                ];
            })
            ->all();
    }
}
