<?php

namespace App\Services\Audit;

use App\DTOs\AuditIssueData;
use App\Enums\IssueSeverity;
use App\Enums\SiteType;
use App\Models\Audit;

/**
 * Turns the measured conversion signals into findings (§46).
 *
 * Split from `ConversionSignalDetector` on purpose: that one answers
 * questions about the site, this one decides which answers are worth
 * telling an owner about. Keeping them apart means the report can show
 * the raw signals — every CRO and ecommerce area, whether or not it
 * produced a finding — without that display being tangled up in scoring.
 *
 * Several checks are **type-aware**: a returns policy is a real omission
 * on a shop and irrelevant on a blog, so the finding is only raised where
 * it means something. Reporting it everywhere would be the same mistake
 * as scoring ecommerce on a portfolio.
 */
class ConversionSignalChecks
{
    /**
     * @param  array<string, mixed>  $signals
     * @return AuditIssueData[]
     */
    public function croIssues(Audit $audit, array $signals): array
    {
        if (($signals['pages_analyzed'] ?? 0) === 0) {
            return [];
        }

        return array_merge(
            $this->ctaIssues($signals),
            $this->formIssues($signals),
            $this->navigationIssues($signals),
            $this->trustIssues($audit, $signals),
            $this->socialProofIssues($signals),
            $this->mobileIssues($audit, $signals),
        );
    }

