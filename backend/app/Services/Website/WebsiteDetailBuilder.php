<?php

namespace App\Services\Website;

use App\Enums\AuditCategory;
use App\Enums\AuditStatus;
use App\Enums\IssueSeverity;
use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\AuditScore;
use App\Models\Website;
use App\Models\WebsiteCompetitor;
use App\Services\Audit\AioContextBuilder;
use App\Services\Report\PageExplorerBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The website detail view: everything about one website across all of its
 * audits.
 *
 * The trend sections are the reason this is a website-level view rather
 * than a report-level one — a single audit can say what is wrong today,
 * but only the history says whether it is getting better.
 *
 * Authorization is *not* done here. The caller has already established
 * that this website belongs to the requester (see WebsiteController);
 * passing an arbitrary Website in would happily render it.
 */
class WebsiteDetailBuilder
{
    /** How many audits back the trend and history sections look. */
    private const HISTORY_LIMIT = 20;

    /** How many recommendations to surface; worst-first, so the tail is noise. */
    private const RECOMMENDATION_LIMIT = 10;

    public function __construct(
        private readonly WebsiteCardBuilder $cards,
        private readonly PageExplorerBuilder $pages,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Website $website): array
    {
        $latestCompleted = $website->latestCompletedAudit;

        // Oldest-first, because that is the order a trend line is read.
        // The limit is applied to the *most recent* N first, then flipped.
        $history = $website->audits()
            ->where('status', AuditStatus::Completed)
            ->with('scores')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->reverse()
            ->values();

        // Every audit the page will mention, whatever its status — the
        // history section lists failed and in-flight runs that the trend
        // sections exclude, and both read their counts from here.
        $recent = $website->audits()
            ->with('scores')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get();

        $issueCounts = $this->issueCountsByAudit(
            $recent->pluck('id')->merge($history->pluck('id'))->unique()->all(),
        );

        return [
            'overview' => $this->overview($website),
            'latest_audit' => $this->latestAudit($website, $latestCompleted),
            'audit_history' => $this->auditHistory($recent, $issueCounts),
            'score_trends' => $this->scoreTrends($history),
            'service_scores' => $this->serviceScores($latestCompleted),
            'issue_trends' => $this->issueTrends($history, $issueCounts),
            'pages' => $latestCompleted !== null ? $this->pages->build($latestCompleted) : [],
            'recommendations' => $this->recommendations($latestCompleted),
            // The business profile the AIO module reasons from, and what is
            // still missing (section 42). Surfaced on the detail view because
            // that is where an owner is already looking at their site.
            'onboarding' => $this->onboarding($website),
            'competitors' => $this->competitors($website),
            'consultation' => [
                // What a "Request Consultation" submission should attach
                // itself to, so the lead arrives with the findings that
                // prompted it rather than as a bare contact form.
                'audit_id' => $latestCompleted?->id,
                'website_url' => $website->primary_url,
            ],
        ];
    }

