<?php

namespace App\Services\Competitor;

use App\Enums\AuditCategory;
use App\Models\Audit;
use App\Models\WebsiteCompetitor;
use App\Services\AI\AiAnalysisService;
use App\Services\Crawler\PlaywrightCrawlerClient;
use App\Services\Crawler\UrlNormalizer;
use App\Services\Crawler\UrlSecurityValidator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Produces the comparison the spec asks for (§42) — competitor pages,
 * AIO score, relevant topics, visibility — by crawling a competitor's
 * site under a deliberately tight budget and scoring it the same way the
 * audited site is scored.
 *
 * ## Why the budget is small
 *
 * This crawls a third party who did not ask to be crawled. A handful of
 * pages is enough to judge structural clarity, and anything more starts
 * to look like scraping someone else's site on a customer's behalf. The
 * cap is not a performance compromise; it is the point.
 *
 * Every fetch goes through the same `UrlSecurityValidator` and
 * `PlaywrightCrawlerClient` as a normal audit, so a competitor domain
 * gets exactly the SSRF protection a user-submitted one does (§26) — a
 * model-suggested domain is user-influenced input and is treated as such.
 */
class CompetitorAnalysisService
{
    /** Pages fetched per competitor. Homepage plus a couple of internal pages. */
    private const MAX_PAGES = 3;

    public function __construct(
        private readonly PlaywrightCrawlerClient $crawler,
        private readonly UrlSecurityValidator $validator,
        private readonly UrlNormalizer $normalizer,
        private readonly AiAnalysisService $ai,
    ) {}

    /**
     * Analyze one competitor, recording the outcome on its row.
     *
     * Never throws: a competitor that cannot be reached records
     * `analysis_error` and leaves every metric null. Null means "not
     * analyzed", which must stay distinguishable from a genuine low
     * score — reporting an unreachable competitor as scoring zero would
     * be a straightforwardly false claim about a real company.
     */
    public function analyze(WebsiteCompetitor $competitor, Audit $audit): WebsiteCompetitor
    {
        try {
            $pages = $this->crawlPages($competitor);

            if ($pages === []) {
                return $this->recordFailure($competitor, 'No pages could be fetched from this competitor.');
            }

            $assessment = $this->assess($competitor, $pages, $audit);

            $competitor->update([
                'aio_score' => $assessment['aio_score'],
                'pages_analyzed' => count($pages),
                'topics' => $assessment['topics'],
                'visibility' => $assessment['visibility'],
                'analysis_error' => null,
                'analyzed_at' => now(),
            ]);

            return $competitor->fresh();
        } catch (\Throwable $e) {
            Log::channel('crawler')->info('Competitor analysis failed', [
                'competitor_id' => $competitor->id,
                'domain' => $competitor->normalized_domain,
                'error' => $e->getMessage(),
            ]);

            return $this->recordFailure($competitor, $e->getMessage());
        }
    }

    /**
     * @return array<int, array<string, mixed>> page summaries, same shape the AIO module reasons over
     */
    private function crawlPages(WebsiteCompetitor $competitor): array
    {
        $start = $this->normalizer->normalize($competitor->url ?: "https://{$competitor->normalized_domain}/");
        $this->validator->assertSafe($start);

        $homepage = $this->crawler->crawl($start);

        $pages = [[
            'url' => $homepage->url,
            'title' => $homepage->title,
            'meta_description' => $homepage->metaDescription,
            'headings' => collect($homepage->headings)->take(15)->map(fn ($h) => "H{$h['level']}: {$h['text']}")->all(),
            'structured_data_types' => collect($homepage->structuredData)->pluck('@type')->filter()->unique()->values()->all(),
        ]];

        // Same-domain internal links only, and only as many as the budget
        // allows. A competitor crawl must not wander onto third parties the
        // customer never asked about.
        $internal = collect($homepage->links)
            ->pluck('href')
            ->filter(fn ($href) => is_string($href) && $this->isSameDomain($href, $competitor->normalized_domain))
            ->unique()
            ->reject(fn ($href) => $this->normalizer->normalize($href) === $start)
            ->take(self::MAX_PAGES - 1);

        foreach ($internal as $href) {
            try {
                $url = $this->normalizer->normalize($href);
                $this->validator->assertSafe($url);
                $page = $this->crawler->crawl($url);

                $pages[] = [
                    'url' => $page->url,
                    'title' => $page->title,
                    'meta_description' => $page->metaDescription,
                    'headings' => collect($page->headings)->take(15)->map(fn ($h) => "H{$h['level']}: {$h['text']}")->all(),
                    'structured_data_types' => collect($page->structuredData)->pluck('@type')->filter()->unique()->values()->all(),
                ];
            } catch (\Throwable) {
                // One unreachable internal page is not a failed analysis —
                // the homepage alone is enough to say something useful.
                continue;
            }
        }

        return $pages;
    }

