<?php

namespace App\Services\Seo;

use App\DTOs\AuditIssueData;
use App\Enums\IssueSeverity;
use App\Models\AuditPage;

/**
 * Everything the deep SEO audit can decide by looking at one page (§44).
 *
 * These emit one issue per page; `PerPageIssueAggregator` collapses the
 * repeated ones into a single site-wide finding afterwards, so "24 pages
 * are missing a meta description" is one issue with a page list rather
 * than 24 issues that flatten the score.
 */
class PageSeoChecks
{
    public function __construct(
        private readonly IndexabilityResolver $indexability,
        private readonly SchemaAnalyzer $schema,
        private readonly SocialCardAnalyzer $social,
    ) {}

    /**
     * @return AuditIssueData[]
     */
    public function run(AuditPage $page, LinkGraph $links): array
    {
        return array_merge(
            $this->checkHttpStatus($page),
            $this->checkTitle($page),
            $this->checkMetaDescription($page),
            $this->checkCanonical($page),
            $this->checkIndexability($page),
            $this->checkH1($page),
            $this->checkHeadingHierarchy($page),
            $this->checkImages($page),
            $this->checkSocialCards($page),
            $this->checkSchema($page),
            $this->checkInternalLinking($page, $links),
        );
    }

    /** @return AuditIssueData[] */
    private function checkHttpStatus(AuditPage $page): array
    {
        $status = $page->http_status;

        if ($status === null || $status < 400) {
            return [];
        }

        return [new AuditIssueData(
            severity: $status >= 500 ? IssueSeverity::Critical : IssueSeverity::High,
            code: $status >= 500 ? 'seo.server_error_page' : 'seo.client_error_page',
            message: "Page returns HTTP {$status}: {$page->url}",
            recommendation: $status >= 500
                ? 'Fix the server error. A page returning a 5xx status cannot be indexed and signals an unreliable site.'
                : 'Restore the page or redirect this URL to the closest equivalent. A 404 reached from your own links wastes both visitors and crawl budget.',
            auditPageId: $page->id,
            meta: ['url' => $page->url, 'http_status' => $status],
        )];
    }

    /** @return AuditIssueData[] */
    private function checkTitle(AuditPage $page): array
    {
        if (empty($page->title)) {
            return [new AuditIssueData(
                severity: IssueSeverity::Critical,
                code: 'seo.missing_title',
                message: "Page is missing a <title> tag: {$page->url}",
                recommendation: 'Add a unique, descriptive <title> tag between '.config('audit_scoring.seo.title_min_length').' and '.config('audit_scoring.seo.title_max_length').' characters.',
                auditPageId: $page->id,
                meta: ['url' => $page->url],
            )];
        }

        $length = mb_strlen($page->title);
        $min = config('audit_scoring.seo.title_min_length');
        $max = config('audit_scoring.seo.title_max_length');

        if ($length < $min || $length > $max) {
            return [new AuditIssueData(
                severity: IssueSeverity::Low,
                code: 'seo.title_length',
                message: "Title length is outside the recommended {$min}-{$max} character range: {$page->url}",
                recommendation: $length > $max
                    ? "Shorten the title to {$max} characters or fewer so it is not truncated in search results."
                    : "Expand the title to at least {$min} characters so it describes the page.",
                auditPageId: $page->id,
                meta: ['url' => $page->url, 'length' => $length, 'title' => $page->title],
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
                message: "Page is missing a meta description: {$page->url}",
                recommendation: 'Add a unique meta description summarizing the page. Without one, search engines assemble a snippet from the page text, which rarely reads as an invitation to click.',
                auditPageId: $page->id,
                meta: ['url' => $page->url],
            )];
        }

        $length = mb_strlen($page->meta_description);
        $min = config('audit_scoring.seo.meta_description_min_length');
        $max = config('audit_scoring.seo.meta_description_max_length');

        if ($length < $min || $length > $max) {
            return [new AuditIssueData(
                severity: IssueSeverity::Low,
                code: 'seo.meta_description_length',
                message: "Meta description length is outside the recommended {$min}-{$max} character range: {$page->url}",
                recommendation: $length > $max
                    ? "Shorten the description to {$max} characters or fewer so it is not cut off in results."
                    : "Expand the description to at least {$min} characters.",
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
                message: "Page is missing a canonical URL: {$page->url}",
                recommendation: 'Add a <link rel="canonical"> tag pointing at this page\'s preferred URL, so query strings and duplicate paths do not compete with each other.',
                auditPageId: $page->id,
                meta: ['url' => $page->url],
            )];
        }

        return [];
    }

