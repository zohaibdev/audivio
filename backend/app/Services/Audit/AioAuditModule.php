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
 */
class AioAuditModule implements AuditModule
{
    public function __construct(
        private readonly AiAnalysisService $ai,
    ) {
    }

    public function category(): AuditCategory
    {
        return AuditCategory::Aio;
    }

    public function run(Audit $audit): AuditModuleResult
    {
        $pageContexts = PageContextSummarizer::summarizePages($audit);
        $issues = [];

        $noStructuredData = collect($pageContexts)->every(fn ($p) => $p['structured_data_types'] === []);
        if ($noStructuredData) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Medium,
                code: 'aio.no_structured_data',
                message: 'No structured data (schema.org/JSON-LD) found on any crawled page.',
                recommendation: 'Add structured data so machine readers can identify entities, facts, and page type unambiguously.',
            );
        }

        $noHeadings = collect($pageContexts)->contains(fn ($p) => collect($p['headings'])->isEmpty());
        if ($noHeadings) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::High,
                code: 'aio.no_heading_structure',
                message: 'One or more pages have no heading structure at all.',
                recommendation: 'Use a clear heading hierarchy (H1 -> H2 -> H3) so content sections are unambiguous to machine parsers.',
            );
        }

        $aiResponse = $this->ai->analyze(
            $audit,
            $this->category(),
            $this->buildPrompt(),
            ['pages' => $pageContexts],
        );

        if ($aiResponse !== null) {
            $issues = array_merge($issues, AiIssueParser::parse($aiResponse->content, 'ai.aio'));
        }

        [$score, $breakdown] = ScoreCalculator::deductPoints($issues);

        return new AuditModuleResult(category: $this->category(), score: $score, issues: $issues, breakdown: $breakdown);
    }

    private function buildPrompt(): string
    {
        return <<<'PROMPT'
            You are assessing how clearly a website's content can be parsed and
            understood by AI-driven search and answer systems (an "AI Search
            Readiness" audit). You are given a JSON array of page summaries
            (title, headings, structured data types, form/image info) — not raw
            HTML.

            Evaluate: whether headings state clear, direct topics; whether
            structured data disambiguates entities/facts; whether page titles
            and meta descriptions clearly summarize the content; whether content
            appears organized into clearly delineated, self-contained sections
            an extractor could pull independently.

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
