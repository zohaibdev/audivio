<?php

namespace App\Jobs\Competitor;

use App\Enums\CompetitorSource;
use App\Enums\PlanFeature;
use App\Models\Audit;
use App\Models\WebsiteCompetitor;
use App\Services\Audit\AuditLifecycle;
use App\Services\Competitor\CompetitorAnalysisService;
use App\Services\Competitor\CompetitorDiscoveryService;
use App\Services\Crawler\SsrfValidationException;
use App\Services\Crawler\UrlNormalizer;
use App\Services\Plan\EntitlementResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Competitor intelligence for one audit (§42): sync what the owner
 * declared, discover more if their plan allows and they named none, then
 * analyze each.
 *
 * Runs **after** the audit completes, as its own job, and deliberately
 * outside the audit's own lifecycle: competitor work involves crawling
 * third-party sites and is the slowest, most failure-prone part of the
 * product. A competitor being unreachable must never turn a finished
 * audit of the customer's *own* site into a failed one.
 */
class AnalyzeCompetitorsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly Audit $audit) {}

    public function middleware(): array
    {
        // Shares the crawler's rate limiter: a competitor fetch is a crawl
        // like any other and must not let one audit burst past it.
        return [new RateLimited('crawler')];
    }

    public function handle(
        CompetitorDiscoveryService $discovery,
        CompetitorAnalysisService $analysis,
        EntitlementResolver $entitlements,
        UrlNormalizer $normalizer,
        AuditLifecycle $lifecycle,
    ): void {
        $website = $this->audit->website;

        if ($website === null) {
            return;
        }

        // A cancelled audit stops this too — the customer is no longer
        // waiting for the result, so third-party sites should not be
        // crawled on their behalf.
        if ($lifecycle->shouldStop($this->audit) && $this->audit->fresh()?->status->value !== 'completed') {
            return;
        }

        $this->syncDeclaredCompetitors($website, $normalizer);

        if ($entitlements->forOrDeny($website->user)->allows(PlanFeature::CompetitorDiscovery)) {
            $discovery->discover($website, $this->audit);
        }

        foreach ($website->competitorRecords()->get() as $competitor) {
            $analysis->analyze($competitor, $this->audit);
        }
    }

    /**
     * Turn the owner's declared `competitors` strings into analyzable
     * rows.
     *
     * The JSON column is free text — a name, a URL, or a bare domain — so
     * anything that cannot be reduced to a domain is skipped rather than
     * stored as an un-crawlable row. It stays in the JSON either way, so
     * nothing the owner typed is lost; it simply is not something we can
     * go and look at.
     */
    private function syncDeclaredCompetitors($website, UrlNormalizer $normalizer): void
    {
        foreach ($website->competitors ?? [] as $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                continue;
            }

            try {
                $domain = $normalizer->normalizedDomain($entry);
            } catch (SsrfValidationException) {
                Log::channel('crawler')->info('Declared competitor is not a resolvable domain; skipped', [
                    'website_id' => $website->id,
                    'entry' => $entry,
                ]);

                continue;
            }

            if ($domain === $website->normalized_domain) {
                continue;
            }

            WebsiteCompetitor::updateOrCreate(
                ['website_id' => $website->id, 'normalized_domain' => $domain],
                [
                    'name' => $domain,
                    'url' => "https://{$domain}/",
                    // Always upgrades a previously-discovered row to
                    // owner-declared: once they name it themselves, it is
                    // theirs, and discovery must stop touching it.
                    'source' => CompetitorSource::User,
                ],
            );
        }
    }

    public function failed(?\Throwable $e): void
    {
        // Nothing to fail: the audit itself already completed. Logged so a
        // systematically broken competitor pipeline is visible rather than
        // silently producing empty comparisons.
        Log::channel('crawler')->warning('Competitor analysis job failed', [
            'audit_id' => $this->audit->id,
            'error' => $e?->getMessage(),
        ]);
    }
}
