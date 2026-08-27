<?php

namespace App\Services\Audit;

use App\Contracts\AuditModule;
use App\DTOs\AuditIssueData;
use App\DTOs\AuditModuleResult;
use App\Enums\AuditCategory;
use App\Enums\IssueSeverity;
use App\Models\Audit;
use App\Models\AuditPage;
use App\Services\AI\AiAnalysisService;

class ConversionAuditModule implements AuditModule
{
    private const MAX_REQUIRED_FIELDS_BEFORE_FRICTION = 6;

    public function __construct(
        private readonly AiAnalysisService $ai,
    ) {
    }

    public function category(): AuditCategory
    {
        return AuditCategory::Conversion;
    }

    public function run(Audit $audit): AuditModuleResult
    {
        $pageContexts = PageContextSummarizer::summarizePages($audit);
        $issues = [];

        foreach ($audit->pages as $page) {
            $issues = array_merge($issues, $this->checkFormFriction($page));
        }

        $aiResponse = $this->ai->analyze(
            $audit,
            $this->category(),
            $this->buildPrompt(),
            ['pages' => $pageContexts],
        );

        if ($aiResponse !== null) {
            $issues = array_merge($issues, AiIssueParser::parse($aiResponse->content, 'ai.conversion'));
        }

        [$score, $breakdown] = ScoreCalculator::deductPoints($issues);

        return new AuditModuleResult(category: $this->category(), score: $score, issues: $issues, breakdown: $breakdown);
    }

    /** @return AuditIssueData[] */
    private function checkFormFriction(AuditPage $page): array
    {
        $issues = [];

        foreach ($page->forms ?? [] as $form) {
            $requiredCount = collect($form['fields'] ?? [])->where('required', true)->count();

            if ($requiredCount > self::MAX_REQUIRED_FIELDS_BEFORE_FRICTION) {
                $issues[] = new AuditIssueData(
                    severity: IssueSeverity::Medium,
                    code: 'conversion.form_friction',
                    message: "Form with {$requiredCount} required fields may cause drop-off: {$page->url}",
                    recommendation: 'Reduce required fields to only what is essential, or split into multiple steps.',
                    auditPageId: $page->id,
                    meta: ['url' => $page->url, 'required_fields' => $requiredCount],
                );
            }
        }

        return $issues;
    }

    private function buildPrompt(): string
    {
        return <<<'PROMPT'
            You are auditing a website's conversion rate optimization (CRO).
            You are given a JSON array of page summaries (title, headings, CTA
            button text, form field info, structured data types) — not raw HTML.

            Identify friction points and missed conversion opportunities:
            competing or unclear CTAs, absence of urgency/trust signals inferred
            only from the given text (do not invent claims), pages with forms
            but no visible CTA nearby, or an unclear next step for the visitor.

            Respond with ONLY a JSON array, no prose, no markdown fences:
            [{"severity": "critical|high|medium|low", "code": "short_snake_case_id", "message": "specific finding", "recommendation": "specific fix"}]

            If you find no notable issues, respond with an empty array: []
            PROMPT;
    }
}
