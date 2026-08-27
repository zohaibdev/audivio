<?php

namespace App\Services\Audit;

use App\Contracts\AuditModule;
use App\DTOs\AuditIssueData;
use App\DTOs\AuditModuleResult;
use App\Enums\AuditCategory;
use App\Enums\IssueSeverity;
use App\Models\Audit;
use App\Models\AuditPage;
use Illuminate\Support\Collection;

class SeoAuditModule implements AuditModule
{
    public function category(): AuditCategory
    {
        return AuditCategory::Seo;
    }

    public function run(Audit $audit): AuditModuleResult
    {
        $pages = $audit->pages;
        $issues = [];

        $urlStatusMap = $pages->pluck('http_status', 'url');
        $titlesSeen = [];

        foreach ($pages as $page) {
            $issues = array_merge(
                $issues,
                $this->checkTitle($page),
                $this->checkMetaDescription($page),
                $this->checkCanonical($page),
                $this->checkHeadings($page),
                $this->checkImageAlt($page),
                $this->checkRedirectChain($page),
                $this->checkBrokenInternalLinks($page, $urlStatusMap, $audit->website->domain),
            );

            if ($page->title) {
                $titlesSeen[$page->title][] = $page->url;
            }
        }

        $issues = array_merge($issues, $this->checkDuplicateTitles($titlesSeen));

        [$score, $breakdown] = ScoreCalculator::deductPoints($issues);

        return new AuditModuleResult(category: $this->category(), score: $score, issues: $issues, breakdown: $breakdown);
    }

    /** @return AuditIssueData[] */
    private function checkTitle(AuditPage $page): array
    {
        if (empty($page->title)) {
            return [new AuditIssueData(
                severity: IssueSeverity::Critical,
                code: 'seo.missing_title',
                message: "Page has no <title> tag: {$page->url}",
                recommendation: 'Add a unique, descriptive <title> tag between '.config('audit_scoring.seo.title_min_length').' and '.config('audit_scoring.seo.title_max_length').' characters.',
                auditPageId: $page->id,
                meta: ['url' => $page->url],
            )];
        }

        $length = strlen($page->title);
        $min = config('audit_scoring.seo.title_min_length');
        $max = config('audit_scoring.seo.title_max_length');

        if ($length < $min || $length > $max) {
            return [new AuditIssueData(
                severity: IssueSeverity::Low,
                code: 'seo.title_length',
                message: "Title length ({$length} chars) is outside the recommended {$min}-{$max} range: {$page->url}",
                recommendation: "Adjust the title to between {$min} and {$max} characters.",
                auditPageId: $page->id,
                meta: ['url' => $page->url, 'length' => $length],
            )];
        }

        return [];
    }

    /** @return AuditIssueData[] */
    private function checkMetaDescription(AuditPage $page): array
    {
        if (empty($page->meta_description)) {
            return [new AuditIssueData(
                severity: IssueSeverity::High,
                code: 'seo.missing_meta_description',
                message: "Page has no meta description: {$page->url}",
                recommendation: 'Add a unique meta description summarizing the page content.',
                auditPageId: $page->id,
                meta: ['url' => $page->url],
            )];
        }

        $length = strlen($page->meta_description);
        $min = config('audit_scoring.seo.meta_description_min_length');
        $max = config('audit_scoring.seo.meta_description_max_length');

        if ($length < $min || $length > $max) {
            return [new AuditIssueData(
                severity: IssueSeverity::Low,
                code: 'seo.meta_description_length',
                message: "Meta description length ({$length} chars) is outside the recommended {$min}-{$max} range: {$page->url}",
                auditPageId: $page->id,
                meta: ['url' => $page->url, 'length' => $length],
            )];
        }

        return [];
    }

    /** @return AuditIssueData[] */
    private function checkCanonical(AuditPage $page): array
    {
        if (empty($page->canonical_url)) {
            return [new AuditIssueData(
                severity: IssueSeverity::Medium,
                code: 'seo.missing_canonical',
                message: "Page has no canonical URL: {$page->url}",
                recommendation: 'Add a <link rel="canonical"> tag to avoid duplicate-content ambiguity.',
                auditPageId: $page->id,
                meta: ['url' => $page->url],
            )];
        }

        return [];
    }

