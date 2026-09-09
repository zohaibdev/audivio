<?php

namespace App\Services\Audit;

use App\Enums\AuditCategory;
use App\Models\AiAnalysis;
use App\Models\ApiProvider;
use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\AuditPage;
use App\Models\AuditScore;
use App\Models\AuditSnapshot;
use App\Models\PagePerformanceMetric;
use App\Models\Screenshot;
use Illuminate\Support\Facades\Storage;

/**
 * Writes the one-time immutable record of a finished audit (§38).
 *
 * Everything is denormalized into `content` rather than referenced by id.
 * That is the point: the snapshot has to keep meaning after the website
 * is renamed, the scoring weights change, the AI model is swapped, the
 * issue rows are re-derived by a later re-audit, or the plan's categories
 * change. A snapshot full of foreign keys would silently start telling a
 * different story.
 */
class AuditSnapshotBuilder
{
    /**
     * Write the snapshot for a completed audit, if it does not exist.
     *
     * Idempotent by returning the existing row rather than rewriting it —
     * `GenerateReportJob` retries, and a retry must not be able to alter
     * a snapshot that is already written.
     */
    public function build(Audit $audit, bool $backfilled = false): AuditSnapshot
    {
        $existing = AuditSnapshot::where('audit_id', $audit->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $audit->loadMissing(['website', 'scores', 'issues.auditPage', 'pages']);

        $ai = $this->aiProvenance($audit);

        return AuditSnapshot::create([
            'audit_id' => $audit->id,
            'website_url' => $audit->website->primary_url,
            'domain' => $audit->website->domain,

            'crawler_version' => (string) config('audit_versions.crawler'),
            'scoring_version' => (string) config('audit_versions.scoring'),
            'prompt_version' => (string) config('audit_versions.prompt'),
            'ai_provider' => $ai['provider'],
            'ai_model' => $ai['model'],

            'pages_analyzed' => $audit->pages()->count(),
            'audited_at' => $audit->completed_at ?? now(),

            'content' => [
                'website' => $this->website($audit),
                'scores' => $this->scores($audit),
                'service_results' => $this->serviceResults($audit),
                'issues' => $this->issues($audit),
                'recommendations' => $this->recommendations($audit),
                'pages' => $this->pages($audit),
                'screenshots' => $this->screenshots($audit),
                'performance' => $this->performance($audit),
                'ai' => $ai,
                'versions' => [
                    'crawler' => (string) config('audit_versions.crawler'),
                    'scoring' => (string) config('audit_versions.scoring'),
                    'prompt' => (string) config('audit_versions.prompt'),
                ],
                // Marks a snapshot assembled after the fact, for an audit
                // that finished before snapshots existed. The findings in
                // it are real stored data; the version stamps are inferred
                // from current config rather than recorded at the time, so
                // a reader has to be able to tell the difference (§38).
                'backfilled' => $backfilled,
                'backfilled_at' => $backfilled ? now()->toIso8601String() : null,
                // The weights themselves, not just their version string:
                // reading a two-year-old snapshot should not require
                // digging a matching config out of git history.
                'scoring_config' => [
                    'category_weights' => config('audit_scoring.category_weights'),
                    'severity_points' => config('audit_scoring.severity_points'),
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function website(Audit $audit): array
    {
        $website = $audit->website;

        return [
            'id' => $website->id,
            'name' => $website->displayName(),
            'primary_url' => $website->primary_url,
            'domain' => $website->domain,
            'normalized_domain' => $website->normalized_domain,
            'platform' => $website->platform?->value,
            'category' => $website->category,
            'country' => $website->country,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scores(Audit $audit): array
    {
        $overall = $audit->scores->first(
            fn (AuditScore $s) => $this->categoryOf($s) === AuditCategory::Overall->value
        );

        return [
            'overall' => $overall?->score,
            'categories' => $audit->scores
                ->reject(fn (AuditScore $s) => $this->categoryOf($s) === AuditCategory::Overall->value)
                ->map(fn (AuditScore $s) => [
                    'category' => $this->categoryOf($s),
                    'score' => $s->score,
                    'is_applicable' => (bool) $s->is_applicable,
                    'breakdown' => $s->breakdown,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Per-service outcome, including which services did *not* run. A
     * snapshot that silently omitted a disabled category would read as
     * "this site had no accessibility problems".
     *
     * @return array<int, array<string, mixed>>
     */
    private function serviceResults(Audit $audit): array
    {
        $issuesByCategory = $audit->issues->groupBy(fn (AuditIssue $i) => $i->category->value);
        $ranCategories = $audit->scores->map(fn (AuditScore $s) => $this->categoryOf($s));

        $results = [];

        foreach (AuditModuleRegistry::modules() as $moduleClass) {
            $category = app($moduleClass)->category()->value;
            $score = $audit->scores->first(fn (AuditScore $s) => $this->categoryOf($s) === $category);

            $results[] = [
                'category' => $category,
                'ran' => $ranCategories->contains($category),
                'score' => $score?->score,
                'is_applicable' => $score === null ? null : (bool) $score->is_applicable,
                'issue_count' => $issuesByCategory->get($category)?->count() ?? 0,
            ];
        }

        return $results;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function issues(Audit $audit): array
    {
        return $audit->issues->map(fn (AuditIssue $issue) => [
            'category' => $issue->category->value,
            'severity' => $issue->severity->value,
            'code' => $issue->code,
            'message' => $issue->message,
            'recommendation' => $issue->recommendation,
            'page_url' => $issue->auditPage?->url,
            'meta' => $issue->meta,
        ])->values()->all();
    }

    /**
     * Only issues that actually carry a recommendation — never a message
     * reworded to look like advice.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recommendations(Audit $audit): array
    {
        return $audit->issues
            ->filter(fn (AuditIssue $i) => filled($i->recommendation))
            ->map(fn (AuditIssue $i) => [
                'category' => $i->category->value,
                'severity' => $i->severity->value,
                'code' => $i->code,
                'recommendation' => $i->recommendation,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pages(Audit $audit): array
    {
        return $audit->pages->map(fn (AuditPage $page) => [
            'url' => $page->url,
            'title' => $page->title,
            'http_status' => $page->http_status,
            'load_time_ms' => $page->load_time_ms,
            'html_size_bytes' => $page->html_size_bytes,
        ])->values()->all();
    }

    /**
     * Screenshot *paths*, plus the URL they resolved to at snapshot time.
     * The files themselves live on disk and can be pruned; recording both
     * means a snapshot whose images are gone still says what was captured.
     *
     * @return array<int, array<string, mixed>>
     */
    private function screenshots(Audit $audit): array
    {
        $disk = Storage::disk(config('crawler.screenshot_disk'));

        return Screenshot::where('audit_id', $audit->id)
            ->with('auditPage:id,url')
            ->get()
            ->map(fn (Screenshot $shot) => [
                'page_url' => $shot->auditPage?->url,
                'viewport' => $shot->viewport->value,
                'width' => $shot->width,
                'height' => $shot->height,
                'storage_path' => $shot->storage_path,
                'url' => $disk->url($shot->storage_path),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function performance(Audit $audit): array
    {
        return PagePerformanceMetric::where('audit_id', $audit->id)
            ->with('auditPage:id,url')
            ->get()
            ->map(fn (PagePerformanceMetric $metric) => [
                'page_url' => $metric->auditPage?->url,
                // ->value explicitly: these are enum-cast, and an enum
                // that reaches a stored array is one refactor away from
                // serializing differently than the day it was written.
                'source' => $metric->source?->value,
                'strategy' => $metric->strategy?->value,
                'score' => $metric->score,
                'fcp_ms' => $metric->fcp_ms,
                'ttfb_ms' => $metric->ttfb_ms,
                'lcp_ms' => $metric->lcp_ms,
                'cls' => $metric->cls,
                'tbt_ms' => $metric->tbt_ms,
                'speed_index_ms' => $metric->speed_index_ms,
                'total_requests' => $metric->total_requests,
                'total_transfer_bytes' => $metric->total_transfer_bytes,
                'third_party_requests' => $metric->third_party_requests,
                'third_party_bytes' => $metric->third_party_bytes,
                'dom_nodes' => $metric->dom_nodes,
                'by_type' => $metric->by_type,
            ])
            ->values()
            ->all();
    }

    /**
     * Which provider and model actually answered for this audit, read from
     * the `ai_analyses` rows rather than from current config — the config
     * says what would be used *now*, which is not what produced this
     * result.
     *
     * @return array{provider: string|null, model: string|null, calls: int, failed_calls: int}
     */
    private function aiProvenance(Audit $audit): array
    {
        $analyses = AiAnalysis::where('audit_id', $audit->id)->get();

        $succeeded = $analyses->filter(fn (AiAnalysis $a) => blank($a->error));

        return [
            'provider' => $succeeded->first()?->provider,
            // `ai_analyses` records the provider; the specific model comes
            // from the provider's configured model at call time, so it is
            // read back from there and may legitimately be null for an
            // audit where no AI category ran.
            'model' => $this->modelForProvider($succeeded->first()?->provider),
            'calls' => $analyses->count(),
            'failed_calls' => $analyses->count() - $succeeded->count(),
        ];
    }

    private function modelForProvider(?string $provider): ?string
    {
        if ($provider === null) {
            return null;
        }

        return ApiProvider::where('slug', $provider)->value('model');
    }

    private function categoryOf(AuditScore $score): string
    {
        return $score->category instanceof AuditCategory
            ? $score->category->value
            : (string) $score->category;
    }
}