    /**
     * Non-indexable pages. Reported at high severity when the page is one
     * the site itself links to and clearly intends to be public — which is
     * every page in a crawl that started from the homepage.
     *
     * @return AuditIssueData[]
     */
    private function checkIndexability(AuditPage $page): array
    {
        $verdict = $this->indexability->resolve($page);

        if ($verdict['is_indexable'] !== false) {
            return [];
        }

        // An error status is already reported by checkHttpStatus with a
        // better message; repeating it here as "not indexable" would
        // double-count one problem.
        $reasons = array_values(array_filter(
            $verdict['reasons'],
            fn (string $reason) => ! str_contains($reason, 'returns HTTP'),
        ));

        if ($reasons === []) {
            return [];
        }

        return [new AuditIssueData(
            severity: IssueSeverity::High,
            code: 'seo.not_indexable',
            message: "Page is excluded from search results: {$page->url}",
            recommendation: 'Remove the directive if this page should be findable. '.implode(' ', $reasons),
            auditPageId: $page->id,
            meta: [
                'url' => $page->url,
                'reasons' => $reasons,
                'directives' => $verdict['directives'],
            ],
        )];
    }

    /** @return AuditIssueData[] */
    private function checkH1(AuditPage $page): array
    {
        $h1s = collect($page->headings ?? [])->where('level', 1);

        if ($h1s->isEmpty()) {
            return [new AuditIssueData(
                severity: IssueSeverity::Medium,
                code: 'seo.missing_h1',
                message: "Page is missing an H1 heading: {$page->url}",
                recommendation: 'Add a single H1 stating what the page is about.',
                auditPageId: $page->id,
                meta: ['url' => $page->url],
            )];
        }

        if ($h1s->count() > 1) {
            return [new AuditIssueData(
                severity: IssueSeverity::Low,
                code: 'seo.multiple_h1',
                message: "Page has {$h1s->count()} H1 headings: {$page->url}",
                recommendation: 'Use a single H1 per page and demote the rest to H2, so the page has one clear subject.',
                auditPageId: $page->id,
                meta: [
                    'url' => $page->url,
                    'count' => $h1s->count(),
                    'headings' => $h1s->pluck('text')->take(5)->all(),
                ],
            )];
        }

        return [];
    }

    /**
     * Heading levels that jump — an H2 followed directly by an H4.
     *
     * Only *skips* are reported, not every ordering oddity: returning to a
     * higher level is normal document structure, and flagging it would
     * make the check noise the reader learns to ignore.
     *
     * @return AuditIssueData[]
     */
    private function checkHeadingHierarchy(AuditPage $page): array
    {
        $levels = collect($page->headings ?? [])
            ->pluck('level')
            ->filter(fn ($level) => is_int($level) || is_numeric($level))
            ->map(fn ($level) => (int) $level)
            ->values();

        if ($levels->count() < 2) {
            return [];
        }

        $skips = [];
        $previous = $levels->first();

        foreach ($levels->slice(1) as $level) {
            if ($level > $previous + 1) {
                $skips[] = "H{$previous} to H{$level}";
            }
            $previous = $level;
        }

        if ($skips === []) {
            return [];
        }

        return [new AuditIssueData(
            severity: IssueSeverity::Low,
            code: 'seo.heading_hierarchy_skip',
            message: count($skips)." heading level skip(s) on {$page->url}",
            recommendation: 'Use heading levels in order without skipping. The hierarchy is how both search engines and screen readers work out which content belongs to which section.',
            auditPageId: $page->id,
            meta: ['url' => $page->url, 'skips' => array_slice(array_unique($skips), 0, 10)],
        )];
    }

