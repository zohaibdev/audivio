<?php

namespace App\Services\Audit;

use App\Contracts\AuditModule;
use App\DTOs\AuditIssueData;
use App\DTOs\AuditModuleResult;
use App\Enums\AuditCategory;
use App\Enums\IssueSeverity;
use App\Models\Audit;
use App\Models\AuditPage;

/**
 * Audits individual product pages: whether each one gives a shopper (and
 * a search engine) what they need to buy with confidence.
 *
 * Runs only when real product pages were actually crawled — a site with
 * no detected product page reports Not Applicable rather than a score
 * derived from pages that sell nothing. Every check is deterministic and
 * reads only crawled page data; nothing here is AI-generated.
 */
class ProductAuditModule implements AuditModule
{
    public function __construct(private readonly ProductPageDetector $detector) {}

    public function category(): AuditCategory
    {
        return AuditCategory::ProductAuditor;
    }

    public function run(Audit $audit): AuditModuleResult
    {
        $productPages = $this->detector->detect($audit->pages);

        if ($productPages->isEmpty()) {
            return new AuditModuleResult(
                category: $this->category(),
                score: null,
                isApplicable: false,
                notApplicableReason: $audit->site_type !== null && ! $audit->site_type->canSellOnline()
                    ? $audit->site_type->ecommerceSkipReason()
                    : 'No product pages were found among the crawled pages, so there was nothing to audit.',
            );
        }

        $perPageIssues = [];

        foreach ($productPages as $page) {
            $perPageIssues = array_merge(
                $perPageIssues,
                $this->checkProductSchema($page),
                $this->checkPricing($page),
                $this->checkAvailability($page),
                $this->checkImages($page),
                $this->checkDescription($page),
                $this->checkReviews($page),
                $this->checkAddToCart($page),
            );
        }

        $issues = PerPageIssueAggregator::aggregate($perPageIssues);
        [$score, $breakdown] = ScoreCalculator::deductPoints($issues);

        return new AuditModuleResult(
            category: $this->category(),
            score: $score,
            issues: $issues,
            breakdown: $breakdown,
            detectionEvidence: [
                $productPages->count().' product page(s) detected among '.$audit->pages->count().' crawled pages',
            ],
        );
    }

    /** @return AuditIssueData[] */
    private function checkProductSchema(AuditPage $page): array
    {
        if ($this->detector->productSchema($page) !== null) {
            return [];
        }

        return [new AuditIssueData(
            severity: IssueSeverity::High,
            code: 'product.missing_product_schema',
            message: "Product page has no Product structured data: {$page->url}",
            recommendation: 'Add schema.org Product markup with name, image, description, price and availability so search engines can show rich product results.',
            auditPageId: $page->id,
            meta: ['url' => $page->url],
        )];
    }

    /** @return AuditIssueData[] */
    private function checkPricing(AuditPage $page): array
    {
        $schema = $this->detector->productSchema($page);

        if ($schema === null) {
            // Already reported as missing schema — flagging the absent
            // price separately would double-count the same root cause.
            return [];
        }

        $price = $schema['offers']['price'] ?? $schema['offers'][0]['price'] ?? null;

        if ($price !== null && $price !== '') {
            return [];
        }

        return [new AuditIssueData(
            severity: IssueSeverity::High,
            code: 'product.missing_price',
            message: "Product structured data has no price: {$page->url}",
            recommendation: 'Include an offers.price and priceCurrency so shoppers and search engines can see what the product costs.',
            auditPageId: $page->id,
            meta: ['url' => $page->url],
        )];
    }

    /** @return AuditIssueData[] */
    private function checkAvailability(AuditPage $page): array
    {
        $schema = $this->detector->productSchema($page);

        if ($schema === null) {
            return [];
        }

        $availability = $schema['offers']['availability'] ?? $schema['offers'][0]['availability'] ?? null;

        if ($availability !== null && $availability !== '') {
            return [];
        }

        return [new AuditIssueData(
            severity: IssueSeverity::Medium,
            code: 'product.missing_availability',
            message: "Product structured data has no availability status: {$page->url}",
            recommendation: 'Add offers.availability (for example InStock or OutOfStock) so shoppers know whether they can buy it now.',
            auditPageId: $page->id,
            meta: ['url' => $page->url],
        )];
    }

    /** @return AuditIssueData[] */
    private function checkImages(AuditPage $page): array
    {
        $imageCount = count($page->images ?? []);

        if ($imageCount === 0) {
            return [new AuditIssueData(
                severity: IssueSeverity::Critical,
                code: 'product.no_images',
                message: "Product page has no images at all: {$page->url}",
                recommendation: 'Add clear product photography — shoppers will not buy what they cannot see.',
                auditPageId: $page->id,
                meta: ['url' => $page->url],
            )];
        }

        if ($imageCount < config('audit_scoring.product.min_images')) {
            return [new AuditIssueData(
                severity: IssueSeverity::Medium,
                code: 'product.too_few_images',
                message: "Product page has only {$imageCount} image(s): {$page->url}",
                recommendation: 'Show the product from several angles and in use — multiple images measurably increase buyer confidence.',
                auditPageId: $page->id,
                meta: ['url' => $page->url, 'count' => $imageCount],
            )];
        }

        return [];
    }

    /** @return AuditIssueData[] */
    private function checkDescription(AuditPage $page): array
    {
        $schema = $this->detector->productSchema($page);
        $description = $schema['description'] ?? $page->meta_description ?? '';

        if (strlen(trim((string) $description)) >= config('audit_scoring.product.min_description_length')) {
            return [];
        }

        return [new AuditIssueData(
            severity: IssueSeverity::Medium,
            code: 'product.thin_description',
            message: "Product page has little or no descriptive copy: {$page->url}",
            recommendation: 'Describe what the product is, who it is for, and what problem it solves — thin product copy loses both shoppers and search rankings.',
            auditPageId: $page->id,
            meta: ['url' => $page->url, 'length' => strlen(trim((string) $description))],
        )];
    }

    /** @return AuditIssueData[] */
    private function checkReviews(AuditPage $page): array
    {
        $schema = $this->detector->productSchema($page);

        if ($schema === null) {
            return [];
        }

        if (isset($schema['aggregateRating']) || isset($schema['review'])) {
            return [];
        }

        return [new AuditIssueData(
            severity: IssueSeverity::Low,
            code: 'product.no_review_data',
            message: "Product page exposes no ratings or reviews: {$page->url}",
            recommendation: 'Collect and mark up customer reviews (aggregateRating) — social proof is one of the strongest drivers of purchase confidence, and it can show as stars in search results.',
            auditPageId: $page->id,
            meta: ['url' => $page->url],
        )];
    }

    /** @return AuditIssueData[] */
    private function checkAddToCart(AuditPage $page): array
    {
        if ($this->detector->hasAddToCart($page)) {
            return [];
        }

        return [new AuditIssueData(
            severity: IssueSeverity::High,
            code: 'product.no_add_to_cart',
            message: "No clear add-to-cart or buy action found on this product page: {$page->url}",
            recommendation: 'Make the primary purchase action obvious and above the fold — if a shopper has to hunt for it, many will not.',
            auditPageId: $page->id,
            meta: ['url' => $page->url],
        )];
    }
}
