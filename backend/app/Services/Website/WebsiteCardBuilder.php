<?php

namespace App\Services\Website;

use App\Enums\AuditCategory;
use App\Enums\AuditStatus;
use App\Enums\IssueSeverity;
use App\Enums\ScreenshotViewport;
use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\AuditScore;
use App\Models\Screenshot;
use App\Models\Website;
use App\Services\Report\ScreenshotUrlSigner;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The "My Websites" card: one per website, summarising its latest
 * *completed* audit.
 *
 * Everything here is read from the audit that finished, not the most
 * recent one — a card must not blank out its scores the moment the user
 * starts a re-audit, and a failed run's partial scores are not a result.
 * `latest_audit` reports the in-flight run separately so the UI can show
 * "audit running" alongside the last known numbers.
 */
class WebsiteCardBuilder
{
    /**
     * The scores a card shows, in display order. A card is a fixed shape:
     * every key is present for every website, null when that category did
     * not run or did not apply, so the frontend never has to distinguish
     * "missing from the payload" from "no score".
     */
    private const CARD_CATEGORIES = [
        AuditCategory::Performance,
        AuditCategory::Accessibility,
        AuditCategory::Seo,
        AuditCategory::Conversion,
        AuditCategory::Aio,
    ];

    /**
     * @param  Collection<int, Website>  $websites
     * @return array<int, array<string, mixed>>
     */
    public function buildMany(Collection $websites): array
    {
        // Normalized to an Eloquent collection so eager loading works
        // whether the caller handed us a paginator's collection or a plain
        // `collect([$website])`.
        $websites = new EloquentCollection($websites->all());

        // Loaded in bulk across every website on the page rather than
        // per-card, so a 25-website listing costs a fixed handful of
        // queries instead of five per row.
        $websites->loadMissing(['latestCompletedAudit', 'latestAudit']);

        $completedAuditIds = $websites
            ->pluck('latestCompletedAudit.id')
            ->filter()
            ->values();

        $scores = AuditScore::whereIn('audit_id', $completedAuditIds)->get()->groupBy('audit_id');
        $issueCounts = $this->issueCountsFor($completedAuditIds);
        $screenshots = $this->screenshotsFor($completedAuditIds);

        return $websites->map(fn (Website $website) => $this->build(
            $website,
            $scores,
            $issueCounts,
            $screenshots,
        ))->all();
    }

    /**
     * @param  Collection<int, Collection<int, AuditScore>>  $scores
     * @param  array<int, array<string, int>>  $issueCounts
     * @param  array<int, string>  $screenshots
     * @return array<string, mixed>
     */
    private function build(Website $website, Collection $scores, array $issueCounts, array $screenshots): array
    {
        $audit = $website->latestCompletedAudit;
        $auditScores = $audit !== null ? ($scores[$audit->id] ?? collect()) : collect();
        $counts = $audit !== null ? ($issueCounts[$audit->id] ?? []) : [];

        return [
            'id' => $website->id,
            'name' => $website->displayName(),
            'domain' => $website->domain,
            'normalized_domain' => $website->normalized_domain,
            'primary_url' => $website->primary_url,
            'screenshot_url' => $audit !== null ? ($screenshots[$audit->id] ?? null) : null,
            'audits_count' => $website->audits_count ?? $website->audits()->count(),

            'last_audit_at' => $audit?->completed_at?->toIso8601String(),
            'last_audit_id' => $audit?->id,
            'pages_analyzed' => $audit?->pages_crawled,

            'overall_score' => $this->scoreFor($auditScores, AuditCategory::Overall),
            'scores' => $this->cardScores($auditScores),

            'critical_issues' => $counts[IssueSeverity::Critical->value] ?? 0,
            'high_issues' => $counts[IssueSeverity::High->value] ?? 0,

            // The run in progress, if any — reported alongside rather than
            // instead of the numbers above, so "re-auditing now" and "here
            // is what we last found" can both be true on one card.
            'latest_audit' => $this->inFlightAudit($website),
        ];
    }

