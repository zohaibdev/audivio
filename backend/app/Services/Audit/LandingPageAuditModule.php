<?php

namespace App\Services\Audit;

use App\Contracts\AuditModule;
use App\DTOs\AuditIssueData;
use App\DTOs\AuditModuleResult;
use App\Enums\AuditCategory;
use App\Enums\IssueSeverity;
use App\Models\Audit;
use App\Services\AI\AiAnalysisService;

class LandingPageAuditModule implements AuditModule
{
    public function __construct(
        private readonly AiAnalysisService $ai,
    ) {}

    public function category(): AuditCategory
    {
        return AuditCategory::LandingPage;
    }

    public function run(Audit $audit): AuditModuleResult
    {
        $pageContexts = PageContextSummarizer::summarizePages($audit);
        $issues = [];

        $totalCtas = collect($pageContexts)->sum(fn ($p) => count($p['cta_texts']));
        if ($totalCtas === 0) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::High,
                code: 'landing_page.no_cta',
                message: 'No call-to-action detected across crawled pages.',
                recommendation: 'Add a clear, single primary call-to-action above the fold.',
            );
        }

        $noHeadline = collect($pageContexts)->contains(fn ($p) => collect($p['headings'])->isEmpty());
        if ($noHeadline) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Medium,
                code: 'landing_page.no_headline',
                message: 'One or more pages have no heading content at all.',
                recommendation: 'Lead with a clear headline stating the value proposition.',
            );
        }

        $aiResponse = $this->ai->analyze(
            $audit,
            $this->category(),
            $this->buildPrompt(),
            ['pages' => $pageContexts],
        );

        if ($aiResponse !== null) {
            $issues = array_merge($issues, AiIssueParser::parse($aiResponse->content, 'ai.landing_page'));
        }

        [$score, $breakdown] = ScoreCalculator::deductPoints($issues);

        return new AuditModuleResult(category: $this->category(), score: $score, issues: $issues, breakdown: $breakdown);
    }

    private function buildPrompt(): string
    {
        return <<<'PROMPT'
            You are auditing a website's landing pages for clarity and focus.
            You are given a JSON array of page summaries (title, headings, CTA
            button text, form info, image counts) — not raw HTML.

            Assess value proposition clarity (is it obvious from the headline
            and headings what this page offers?), focus (does the page have one
            clear goal, or is it diluted by too many competing CTAs/links?), and
            whether the page structure supports a visitor deciding quickly.

            Respond with ONLY a JSON array, no prose, no markdown fences:
            [{"severity": "critical|high|medium|low", "code": "short_snake_case_id", "message": "specific finding", "recommendation": "specific fix"}]

            If you find no notable issues, respond with an empty array: []
            PROMPT;
    }
}
