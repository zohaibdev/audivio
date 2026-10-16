<?php

namespace App\Services\Competitor;

use App\Enums\AuditCategory;
use App\Enums\CompetitorSource;
use App\Enums\PlanFeature;
use App\Models\Audit;
use App\Models\Website;
use App\Models\WebsiteCompetitor;
use App\Services\AI\AiAnalysisService;
use App\Services\Audit\AioContextBuilder;
use App\Services\Crawler\SsrfValidationException;
use App\Services\Crawler\UrlNormalizer;
use App\Services\Plan\EntitlementResolver;
use Illuminate\Support\Facades\Log;

/**
 * Finds likely competitors for a website whose owner did not name any
 * (§42), for plans that include discovery.
 *
 * Discovery only ever *adds* rows with `source = discovered`. A competitor
 * the owner named themselves is never overwritten, re-ranked, or removed
 * by a discovery run — what they told us outranks what a model guessed.
 */
class CompetitorDiscoveryService
{
    /** Kept small on purpose: each discovered competitor becomes a crawl. */
    private const MAX_DISCOVERED = 5;

    public function __construct(
        private readonly AiAnalysisService $ai,
        private readonly AioContextBuilder $context,
        private readonly EntitlementResolver $entitlements,
        private readonly UrlNormalizer $normalizer,
    ) {}

    /**
     * Whether discovery should run for this website at all.
     *
     * Three conditions, and the middle one is the point: discovery exists
     * to fill a gap, not to second-guess the owner. If they named
     * competitors, theirs are the ones we analyze.
     */
    public function shouldDiscoverFor(Website $website): bool
    {
        if (! $this->entitlements->forOrDeny($website->user)->allows(PlanFeature::CompetitorDiscovery)) {
            return false;
        }

        if ($this->hasOwnerNamedCompetitors($website)) {
            return false;
        }

        // Nothing to reason from — a model asked to guess competitors for
        // an unknown business in an unknown market will produce plausible
        // names with no basis, which is worse than no answer.
        return $this->context->build($website) !== [];
    }

    /**
     * @return WebsiteCompetitor[] the rows created or refreshed
     */
    public function discover(Website $website, Audit $audit): array
    {
        if (! $this->shouldDiscoverFor($website)) {
            return [];
        }

        $response = $this->ai->analyze(
            $audit,
            AuditCategory::Aio,
            $this->buildPrompt(),
            ['business' => $this->context->build($website), 'own_domain' => $website->normalized_domain],
        );

        if ($response === null) {
            return [];
        }

        return $this->persist($website, $this->parse($response->content, $website));
    }

    private function hasOwnerNamedCompetitors(Website $website): bool
    {
        $declared = collect($website->competitors ?? [])
            ->filter(fn ($c) => is_string($c) && trim($c) !== '');

        if ($declared->isNotEmpty()) {
            return true;
        }

        return $website->competitorRecords()
            ->where('source', CompetitorSource::User->value)
            ->exists();
    }

    /**
     * @return array<int, array{name: string, domain: string, reason: string|null}>
     */
    private function parse(string $content, Website $website): array
    {
        $decoded = json_decode($this->stripFences($content), true);

        if (! is_array($decoded)) {
            Log::channel('ai')->warning('Competitor discovery returned unparseable output', [
                'website_id' => $website->id,
            ]);

            return [];
        }

        $out = [];

        foreach ($decoded as $entry) {
            if (! is_array($entry) || blank($entry['domain'] ?? null)) {
                continue;
            }

            try {
                // Through the same normalizer everything else uses, so a
                // hallucinated "domain" that is not a domain is rejected
                // here rather than becoming a row that later fails to crawl.
                $domain = $this->normalizer->normalizedDomain((string) $entry['domain']);
            } catch (SsrfValidationException) {
                continue;
            }

            // A model asked for competitors will sometimes return the site
            // itself. It is not its own competitor.
            if ($domain === $website->normalized_domain) {
                continue;
            }

            $out[$domain] = [
                'name' => (string) ($entry['name'] ?? $domain),
                'domain' => $domain,
                'reason' => filled($entry['reason'] ?? null) ? (string) $entry['reason'] : null,
            ];

            if (count($out) >= self::MAX_DISCOVERED) {
                break;
            }
        }

        return array_values($out);
    }

    /**
     * @param  array<int, array{name: string, domain: string, reason: string|null}>  $candidates
     * @return WebsiteCompetitor[]
     */
    private function persist(Website $website, array $candidates): array
    {
        $saved = [];

        foreach ($candidates as $candidate) {
            $existing = $website->competitorRecords()
                ->where('normalized_domain', $candidate['domain'])
                ->first();

            // Already there as an owner-named competitor: leave it exactly
            // as it is. Re-labelling it "discovered" would misattribute the
            // owner's own input to the model.
            if ($existing !== null && $existing->source === CompetitorSource::User) {
                continue;
            }

            $saved[] = WebsiteCompetitor::updateOrCreate(
                ['website_id' => $website->id, 'normalized_domain' => $candidate['domain']],
                [
                    'name' => $candidate['name'],
                    'url' => "https://{$candidate['domain']}/",
                    'source' => CompetitorSource::Discovered,
                    'reason' => $candidate['reason'],
                ],
            );
        }

        return $saved;
    }

    private function stripFences(string $content): string
    {
        return trim(preg_replace('/^```(?:json)?|```$/m', '', trim($content)));
    }

    private function buildPrompt(): string
    {
        return <<<'PROMPT'
            You are identifying likely commercial competitors for a business,
            given a JSON object describing it (`business`) and its own domain
            (`own_domain`).

            Return businesses that a prospective customer of this business would
            realistically also consider — same offering, same rough market and
            country. Prefer specific, verifiable companies over categories or
            directories. Do not include the business's own domain. Do not
            include marketplaces, review sites, or aggregators unless the
            business itself is one.

            If you are not reasonably confident a company exists and competes
            with this business, leave it out. A short accurate list is far more
            useful than a long speculative one — every name returned will be
            crawled and reported to the owner as a competitor.

            Respond with ONLY a JSON array, no prose, no markdown fences:
            [{"name": "Company name", "domain": "example.com", "reason": "one sentence on why they compete"}]

            If you cannot identify any with reasonable confidence, respond with
            an empty array: []
            PROMPT;
    }
}