    /**
     * @param  Collection<int, AuditScore>  $auditScores
     * @return array<string, int|null>
     */
    private function cardScores(Collection $auditScores): array
    {
        $out = [];

        foreach (self::CARD_CATEGORIES as $category) {
            $out[$category->value] = $this->scoreFor($auditScores, $category);
        }

        return $out;
    }

    /**
     * @param  Collection<int, AuditScore>  $auditScores
     */
    private function scoreFor(Collection $auditScores, AuditCategory $category): ?int
    {
        $score = $auditScores->firstWhere('category', $category->value);

        // A Not Applicable category (§34) stores score=null,
        // is_applicable=false. Returning null for it is right — the card
        // shows a dash, never a fabricated number.
        return $score?->score;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function inFlightAudit(Website $website): ?array
    {
        $latest = $website->latestAudit;

        if ($latest === null || $latest->status === AuditStatus::Completed) {
            return null;
        }

        return [
            'id' => $latest->id,
            'status' => $latest->status->value,
            'pages_crawled' => $latest->pages_crawled,
            'max_pages' => $latest->max_pages,
        ];
    }

    /**
     * Severity counts per audit, in one grouped query rather than a count
     * per card.
     *
     * @param  Collection<int, int>  $auditIds
     * @return array<int, array<string, int>>
     */
    private function issueCountsFor(Collection $auditIds): array
    {
        if ($auditIds->isEmpty()) {
            return [];
        }

        // The query builder, not Eloquent: `severity` is an enum-cast
        // attribute on AuditIssue, and an enum object cannot be an array
        // key — keying the result by it throws "Illegal offset type".
        // Aggregate rows want raw column values anyway.
        return DB::table('audit_issues')
            ->selectRaw('audit_id, severity, COUNT(*) as total')
            ->whereIn('audit_id', $auditIds)
            ->whereIn('severity', [IssueSeverity::Critical->value, IssueSeverity::High->value])
            ->groupBy('audit_id', 'severity')
            ->get()
            ->groupBy('audit_id')
            ->map(fn ($rows) => $rows->pluck('total', 'severity')->map(fn ($n) => (int) $n)->all())
            ->all();
    }

    /**
     * The homepage's desktop screenshot for each audit, keyed by audit id.
     *
     * "Homepage" is the lowest `audit_page_id` in the audit: the
     * orchestrator crawls the homepage first and every other page is
     * discovered from it, so insertion order is the reliable signal —
     * matching on URL would fail whenever the site redirects its root.
     *
     * @param  Collection<int, int>  $auditIds
     * @return array<int, string>
     */
    private function screenshotsFor(Collection $auditIds): array
    {
        if ($auditIds->isEmpty()) {
            return [];
        }

        // Signed, not a disk URL (§48). `$disk->url()` only produces a
        // working address on a public disk, which is exactly what these
        // stopped being — and a card thumbnail is still a capture of
        // someone's site.
        $signer = app(ScreenshotUrlSigner::class);

        return Screenshot::query()
            ->whereIn('audit_id', $auditIds)
            ->where('viewport', ScreenshotViewport::Desktop->value)
            ->orderBy('audit_page_id')
            ->get()
            ->groupBy('audit_id')
            ->map(fn ($shots) => $signer->urlFor($shots->first()))
            ->all();
    }

    /**
     * Single-website variant, for the detail view's header.
     *
     * @return array<string, mixed>
     */
    public function buildOne(Website $website): array
    {
        return $this->buildMany(collect([$website]))[0];
    }

    /** Exposed so the detail builder reports the same categories as the card. */
    public static function cardCategories(): array
    {
        return self::CARD_CATEGORIES;
    }

    /** @return array<string, mixed>|null */
    public function auditSummary(?Audit $audit): ?array
    {
        if ($audit === null) {
            return null;
        }

        return [
            'id' => $audit->id,
            'status' => $audit->status->value,
            'pages_crawled' => $audit->pages_crawled,
            'max_pages' => $audit->max_pages,
            'started_at' => $audit->started_at?->toIso8601String(),
            'completed_at' => $audit->completed_at?->toIso8601String(),
            'created_at' => $audit->created_at?->toIso8601String(),
        ];
    }
}