    /**
     * @param  array<string, mixed>  $signals
     * @return AuditIssueData[]
     */
    public function ecommerceIssues(Audit $audit, array $signals): array
    {
        if (($signals['pages_analyzed'] ?? 0) === 0) {
            return [];
        }

        $issues = [];
        $policies = $signals['policies'] ?? [];

        foreach (['shipping' => IssueSeverity::High, 'returns' => IssueSeverity::High] as $key => $severity) {
            if (($policies[$key]['found'] ?? true) === true) {
                continue;
            }

            $label = strtolower((string) ($policies[$key]['label'] ?? $key));

            $issues[] = new AuditIssueData(
                severity: $severity,
                code: "ecommerce.missing_{$key}_information",
                message: "No {$label} was found on the pages that were crawled.",
                recommendation: $key === 'shipping'
                    ? 'Publish shipping costs and delivery times, and link them from the product and cart pages. Unexpected delivery cost at checkout is the most commonly cited reason for abandoning a cart.'
                    : 'Publish a returns policy and link it from product pages. Buyers who cannot find one before paying often do not pay.',
                meta: ['signal' => $key, 'scope' => 'crawled pages only'],
            );
        }

        if (($signals['checkout']['has_cart_route'] ?? false) === false) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Medium,
                code: 'ecommerce.no_cart_route_found',
                message: 'No cart or checkout route was found among the crawled pages.',
                recommendation: 'Make sure the cart is reachable by a normal link from every page. A cart that only appears after adding an item is invisible to a returning visitor who wants to finish an order.',
                meta: ['scope' => 'crawled pages only'],
            );
        }

        if (($signals['social_proof']['has_review_schema'] ?? false) === false) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Low,
                code: 'ecommerce.no_review_data',
                message: 'No review or rating structured data was found.',
                recommendation: 'Publish reviews with Review or AggregateRating markup. Star ratings in search results are one of the few things that visibly change click-through before a visitor ever reaches the site.',
                meta: [],
            );
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $signals
     * @return AuditIssueData[]
     */
    private function ctaIssues(array $signals): array
    {
        $ctas = $signals['ctas'] ?? [];
        $issues = [];

        $without = $ctas['pages_without_cta'] ?? [];

        if ($without !== []) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::High,
                code: 'conversion.pages_without_cta',
                message: count($without).' crawled page(s) have no call to action at all.',
                recommendation: 'Give every page one obvious next step. A page a visitor can read but not act on is a dead end.',
                meta: [
                    'affected_pages' => array_slice($without, 0, 10),
                    'affected_page_count' => count($without),
                ],
            );
        }

        if (($ctas['vague_cta_count'] ?? 0) > 0) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Medium,
                code: 'conversion.vague_cta_text',
                message: $ctas['vague_cta_count'].' call(s) to action use wording that does not say what happens next.',
                recommendation: 'Replace generic button text with the outcome: "Get my free quote" rather than "Submit". The label is often the last thing a visitor reads before deciding.',
                meta: ['examples' => $ctas['vague_ctas'] ?? []],
            );
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $signals
     * @return AuditIssueData[]
     */
    private function formIssues(array $signals): array
    {
        $forms = $signals['forms'] ?? [];

        if (($forms['long_form_count'] ?? 0) === 0) {
            return [];
        }

        $max = $forms['max_fields'] ?? 7;

        return [new AuditIssueData(
            severity: IssueSeverity::Medium,
            code: 'conversion.long_forms',
            message: $forms['long_form_count']." form(s) ask for more than {$max} pieces of information.",
            recommendation: 'Cut each form to what you genuinely need to respond. Every additional field costs completions, and fields you could ask for later cost them for nothing.',
            meta: ['forms' => $forms['long_forms'] ?? [], 'max_fields' => $max],
        )];
    }

    /**
     * @param  array<string, mixed>  $signals
     * @return AuditIssueData[]
     */
    private function navigationIssues(array $signals): array
    {
        $nav = $signals['navigation'] ?? [];
        $links = $nav['primary_links'] ?? null;

        if ($links === null) {
            return [];
        }

        if ($links > ($nav['max'] ?? 12)) {
            return [new AuditIssueData(
                severity: IssueSeverity::Low,
                code: 'conversion.wide_navigation',
                message: "The entry page offers {$links} distinct internal destinations.",
                recommendation: 'Group related links so the main navigation can be scanned rather than read. A visitor who has to study the menu to find anything usually picks nothing.',
                meta: ['primary_links' => $links, 'max' => $nav['max'] ?? 12],
            )];
        }

        if ($links < ($nav['min'] ?? 2)) {
            return [new AuditIssueData(
                severity: IssueSeverity::Medium,
                code: 'conversion.thin_navigation',
                message: "The entry page links to only {$links} other page(s) on this site.",
                recommendation: 'Add navigation to the rest of the site. Visitors who want more than the page they landed on currently have nowhere to go.',
                meta: ['primary_links' => $links, 'min' => $nav['min'] ?? 2],
            )];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $signals
     * @return AuditIssueData[]
     */
    private function trustIssues(Audit $audit, array $signals): array
    {
        $trust = $signals['trust'] ?? [];
        $policies = $signals['policies'] ?? [];
        $issues = [];

        if (($trust['has_contact_route'] ?? true) === false) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::High,
                code: 'conversion.no_contact_route',
                message: 'No contact page, phone link or email link was found on the crawled pages.',
                recommendation: 'Publish a way to reach you. A site with no visible contact route reads as one nobody is behind, which is the single cheapest trust problem to fix.',
                meta: ['scope' => 'crawled pages only'],
            );
        }

        if (($policies['privacy']['found'] ?? true) === false) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Medium,
                code: 'conversion.no_privacy_policy',
                message: 'No privacy policy was found on the pages that were crawled.',
                recommendation: 'Publish a privacy policy and link it from the footer. Visitors asked for an email address look for one, and in most jurisdictions collecting data without one is a legal problem as well as a conversion one.',
                meta: ['scope' => 'crawled pages only'],
            );
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $signals
     * @return AuditIssueData[]
     */
    private function socialProofIssues(array $signals): array
    {
        $proof = $signals['social_proof'] ?? [];

        if (($proof['has_review_schema'] ?? false) || ($proof['phrases_found'] ?? []) !== []) {
            return [];
        }

        return [new AuditIssueData(
            severity: IssueSeverity::Medium,
            code: 'conversion.no_social_proof',
            message: 'No testimonials, reviews or client references were found on the crawled pages.',
            recommendation: 'Show evidence that other people have already trusted you — a testimonial, a client list, a review count. A first-time visitor has no other way to judge.',
            meta: ['scope' => 'crawled pages only'],
        )];
    }

    /**
     * @param  array<string, mixed>  $signals
     * @return AuditIssueData[]
     */
    private function mobileIssues(Audit $audit, array $signals): array
    {
        $mobile = $signals['mobile'] ?? [];
        $issues = [];

        // Only where tapping to call is a plausible primary action. On a
        // blog or a SaaS product it is not, and demanding one would be
        // advice about a business the site does not run.
        $expectsCalls = in_array(
            $audit->site_type,
            [SiteType::LocalBusiness, SiteType::ServiceBusiness],
            true,
        );

        if ($expectsCalls && ($mobile['has_tel_link'] ?? false) === false) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Medium,
                code: 'conversion.no_tap_to_call',
                message: 'No tap-to-call telephone link was found.',
                recommendation: 'Make the phone number a tel: link. On a phone it turns the highest-intent action you have into one tap instead of copying digits between apps.',
                meta: ['site_type' => $audit->site_type?->value],
            );
        }

        if (($mobile['has_target_size_issues'] ?? false) === true) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Medium,
                code: 'conversion.small_tap_targets',
                message: 'Tap targets on the entry page are smaller than the recommended minimum.',
                recommendation: 'Make buttons and links large enough to hit reliably on a phone. This is measured by the accessibility scan and affects everyone using a touchscreen, not only people with impairments.',
                meta: [],
            );
        }

        return $issues;
    }
}
