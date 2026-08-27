<?php

namespace App\Services\Audit;

use App\Contracts\AuditModule;
use App\DTOs\AuditIssueData;
use App\DTOs\AuditModuleResult;
use App\Enums\AuditCategory;
use App\Enums\IssueSeverity;
use App\Enums\PlatformName;
use App\Models\Audit;
use App\Services\AI\AiAnalysisService;

class EcommerceAuditModule implements AuditModule
{
    public function __construct(
        private readonly AiAnalysisService $ai,
    ) {}

    public function category(): AuditCategory
    {
        return AuditCategory::Ecommerce;
    }

    public function run(Audit $audit): AuditModuleResult
    {
        $pageContexts = PageContextSummarizer::summarizePages($audit);

        $hasProductSchema = collect($pageContexts)->contains(fn ($p) => in_array('Product', $p['structured_data_types']));
        $hasPurchaseCta = collect($pageContexts)->contains(fn ($p) => collect($p['cta_texts'])->contains(
            fn ($text) => preg_match('/\b(buy|add to cart|checkout|shop now)\b/i', $text)
        ));

        // Only an ecommerce audit if the site actually shows commerce
        // signals — otherwise there's nothing for this module to say.
        $looksLikeEcommerce = $hasProductSchema || $hasPurchaseCta || $audit->website->platform === PlatformName::Shopify;

        if (! $looksLikeEcommerce) {
            return new AuditModuleResult(category: $this->category(), score: 100, issues: [], breakdown: []);
        }

        $issues = [];

        if (! $hasProductSchema) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Medium,
                code: 'ecommerce.missing_product_schema',
                message: 'No Product structured data found on crawled pages.',
                recommendation: 'Add schema.org Product markup (price, availability, reviews) so product pages are eligible for rich search results.',
            );
        }

        if (! $hasPurchaseCta) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::High,
                code: 'ecommerce.missing_purchase_cta',
                message: 'No clear "buy" / "add to cart" / "checkout" call-to-action found on crawled pages.',
                recommendation: 'Ensure a prominent purchase CTA is visible on product and category pages.',
            );
        }

        $aiResponse = $this->ai->analyze(
            $audit,
            $this->category(),
            $this->buildPrompt(),
            ['pages' => $pageContexts],
        );

        if ($aiResponse !== null) {
            $issues = array_merge($issues, AiIssueParser::parse($aiResponse->content, 'ai.ecommerce'));
        }

        [$score, $breakdown] = ScoreCalculator::deductPoints($issues);

        return new AuditModuleResult(category: $this->category(), score: $score, issues: $issues, breakdown: $breakdown);
    }

    private function buildPrompt(): string
    {
        return <<<'PROMPT'
            You are auditing an ecommerce website's crawled pages for conversion
            and trust issues. You are given a JSON array of page summaries
            (title, headings, CTA button text, structured data types, image/form
            counts) — not raw HTML.

            Identify concrete, specific issues affecting purchase confidence:
            trust signals (reviews, guarantees, security badges — inferred only
            from headings/CTA text actually present, do not assume), checkout
            friction (excessive form fields), unclear product information, or
            missing category/product navigation cues.

            Respond with ONLY a JSON array, no prose, no markdown fences:
            [{"severity": "critical|high|medium|low", "code": "short_snake_case_id", "message": "specific finding", "recommendation": "specific fix"}]

            If you find no notable issues, respond with an empty array: []
            PROMPT;
    }
}
