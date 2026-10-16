<?php

namespace App\Services\Report;

use App\Enums\AuditCategory;
use App\Enums\AuditStatus;
use App\Enums\IssueSeverity;
use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\AuditScore;
use Illuminate\Support\Collection;

/**
 * What changed between two audits of the same site (§52).
 *
 * The useful output is small — "Performance improved from 48 to 72" — and
 * the work is almost entirely in refusing to say that when it is not true.
 *
 * Three ways a naive diff lies, all handled here:
 *
 * 1. **Different page coverage.** If the earlier audit crawled 25 pages
 *    and the later one 5, every finding on the 20 pages nobody looked at
 *    disappears. Reporting those as "resolved" is the worst number this
 *    product could produce: "12 issues fixed" is exactly what someone
 *    forwards to their manager.
 * 2. **A service that did not run.** Not Applicable is not a score.
 *    An ecommerce assessment that stopped running because the site was
 *    reclassified (§46) has not "declined to zero".
 * 3. **Partial confidence.** A service whose AI provider was down scored
 *    on its deterministic checks alone (§35). Comparing that against a
 *    full run compares two different measurements.
 *
 * Every figure therefore travels with whether it is comparable, and why
 * not when it isn't.
 */
class AuditComparison
{
    /** The services the comparison reports on, in display order. */
    private const CATEGORIES = [
        AuditCategory::Overall,
        AuditCategory::Performance,
        AuditCategory::Seo,
        AuditCategory::Accessibility,
        AuditCategory::Conversion,
        AuditCategory::LandingPage,
        AuditCategory::Aio,
        AuditCategory::Ecommerce,
        AuditCategory::ProductAuditor,
    ];

    public function __construct(private readonly IssueTaxonomy $taxonomy) {}