    private function isSameDomain(string $url, string $normalizedDomain): bool
    {
        try {
            return $this->normalizer->normalizedDomain($url) === $normalizedDomain;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @return array{aio_score: int|null, topics: array<int, string>, visibility: array<string, mixed>|null}
     */
    private function assess(WebsiteCompetitor $competitor, array $pages, Audit $audit): array
    {
        $response = $this->ai->analyze(
            $audit,
            AuditCategory::Aio,
            $this->buildPrompt(),
            ['competitor' => $competitor->displayName(), 'pages' => $pages],
        );

        if ($response === null) {
            // Crawled successfully but could not be scored — the page count
            // is still a real finding, so this is not a failure.
            return ['aio_score' => null, 'topics' => [], 'visibility' => null];
        }

        $decoded = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', trim($response->content))), true);

        if (! is_array($decoded)) {
            return ['aio_score' => null, 'topics' => [], 'visibility' => null];
        }

        $score = $decoded['aio_score'] ?? null;

        return [
            'aio_score' => is_numeric($score) ? max(0, min(100, (int) $score)) : null,
            'topics' => collect($decoded['topics'] ?? [])
                ->filter(fn ($t) => is_string($t) && trim($t) !== '')
                ->map(fn ($t) => Str::limit(trim($t), 80))
                ->take(10)
                ->values()
                ->all(),
            'visibility' => is_array($decoded['visibility'] ?? null)
                ? [
                    'structured_data' => (bool) ($decoded['visibility']['structured_data'] ?? false),
                    'clear_headings' => (bool) ($decoded['visibility']['clear_headings'] ?? false),
                    'descriptive_titles' => (bool) ($decoded['visibility']['descriptive_titles'] ?? false),
                    'summary' => Str::limit((string) ($decoded['visibility']['summary'] ?? ''), 300) ?: null,
                ]
                : null,
        ];
    }

    private function recordFailure(WebsiteCompetitor $competitor, string $message): WebsiteCompetitor
    {
        $competitor->update([
            'analysis_error' => Str::limit($message, 250),
            'analyzed_at' => now(),
            // Explicitly cleared, not left stale: a failed re-analysis must
            // not leave last week's score looking like today's.
            'aio_score' => null,
            'pages_analyzed' => null,
            'topics' => null,
            'visibility' => null,
        ]);

        return $competitor->fresh();
    }

    private function buildPrompt(): string
    {
        return <<<'PROMPT'
            You are assessing how clearly a competitor website's content can be
            parsed and understood by AI-driven search and answer systems, so it
            can be compared against another site audited the same way.

            You are given a JSON object with the competitor's name and a small
            sample of their page summaries (title, meta description, headings,
            structured data types — not raw HTML). It is a *sample*: judge only
            what is present, and do not extrapolate to pages you were not shown.

            CRITICAL CONSTRAINT: assess structural clarity for machine parsing
            only. Do NOT state or imply that this predicts or guarantees
            ranking, citation, or visibility in ChatGPT, Google AI Overviews,
            Gemini, Perplexity, or any other AI search product. "visibility"
            below means how legible the content is to a machine reader, nothing
            more.

            Respond with ONLY a JSON object, no prose, no markdown fences:
            {
              "aio_score": 0-100,
              "topics": ["the subjects this site clearly covers"],
              "visibility": {
                "structured_data": true|false,
                "clear_headings": true|false,
                "descriptive_titles": true|false,
                "summary": "one sentence on how legible this content is to a machine reader"
              }
            }
            PROMPT;
    }
}