    /**
     * Which onboarding fields are filled in, and what the AIO module can
     * therefore ask. `aio_goal` is called out separately because it alone
     * decides whether the three context-dependent AIO checks run at all.
     *
     * @return array<string, mixed>
     */
    private function onboarding(Website $website): array
    {
        $context = app(AioContextBuilder::class);
        $missing = $context->missingFields($website);

        return [
            'aio_goal' => $website->aio_goal,
            'missing_fields' => $missing,
            'is_complete' => $missing === [],
            // False means three of the ten AIO checks are skipped, not that
            // the audit is invalid — the report says which and why.
            'unlocks_context_prompts' => $context->isSufficient($website),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function competitors(Website $website): array
    {
        return $website->competitorRecords()
            ->orderByRaw("CASE WHEN source = 'user' THEN 0 ELSE 1 END")
            ->orderByDesc('aio_score')
            ->get()
            ->map(fn (WebsiteCompetitor $c) => [
                'id' => $c->id,
                'name' => $c->displayName(),
                'domain' => $c->normalized_domain,
                'url' => $c->url,
                'source' => $c->source->value,
                'source_label' => $c->source->label(),
                'reason' => $c->reason,
                'is_analyzed' => $c->isAnalyzed(),
                // Null throughout when unanalyzed. A competitor we could not
                // reach must never render as one scoring zero.
                'aio_score' => $c->aio_score,
                'pages_analyzed' => $c->pages_analyzed,
                'topics' => $c->topics ?? [],
                'visibility' => $c->visibility,
                'analysis_error' => $c->analysis_error,
                'analyzed_at' => $c->analyzed_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function overview(Website $website): array
    {
        return array_merge($this->cards->buildOne($website), [
            'category' => $website->category,
            'description' => $website->description,
            'products_or_services' => $website->products_or_services,
            'aio_goal' => $website->aio_goal,
            'country' => $website->country,
            'budget' => $website->budget,
            'average_product_cost' => $website->average_product_cost,
            'competitors' => $website->competitors ?? [],
            'platform' => $website->platform?->value,
            'created_at' => $website->created_at?->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestAudit(Website $website, ?Audit $latestCompleted): ?array
    {
        $summary = $this->cards->auditSummary($latestCompleted);

        if ($summary === null) {
            // No completed audit yet, but there may be one running — the
            // detail page needs something to show for a website whose
            // first audit is still in flight.
            return $this->cards->auditSummary($website->latestAudit);
        }

        $summary['scores'] = $latestCompleted->scores
            ->map(fn (AuditScore $s) => $this->scoreRow($s))
            ->values()
            ->all();

        return $summary;
    }

    /**
     * Every audit ever run for this website, newest first — including
     * failed and in-flight ones, which the trend sections deliberately
     * exclude. A user looking at "Audit History" wants to see the run that
     * failed; a trend line that plotted it would just show a false cliff.
     *
     * @param  Collection<int, Audit>  $recent
     * @param  array<int, array<string, int>>  $issueCounts
     * @return array<int, array<string, mixed>>
     */
    private function auditHistory($recent, array $issueCounts): array
    {
        return $recent
            ->map(function (Audit $audit) use ($issueCounts) {
                $counts = $issueCounts[$audit->id] ?? [];

                return [
                    'id' => $audit->id,
                    'status' => $audit->status->value,
                    'created_at' => $audit->created_at?->toIso8601String(),
                    'completed_at' => $audit->completed_at?->toIso8601String(),
                    'pages_crawled' => $audit->pages_crawled,
                    'error_message' => $audit->error_message,
                    'overall_score' => $audit->scores->firstWhere('category', AuditCategory::Overall->value)?->score,
                    'critical_issues' => $counts[IssueSeverity::Critical->value] ?? 0,
                    'high_issues' => $counts[IssueSeverity::High->value] ?? 0,
                ];
            })
            ->all();
    }

    /**
     * One series per category (overall plus the card categories), each a
     * list of points in chronological order.
     *
     * A category is only included if at least one audit actually scored it
     * — an empty series would render as a flat line at zero, which reads
     * as "we measured this and it is terrible" rather than "we never ran
     * this check".
     *
     * @param  Collection<int, Audit>  $history
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function scoreTrends($history): array
    {
        $categories = array_merge([AuditCategory::Overall], WebsiteCardBuilder::cardCategories());
        $trends = [];

        foreach ($categories as $category) {
            $points = $history
                ->map(function (Audit $audit) use ($category) {
                    $score = $audit->scores->firstWhere('category', $category->value);

                    if ($score === null || $score->score === null) {
                        return null;
                    }

                    return [
                        'audit_id' => $audit->id,
                        'date' => $audit->completed_at?->toIso8601String() ?? $audit->created_at?->toIso8601String(),
                        'score' => $score->score,
                    ];
                })
                ->filter()
                ->values()
                ->all();

            if ($points !== []) {
                $trends[$category->value] = $points;
            }
        }

        return $trends;
    }

    /**
     * The latest completed audit's score for every category it ran,
     * carrying `is_applicable` so a Not Applicable service stays
     * distinguishable from one that scored zero.
     *
     * @return array<int, array<string, mixed>>
     */
    private function serviceScores(?Audit $audit): array
    {
        if ($audit === null) {
            return [];
        }

        return $audit->scores
            ->reject(fn (AuditScore $s) => $this->categoryOf($s) === AuditCategory::Overall->value)
            ->map(fn (AuditScore $s) => $this->scoreRow($s))
            ->values()
            ->all();
    }

    /**
     * `audit_scores.category` is enum-cast, so `$score->category` is an
     * AuditCategory, not a string — `=== 'overall'` is always false and
     * silently lets the overall score through as if it were a service.
     * Every comparison and every emitted value goes through here.
     */
    private function categoryOf(AuditScore $score): string
    {
        return $score->category instanceof AuditCategory
            ? $score->category->value
            : (string) $score->category;
    }

    /**
     * @return array<string, mixed>
     */
    private function scoreRow(AuditScore $score): array
    {
        return [
            'category' => $this->categoryOf($score),
            'score' => $score->score,
            'is_applicable' => (bool) $score->is_applicable,
        ];
    }

    /**
     * Issue counts per audit over time, by severity — the "is this getting
     * better" view that a single audit's numbers cannot give.
     *
     * @param  Collection<int, Audit>  $history
     * @param  array<int, array<string, int>>  $issueCounts
     * @return array<int, array<string, mixed>>
     */
    private function issueTrends($history, array $issueCounts): array
    {
        return $history->map(function (Audit $audit) use ($issueCounts) {
            $counts = $issueCounts[$audit->id] ?? [];

            return [
                'audit_id' => $audit->id,
                'date' => $audit->completed_at?->toIso8601String() ?? $audit->created_at?->toIso8601String(),
                'critical' => $counts[IssueSeverity::Critical->value] ?? 0,
                'high' => $counts[IssueSeverity::High->value] ?? 0,
                'medium' => $counts[IssueSeverity::Medium->value] ?? 0,
                'low' => $counts[IssueSeverity::Low->value] ?? 0,
                // Carried alongside since §52: an issue count only means
                // something next to how much of the site was crawled to
                // find them.
                'pages_analyzed' => $audit->pages_crawled ?? 0,
            ];
        })->all();
    }

    /**
     * The latest audit's issues that actually carry a recommendation,
     * worst-first.
     *
     * Only issues with a recommendation are returned — never a message
     * reworded to look like advice. An issue with nothing actionable
     * attached belongs in the report's findings list, not here.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recommendations(?Audit $audit): array
    {
        if ($audit === null) {
            return [];
        }

        $severityRank = [
            IssueSeverity::Critical->value => 0,
            IssueSeverity::High->value => 1,
            IssueSeverity::Medium->value => 2,
            IssueSeverity::Low->value => 3,
        ];

        return $audit->issues()
            ->whereNotNull('recommendation')
            ->where('recommendation', '!=', '')
            ->get()
            ->sortBy(fn (AuditIssue $issue) => $severityRank[$issue->severity->value] ?? 99)
            ->take(self::RECOMMENDATION_LIMIT)
            ->map(fn (AuditIssue $issue) => [
                'category' => $issue->category->value,
                'severity' => $issue->severity->value,
                'code' => $issue->code,
                'message' => $issue->message,
                'recommendation' => $issue->recommendation,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $auditIds
     * @return array<int, array<string, int>>
     */
    private function issueCountsByAudit(array $auditIds): array
    {
        if ($auditIds === []) {
            return [];
        }

        // Query builder rather than Eloquent: `severity` is enum-cast on
        // AuditIssue, and an enum object cannot be used as an array key.
        return DB::table('audit_issues')
            ->selectRaw('audit_id, severity, COUNT(*) as total')
            ->whereIn('audit_id', $auditIds)
            ->groupBy('audit_id', 'severity')
            ->get()
            ->groupBy('audit_id')
            ->map(fn ($rows) => $rows->pluck('total', 'severity')->map(fn ($n) => (int) $n)->all())
            ->all();
    }
}