    /** @return AuditIssueData[] */
    private function checkHeadings(AuditPage $page): array
    {
        $h1Count = collect($page->headings ?? [])->where('level', 1)->count();

        if ($h1Count === 0) {
            return [new AuditIssueData(
                severity: IssueSeverity::Medium,
                code: 'seo.missing_h1',
                message: "Page has no H1 heading: {$page->url}",
                auditPageId: $page->id,
                meta: ['url' => $page->url],
            )];
        }

        if ($h1Count > 1) {
            return [new AuditIssueData(
                severity: IssueSeverity::Low,
                code: 'seo.multiple_h1',
                message: "Page has {$h1Count} H1 headings: {$page->url}",
                recommendation: 'Use a single H1 per page for a clear content hierarchy.',
                auditPageId: $page->id,
                meta: ['url' => $page->url, 'count' => $h1Count],
            )];
        }

        return [];
    }

    /** @return AuditIssueData[] */
    private function checkImageAlt(AuditPage $page): array
    {
        $missing = collect($page->images ?? [])->filter(fn ($img) => empty($img['alt']))->count();

        if ($missing === 0) {
            return [];
        }

        return [new AuditIssueData(
            severity: IssueSeverity::Medium,
            code: 'seo.missing_image_alt',
            message: "{$missing} image(s) missing alt text: {$page->url}",
            recommendation: 'Add descriptive alt text to every content image.',
            auditPageId: $page->id,
            meta: ['url' => $page->url, 'count' => $missing],
        )];
    }

    /** @return AuditIssueData[] */
    private function checkRedirectChain(AuditPage $page): array
    {
        $hops = count($page->redirect_chain ?? []);

        if ($hops > 1) {
            return [new AuditIssueData(
                severity: IssueSeverity::Low,
                code: 'seo.redirect_chain',
                message: "Page reached via a {$hops}-hop redirect chain: {$page->url}",
                recommendation: 'Point links directly at the final URL to avoid redirect chains.',
                auditPageId: $page->id,
                meta: ['url' => $page->url, 'hops' => $hops],
            )];
        }

        return [];
    }

    /**
     * @param  Collection<string, int>  $urlStatusMap
     * @return AuditIssueData[]
     */
    private function checkBrokenInternalLinks(AuditPage $page, $urlStatusMap, string $domain): array
    {
        $broken = [];

        foreach ($page->links ?? [] as $link) {
            $href = $link['href'] ?? null;
            if (! $href || parse_url($href, PHP_URL_HOST) !== $domain) {
                continue;
            }

            // Only pages we actually crawled have a known status — we can't
            // assert a link is broken without having fetched its target.
            if ($urlStatusMap->has($href) && $urlStatusMap->get($href) >= 400) {
                $broken[] = $href;
            }
        }

        if ($broken === []) {
            return [];
        }

        return [new AuditIssueData(
            severity: IssueSeverity::High,
            code: 'seo.broken_internal_links',
            message: count($broken)." broken internal link(s) found on {$page->url}",
            recommendation: 'Fix or remove links pointing to pages that return an error status.',
            auditPageId: $page->id,
            meta: ['url' => $page->url, 'broken_links' => array_slice(array_unique($broken), 0, 10)],
        )];
    }

    /**
     * @param  array<string, string[]>  $titlesSeen
     * @return AuditIssueData[]
     */
    private function checkDuplicateTitles(array $titlesSeen): array
    {
        $issues = [];

        foreach ($titlesSeen as $title => $urls) {
            if (count($urls) > 1) {
                $issues[] = new AuditIssueData(
                    severity: IssueSeverity::Medium,
                    code: 'seo.duplicate_title',
                    message: count($urls)." pages share the title \"{$title}\"",
                    recommendation: 'Give each page a unique, descriptive title.',
                    meta: ['title' => $title, 'urls' => array_slice($urls, 0, 10)],
                );
            }
        }

        return $issues;
    }
}