    /** @return AuditIssueData[] */
    private function checkImages(AuditPage $page): array
    {
        $images = collect($page->images ?? []);

        if ($images->isEmpty()) {
            return [];
        }

        $issues = [];

        $missingAlt = $images->filter(fn ($img) => ! isset($img['alt']) || trim((string) $img['alt']) === '');

        if ($missingAlt->isNotEmpty()) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Medium,
                code: 'seo.missing_image_alt',
                message: "{$missingAlt->count()} image(s) missing alt text: {$page->url}",
                recommendation: 'Add descriptive alt text to every content image. It is what lets an image rank in image search, and what a screen reader announces in place of the picture.',
                auditPageId: $page->id,
                meta: [
                    'url' => $page->url,
                    'count' => $missingAlt->count(),
                    'images' => $missingAlt->pluck('src')->filter()->take(10)->values()->all(),
                ],
            );
        }

        // Declared width/height reserve space before the image loads. This
        // is a layout-stability problem as much as an SEO one, and Core
        // Web Vitals is a ranking input.
        //
        // Skipped entirely unless the crawl recorded the attribute at all:
        // pages crawled before §44 have no `declaredWidth` key, and
        // treating "not captured" as "not set" would report every image on
        // every historical audit as broken.
        $capturedDimensions = $images->contains(
            fn ($img) => is_array($img) && array_key_exists('declaredWidth', $img)
        );

        $missingDimensions = $images->filter(
            fn ($img) => empty($img['declaredWidth']) || empty($img['declaredHeight'])
        );

        if ($capturedDimensions && $missingDimensions->isNotEmpty()) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Low,
                code: 'seo.image_missing_dimensions',
                message: "{$missingDimensions->count()} image(s) have no width/height attributes: {$page->url}",
                recommendation: 'Set width and height attributes on images so the browser can reserve space for them, which stops the page shifting as they load.',
                auditPageId: $page->id,
                meta: [
                    'url' => $page->url,
                    'count' => $missingDimensions->count(),
                    'images' => $missingDimensions->pluck('src')->filter()->take(10)->values()->all(),
                ],
            );
        }

        return $issues;
    }

    /** @return AuditIssueData[] */
    private function checkSocialCards(AuditPage $page): array
    {
        // Null (rather than an empty array) means this page was crawled
        // before social tags were captured at all. Reporting "no Open
        // Graph tags" for it would be a claim about markup nobody looked
        // at.
        if ($page->open_graph === null && $page->twitter_card === null) {
            return [];
        }

        $analysis = $this->social->analyze($page);
        $issues = [];

        if (! $analysis['has_open_graph']) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Medium,
                code: 'seo.missing_open_graph',
                message: "Page has no Open Graph tags: {$page->url}",
                recommendation: 'Add og:title, og:description, og:image and og:url. Without them, a shared link renders as a bare URL on most platforms.',
                auditPageId: $page->id,
                meta: ['url' => $page->url, 'preview' => $analysis['preview']],
            );
        } elseif ($analysis['missing_og_tags'] !== []) {
            $missing = implode(', ', $analysis['missing_og_tags']);

            $issues[] = new AuditIssueData(
                severity: $analysis['has_og_image'] ? IssueSeverity::Low : IssueSeverity::Medium,
                code: 'seo.incomplete_open_graph',
                message: "Open Graph tags incomplete ({$missing}): {$page->url}",
                recommendation: "Add the missing Open Graph tags: {$missing}.",
                auditPageId: $page->id,
                meta: [
                    'url' => $page->url,
                    'missing' => $analysis['missing_og_tags'],
                    'preview' => $analysis['preview'],
                ],
            );
        }

        if (! $analysis['has_twitter_card_type']) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Low,
                code: 'seo.missing_twitter_card',
                message: "Page has no twitter:card tag: {$page->url}",
                recommendation: 'Add <meta name="twitter:card" content="summary_large_image">. X falls back to Open Graph for the title, description and image, but not for the card type — without it the link renders plain.',
                auditPageId: $page->id,
                meta: ['url' => $page->url, 'preview' => $analysis['preview']],
            );
        }

        return $issues;
    }

    /** @return AuditIssueData[] */
    private function checkSchema(AuditPage $page): array
    {
        $issues = [];

        foreach ($this->schema->validate($page) as $result) {
            if ($result['missing_required'] !== []) {
                $missing = implode(', ', $result['missing_required']);

                $issues[] = new AuditIssueData(
                    severity: IssueSeverity::Medium,
                    code: "seo.schema_incomplete.{$result['type']}",
                    message: "{$result['label']} structured data is missing required properties ({$missing}): {$page->url}",
                    recommendation: "Add the missing properties to the {$result['type']} markup: {$missing}. Without them the rich result cannot be shown at all.",
                    auditPageId: $page->id,
                    meta: [
                        'url' => $page->url,
                        'schema_type' => $result['type'],
                        'missing_required' => $result['missing_required'],
                    ],
                );
            }

            if ($result['missing_recommended'] !== []) {
                $missing = implode(', ', $result['missing_recommended']);

                $issues[] = new AuditIssueData(
                    severity: IssueSeverity::Low,
                    code: "seo.schema_recommended.{$result['type']}",
                    message: "{$result['label']} structured data is missing recommended properties ({$missing}): {$page->url}",
                    recommendation: "Consider adding {$missing} to the {$result['type']} markup. The rich result still works without them, but shows less.",
                    auditPageId: $page->id,
                    meta: [
                        'url' => $page->url,
                        'schema_type' => $result['type'],
                        'missing_recommended' => $result['missing_recommended'],
                    ],
                );
            }
        }

        return $issues;
    }

    /** @return AuditIssueData[] */
    private function checkInternalLinking(AuditPage $page, LinkGraph $links): array
    {
        $minimum = (int) config('audit_scoring.seo.min_internal_links_per_page');
        $count = $links->internalLinkCountFor($page);

        // A page with no links at all is usually an error page or a
        // redirect target, both of which are reported elsewhere.
        if ($count === 0 && ($page->links ?? []) === []) {
            return [];
        }

        if ($count >= $minimum) {
            return [];
        }

        return [new AuditIssueData(
            severity: IssueSeverity::Low,
            code: 'seo.few_internal_links',
            message: "Page links to only {$count} other page(s) on this site: {$page->url}",
            recommendation: "Link to at least {$minimum} related pages. Internal links are how a crawler discovers the rest of the site and how it works out which pages matter.",
            auditPageId: $page->id,
            meta: ['url' => $page->url, 'internal_link_count' => $count],
        )];
    }
}
