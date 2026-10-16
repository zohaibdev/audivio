<?php

namespace App\Services\Audit;

use App\Contracts\AuditModule;
use App\DTOs\AuditIssueData;
use App\DTOs\AuditModuleResult;
use App\Enums\AuditCategory;
use App\Enums\IssueSeverity;
use App\Models\Audit;
use App\Services\AI\AiAnalysisService;

class EcommerceAuditModule implements AuditModule
{
    public function __construct(
        private readonly AiAnalysisService $ai,
        private readonly EcommerceDetector $detector,
        private readonly ConversionSignalDetector $signals,
        private readonly ConversionSignalChecks $checks,
    ) {}

    public function category(): AuditCategory
    {
        return AuditCategory::Ecommerce;
    }

    public function run(Audit $audit): AuditModuleResult
    {
        $detection = $this->detector->detect($audit);

        // The site's classification is checked first, and it can veto
        // (§46). A blog with a stray "checkout" link in its footer can
        // clear the detector's threshold; it still has no store, and an
        // ecommerce score on it would be a category error rather than a
        // low score. The classifier's answer is the broader judgement, so
        // it wins.
        $siteType = $audit->site_type;

        if ($siteType !== null && ! $siteType->canSellOnline()) {
            return new AuditModuleResult(
                category: $this->category(),
                score: null,
                isApplicable: false,
                notApplicableReason: $siteType->ecommerceSkipReason(),
                detectionEvidence: $audit->site_type_signals ?? [],
            );
        }

        // Not enough evidence this is actually an ecommerce site — report
        // Not Applicable rather than running (and scoring) checks against
        // a site that sells nothing. No score, no issues, no ecommerce
        // recommendations for a site like a ministry/nonprofit whose
        // "Checkout" button is for event tickets, not products.
        if (! $detection->isEcommerce) {
            return new AuditModuleResult(
                category: $this->category(),
                score: null,
                isApplicable: false,
                notApplicableReason: 'No evidence of an online store was found on the pages that were crawled, so no ecommerce score is shown. The signals looked for include product structured data, an add-to-cart action, and a known ecommerce platform.',
                detectionEvidence: $detection->signals,
            );
        }

        $pageContexts = PageContextSummarizer::summarizePages($audit);
        $hasProductSchema = collect($pageContexts)->contains(fn ($p) => in_array('Product', $p['structured_data_types']));
        $hasPurchaseCta = collect($pageContexts)->contains(fn ($p) => collect($p['cta_texts'])->contains(
            fn ($text) => preg_match('/\b(buy|add to cart|checkout|shop now)\b/i', $text)
        ));

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

        // Shipping, returns, checkout reachability and review data (§46).
        // Deterministic, so a shop still gets these findings when the AI
        // provider is unavailable.
        $signals = $this->signals->detect($audit);
        $issues = array_merge($issues, $this->checks->ecommerceIssues($audit, $signals));

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
        $breakdown['signals'] = $signals;

        return new AuditModuleResult(
            category: $this->category(),
            score: $score,
            issues: $issues,
            breakdown: $breakdown,
            detectionEvidence: $detection->signals,
        );
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