    /**
     * @return array<string, mixed>
     */
    public function compare(Audit $previous, Audit $current): array
    {
        // `issues.auditPage` and not just `issues`: urlsFor() falls back
        // to the issue's page relation when the finding carries no URL in
        // its meta, and without it that is one query per issue — hundreds
        // on a large two-audit comparison.
        $previous->loadMissing(['scores', 'issues.auditPage', 'pages']);
        $current->loadMissing(['scores', 'issues.auditPage', 'pages']);

        $coverage = $this->coverage($previous, $current);

        return [
            'previous' => $this->summary($previous),
            'current' => $this->summary($current),
            'coverage' => $coverage,
            'scores' => $this->scoreDeltas($previous, $current),
            'issues' => $this->issueDeltas($previous, $current, $coverage),
            'insights' => $this->insights($previous, $current, $coverage),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Audit $audit): array
    {
        return [
            'audit_id' => $audit->id,
            'date' => ($audit->completed_at ?? $audit->created_at)?->toIso8601String(),
            'pages_analyzed' => $audit->pages_crawled ?? 0,
            'critical_issues' => $this->countBySeverity($audit, IssueSeverity::Critical),
            'high_issues' => $this->countBySeverity($audit, IssueSeverity::High),
            'total_issues' => $audit->issues->count(),
        ];
    }

    /**
     * Whether the two runs looked at enough of the same site to be
     * compared at all.
     *
     * The URLs matter, not just the counts: two 10-page crawls that
     * happened to reach different sets of pages are no more comparable
     * than a 25-page and a 5-page one.
     *
     * @return array<string, mixed>
     */
    private function coverage(Audit $previous, Audit $current): array
    {
        $previousUrls = $previous->pages->pluck('url')->map($this->normalize(...))->unique();
        $currentUrls = $current->pages->pluck('url')->map($this->normalize(...))->unique();

        $shared = $previousUrls->intersect($currentUrls);
        $dropped = $previousUrls->diff($currentUrls);
        $added = $currentUrls->diff($previousUrls);

        // Both crawls reaching exactly the same pages is the only case
        // where the two issue lists can be read against each other
        // directly. Dropped pages inflate "resolved"; added pages inflate
        // "new" just as badly, so neither counts as like-for-like.
        $isComparable = $dropped->isEmpty() && $added->isEmpty() && $previousUrls->isNotEmpty();

        return [
            'previous_pages' => $previousUrls->count(),
            'current_pages' => $currentUrls->count(),
            'shared_pages' => $shared->count(),
            'pages_not_recrawled' => $dropped->count(),
            'pages_added' => $added->count(),
            'is_like_for_like' => $isComparable,
            'note' => $isComparable
                ? null
                : $this->coverageNote($dropped->count(), $added->count()),
            // Held for the issue diff, which needs to know which findings
            // were simply not looked for again.
            'not_recrawled_urls' => $dropped->values()->all(),
        ];
    }

    private function coverageNote(int $dropped, int $added): string
    {
        if ($dropped === 0) {
            return "This audit covered {$added} page(s) the previous one did not, so some findings are new to the comparison rather than new to the site.";
        }

        return "{$dropped} page(s) from the previous audit were not crawled again, so findings on them are reported as not re-checked rather than resolved.";
    }

    /**
     * Per-service score movement.
     *
     * @return array<int, array<string, mixed>>
     */
    private function scoreDeltas(Audit $previous, Audit $current): array
    {
        $deltas = [];

        foreach (self::CATEGORIES as $category) {
            $before = $previous->scores->firstWhere('category', $category);
            $after = $current->scores->firstWhere('category', $category);

            // Neither run produced this service at all — it is not part of
            // this site's story and does not belong in the table.
            if ($before === null && $after === null) {
                continue;
            }

            $deltas[] = $this->scoreDelta($category, $before, $after);
        }

        return $deltas;
    }

    /**
     * @return array<string, mixed>
     */
    private function scoreDelta(AuditCategory $category, ?AuditScore $before, ?AuditScore $after): array
    {
        $beforeScore = $this->comparableScore($before);
        $afterScore = $this->comparableScore($after);

        $row = [
            'category' => $category->value,
            'label' => $this->label($category),
            'previous_score' => $beforeScore,
            'current_score' => $afterScore,
            'previous_applicable' => $before?->is_applicable ?? null,
            'current_applicable' => $after?->is_applicable ?? null,
            'delta' => null,
            'direction' => 'unchanged',
            'is_comparable' => false,
            'reason' => null,
        ];

        if ($beforeScore === null || $afterScore === null) {
            // The one case worth naming precisely, because it is the one
            // a reader would otherwise misread as a collapse.
            $row['reason'] = $this->incomparableReason($before, $after);

            return $row;
        }

        $delta = $afterScore - $beforeScore;

        return array_merge($row, [
            'delta' => $delta,
            'direction' => $delta > 0 ? 'improved' : ($delta < 0 ? 'declined' : 'unchanged'),
            'is_comparable' => true,
        ]);
    }

    private function comparableScore(?AuditScore $score): ?int
    {
        // A Not Applicable service has no score, and a null score is not a
        // zero. Either way there is nothing to subtract.
        return $score !== null && $score->is_applicable ? $score->score : null;
    }

    private function incomparableReason(?AuditScore $before, ?AuditScore $after): string
    {
        $ranBefore = $before !== null && $before->is_applicable;
        $ranAfter = $after !== null && $after->is_applicable;

        if ($ranBefore && ! $ranAfter) {
            return $this->missingScoreReason($after, 'the later audit');
        }

        if (! $ranBefore && $ranAfter) {
            return $this->missingScoreReason($before, 'the earlier audit');
        }

        // Neither side scored. The current run's own explanation is the
        // one worth showing, falling back to the earlier run's.
        return $this->missingScoreReason($after ?? $before, 'either audit');
    }

    /**
     * Why a run has no comparable score for a service.
     *
     * Three different things were previously reported as one, and the
     * wrong one was a claim about the *site*: an absent score row means
     * the service never ran (an older audit, a plan without it, a module
     * that was not registered yet), which is not the same as the auditor
     * having looked and found the service inapplicable. Saying "this did
     * not apply to the site" about a service nobody ran states a finding
     * that was never made.
     */
    private function missingScoreReason(?AuditScore $score, string $which): string
    {
        if ($score === null) {
            return "This service did not run in {$which}, so there is nothing to compare.";
        }

        // What §46 recorded at the time, in the auditor's own words.
        if (filled($score->not_applicable_reason)) {
            return $score->not_applicable_reason;
        }

        // An unassessable category (§35) — the module threw and the score
        // was written off. That is a failed measurement, not a property
        // of the site.
        if (filled(data_get($score->breakdown, 'error'))) {
            return "This service could not be assessed in {$which}, so its result is not comparable.";
        }

        return "This service did not apply to the site in {$which}, so the two runs cannot be compared.";
    }

    /**
     * Which findings are new, which are gone, and which of the "gone"
     * were merely not looked for.
     *
     * Keyed by issue code, because that is what identifies a *kind* of
     * problem. The same code on a different page is the same finding
     * recurring, and treating each page as its own issue would report a
     * site-wide problem as dozens of separate fixes.
     *
     * @param  array<string, mixed>  $coverage
     * @return array<string, mixed>
     */
    private function issueDeltas(Audit $previous, Audit $current, array $coverage): array
    {
        $before = $this->issuesByCode($previous);
        $after = $this->issuesByCode($current);

        $notRecrawled = collect($coverage['not_recrawled_urls']);

        $resolved = [];
        $notRechecked = [];

        foreach ($before as $code => $issue) {
            if (isset($after[$code])) {
                continue;
            }

            // The judgement this whole class exists for: a finding whose
            // pages were not crawled again has not been fixed — nobody
            // looked. Only a finding on a page that *was* re-crawled can
            // honestly be called resolved.
            $entry = [
                'code' => $code,
                'severity' => $issue['severity'],
                'category' => $issue['category'],
                'message' => $issue['message'],
            ];

            if ($this->onlyOnPages($issue['urls'], $notRecrawled)) {
                $notRechecked[] = $entry + ['reason' => 'The page(s) this was found on were not crawled in the later audit.'];

                continue;
            }

            $resolved[] = $entry;
        }

        $new = [];

        foreach ($after as $code => $issue) {
            if (! isset($before[$code])) {
                $new[] = [
                    'code' => $code,
                    'severity' => $issue['severity'],
                    'category' => $issue['category'],
                    'message' => $issue['message'],
                ];
            }
        }

        return [
            'new' => $this->worstFirst($new),
            'resolved' => $this->worstFirst($resolved),
            'not_rechecked' => $this->worstFirst($notRechecked),
            'persisting' => count(array_intersect_key($before, $after)),
            'severity_deltas' => $this->severityDeltas($previous, $current),
        ];
    }

    /**
     * Every URL this finding was attached to, normalized.
     *
     * A site-wide finding has none, and must never be counted as "not
     * re-checked" — it was not tied to any page that could go missing.
     *
     * @param  Collection<int, string>  $urls
     * @param  Collection<int, string>  $notRecrawled
     */
    private function onlyOnPages(Collection $urls, Collection $notRecrawled): bool
    {
        return $urls->isNotEmpty() && $urls->diff($notRecrawled)->isEmpty();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function issuesByCode(Audit $audit): array
    {
        $byCode = [];

        foreach ($audit->issues as $issue) {
            $code = $issue->code;

            $byCode[$code] ??= [
                'severity' => $issue->severity->value,
                'category' => $this->taxonomy->serviceFor($issue->category) ?? 'other',
                'message' => $issue->message,
                'urls' => collect(),
            ];

            foreach ($this->urlsFor($issue) as $url) {
                $byCode[$code]['urls']->push($this->normalize($url));
            }
        }

        foreach ($byCode as $code => $data) {
            $byCode[$code]['urls'] = $data['urls']->unique()->values();
        }

        return $byCode;
    }

    /**
     * @return string[]
     */
    private function urlsFor(AuditIssue $issue): array
    {
        $meta = $issue->meta ?? [];

        if (! empty($meta['affected_pages']) && is_array($meta['affected_pages'])) {
            return $meta['affected_pages'];
        }

        if (! empty($meta['url'])) {
            return [$meta['url']];
        }

        return $issue->auditPage !== null ? [$issue->auditPage->url] : [];
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function severityDeltas(Audit $previous, Audit $current): array
    {
        $deltas = [];

        foreach ([IssueSeverity::Critical, IssueSeverity::High, IssueSeverity::Medium, IssueSeverity::Low] as $severity) {
            $before = $this->countBySeverity($previous, $severity);
            $after = $this->countBySeverity($current, $severity);

            $deltas[$severity->value] = [
                'previous' => $before,
                'current' => $after,
                'delta' => $after - $before,
            ];
        }

        return $deltas;
    }

    private function countBySeverity(Audit $audit, IssueSeverity $severity): int
    {
        return $audit->issues->where('severity', $severity)->count();
    }

    /**
     * The sentences a reader actually takes away.
     *
     * Only genuinely comparable movements produce one. A service that
     * stopped applying gets a note explaining that, never a delta.
     *
     * @param  array<string, mixed>  $coverage
     * @return array<int, array<string, mixed>>
     */
    private function insights(Audit $previous, Audit $current, array $coverage): array
    {
        $insights = [];

        foreach ($this->scoreDeltas($previous, $current) as $delta) {
            if (! $delta['is_comparable'] || $delta['delta'] === 0) {
                continue;
            }

            $verb = $delta['delta'] > 0 ? 'improved' : 'declined';
            $sign = $delta['delta'] > 0 ? '+' : '';

            $insights[] = [
                'type' => $delta['delta'] > 0 ? 'improved' : 'declined',
                'category' => $delta['category'],
                'text' => "{$delta['label']} {$verb} from {$delta['previous_score']} to {$delta['current_score']} ({$sign}{$delta['delta']} points).",
                'magnitude' => abs($delta['delta']),
            ];
        }

        // Biggest movement first — the one worth acting on.
        usort($insights, fn (array $a, array $b) => $b['magnitude'] <=> $a['magnitude']);

        if (! $coverage['is_like_for_like'] && $coverage['note'] !== null) {
            // Prepended, not appended: a caveat that changes how every
            // other line should be read belongs before them.
            array_unshift($insights, [
                'type' => 'caveat',
                'category' => null,
                'text' => $coverage['note'],
                'magnitude' => 0,
            ]);
        }

        return $insights;
    }

    /**
     * @param  array<int, array<string, mixed>>  $issues
     * @return array<int, array<string, mixed>>
     */
    private function worstFirst(array $issues): array
    {
        $order = array_flip(['critical', 'high', 'medium', 'low', 'passed']);

        usort($issues, fn (array $a, array $b) => ($order[$a['severity']] ?? 99) <=> ($order[$b['severity']] ?? 99));

        return $issues;
    }

    /**
     * Two categories need their own name here.
     *
     * `Overall` maps to no service at all, so the taxonomy handed back
     * the raw enum value and the headline sentence read "overall declined
     * from 64 to 53" — lowercase, mid-sentence. `LandingPage` shares the
     * CRO service with `Conversion`, so both rows came out labelled
     * identically.
     */
    private const CATEGORY_LABELS = [
        'overall' => 'Overall score',
        'landing_page' => 'Landing Page',
    ];

    private function label(AuditCategory $category): string
    {
        return self::CATEGORY_LABELS[$category->value]
            ?? $this->taxonomy->serviceName($this->taxonomy->serviceFor($category) ?? $category->value);
    }

    /**
     * Trailing slash and fragment dropped, host case-folded — the same
     * shape the link graph uses (§44), so "/about" and "/about/" are one
     * page here too.
     */
    private function normalize(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return rtrim(strtolower($url), '/');
        }

        $host = strtolower($parts['host']);
        $path = rtrim($parts['path'] ?? '', '/');
        $query = isset($parts['query']) ? "?{$parts['query']}" : '';

        return "{$host}{$path}{$query}";
    }

    /**
     * The audit this one should be compared against: the previous
     * *completed* run of the same website.
     *
     * Failed and cancelled runs are skipped — they have no scores, so a
     * comparison against one would report every service as incomparable
     * and every finding as new.
     */
    public function previousFor(Audit $audit): ?Audit
    {
        return Audit::where('website_id', $audit->website_id)
            ->where('id', '<', $audit->id)
            ->where('status', AuditStatus::Completed->value)
            ->orderByDesc('id')
            ->first();
    }
}
