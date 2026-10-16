<?php

namespace App\Services\Audit;

use App\Contracts\AuditModule;
use App\DTOs\AuditIssueData;
use App\DTOs\AuditModuleResult;
use App\Enums\AuditCategory;
use App\Enums\IssueSeverity;
use App\Models\Audit;
use App\Services\AI\AiAnalysisService;

/**
 * "AI Search Readiness" — how clearly the site's content can be parsed
 * and understood by AI-driven search/answer systems. This score never
 * implies, and the prompt explicitly forbids claiming, that it predicts
 * or guarantees ranking/visibility in any specific AI search product.
 *
 * Since §42 this runs a *set* of focused prompts (ten defaults plus the
 * owner's own) rather than one broad one, each answered against the
 * business profile. A narrow question gets a specific answer; a sprawling
 * one gets a vague one, and a failure in one check no longer costs the
 * whole category.
 */
class AioAuditModule implements AuditModule
{
    public function __construct(
        private readonly AiAnalysisService $ai,
        private readonly AioPromptLibrary $prompts,
        private readonly AioContextBuilder $context,
    ) {}

    public function category(): AuditCategory
    {
        return AuditCategory::Aio;
    }

    public function run(Audit $audit): AuditModuleResult
    {
        $pageContexts = PageContextSummarizer::summarizePages($audit);
        $issues = $this->deterministicIssues($pageContexts);

        $website = $audit->website;
        $businessContext = $this->context->build($website);

        foreach ($this->prompts->for($website) as $prompt) {
            $response = $this->ai->analyze(
                $audit,
                $this->category(),
                $this->buildPrompt($prompt['question'], $businessContext !== []),
                [
                    'pages' => $pageContexts,
                    // Sent as its own key rather than interpolated into the
                    // prompt text: the analysis cache keys on the context
                    // hash, so a profile edit correctly invalidates the
                    // cached answer instead of silently reusing one that
                    // was reasoned from the old business description.
                    'business' => $businessContext,
                ],
            );

            if ($response === null) {
                // Budget spent or provider unavailable — AiAnalysisService
                // has already recorded why. Stop rather than firing the
                // remaining prompts at a provider that just refused.
                break;
            }

            $issues = array_merge(
                $issues,
                AiIssueParser::parse($response->content, "ai.aio.{$prompt['key']}"),
            );
        }

        [$score, $breakdown] = ScoreCalculator::deductPoints($issues);

        // Which context-dependent checks were skipped, and what would
        // unlock them. Recorded on the score so the report can say "3
        // checks need your business profile" rather than quietly running
        // seven prompts and presenting the result as a complete audit.
        $breakdown['context'] = $this->prompts->skippedFor($website);

        return new AuditModuleResult(category: $this->category(), score: $score, issues: $issues, breakdown: $breakdown);
    }

    /**
     * The checks that need no AI at all. Kept first and unconditional:
     * they still run when the AI budget is spent or no provider is
     * configured, so the category is never empty.
     *
     * @param  array<int, array<string, mixed>>  $pageContexts
     * @return AuditIssueData[]
     */
    private function deterministicIssues(array $pageContexts): array
    {
        $issues = [];

        if (collect($pageContexts)->every(fn ($p) => $p['structured_data_types'] === [])) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Medium,
                code: 'aio.no_structured_data',
                message: 'No structured data (schema.org/JSON-LD) found on any crawled page.',
                recommendation: 'Add structured data so machine readers can identify entities, facts, and page type unambiguously.',
            );
        }

        if (collect($pageContexts)->contains(fn ($p) => collect($p['headings'])->isEmpty())) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::High,
                code: 'aio.no_heading_structure',
                message: 'One or more pages have no heading structure at all.',
                recommendation: 'Use a clear heading hierarchy (H1 -> H2 -> H3) so content sections are unambiguous to machine parsers.',
            );
        }

        return $issues;
    }

    private function buildPrompt(string $question, bool $hasBusinessContext): string
    {
        $contextNote = $hasBusinessContext
            ? <<<'NOTE'

                The `business` object describes what the site's owner says the
                business is and what they want to be found for. Judge the pages
                against that stated intent. Where the pages do not support it,
                say so specifically — do not restate the owner's own claims back
                as findings, and do not assume a claim is true just because they
                made it.
                NOTE
            : <<<'NOTE'

                No business profile was provided, so judge the pages only on
                their own terms. Do not invent a purpose for the site.
                NOTE;

        return <<<PROMPT
            You are assessing how clearly a website's content can be parsed and
            understood by AI-driven search and answer systems (an "AI Search
            Readiness" audit). You are given a JSON object with `pages` (page
            summaries: title, headings, structured data types, form/image info —
            not raw HTML) and optionally `business`.
            {$contextNote}

            Answer this specific question about the site, and report only
            findings that bear on it:

            {$question}

            CRITICAL CONSTRAINT: You are evaluating structural clarity only.
            Do NOT state or imply that any finding, fix, or score predicts or
            guarantees ranking, citation, or visibility in ChatGPT, Google AI
            Overviews, Gemini, Perplexity, or any other AI search product. Do
            not use language like "this will get you cited by ChatGPT" or
            similar — only describe structural clarity for machine parsing.

            Respond with ONLY a JSON array, no prose, no markdown fences:
            [{"severity": "critical|high|medium|low", "code": "short_snake_case_id", "message": "specific finding", "recommendation": "specific fix"}]

            If you find no notable issues, respond with an empty array: []
            PROMPT;
    }
}
