<?php

namespace App\DTOs;

final readonly class CrawledPageData
{
    public function __construct(
        public string $url,
        public int $httpStatus,
        public array $redirectChain,
        public int $loadTimeMs,
        public ?string $screenshotDesktopPath,
        public ?string $screenshotMobilePath,
        public ?string $title,
        public ?string $metaDescription,
        public ?string $canonicalUrl,
        public array $headings,
        public array $links,
        public array $images,
        public array $forms,
        public array $structuredData,
        public array $ctaElements,
        public int $htmlSizeBytes,
        public array $headers = [],
        public string $htmlSample = '',
        public ?array $accessibilityViolations = null,
        /** axe rules that passed, and ones it could not decide (section 43). */
        public ?array $accessibilityPasses = null,
        public ?array $accessibilityIncomplete = null,
        /** Browser-measured resource/timing data — null when the crawler
         * could not collect it, never a zeroed placeholder. */
        public ?array $performanceMetrics = null,
        /**
         * Deep SEO signals (section 44). Defaulted and last in the
         * signature so the existing positional constructions keep working;
         * null means "this crawl did not capture it", which the audit
         * reports differently from "the page does not have it".
         */
        public ?string $metaRobots = null,
        public ?string $metaGooglebot = null,
        public ?array $openGraph = null,
        public ?array $twitterCard = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            url: $data['url'],
            httpStatus: $data['httpStatus'],
            redirectChain: $data['redirectChain'] ?? [],
            loadTimeMs: $data['loadTimeMs'] ?? 0,
            screenshotDesktopPath: $data['screenshotDesktopPath'] ?? null,
            screenshotMobilePath: $data['screenshotMobilePath'] ?? null,
            title: $data['title'] ?? null,
            metaDescription: $data['metaDescription'] ?? null,
            canonicalUrl: $data['canonicalUrl'] ?? null,
            headings: $data['headings'] ?? [],
            links: $data['links'] ?? [],
            images: $data['images'] ?? [],
            forms: $data['forms'] ?? [],
            structuredData: $data['structuredData'] ?? [],
            ctaElements: $data['ctaElements'] ?? [],
            htmlSizeBytes: $data['htmlSizeBytes'] ?? 0,
            headers: $data['headers'] ?? [],
            htmlSample: $data['htmlSample'] ?? '',
            accessibilityViolations: $data['accessibilityViolations'] ?? null,
            accessibilityPasses: $data['accessibilityPasses'] ?? null,
            accessibilityIncomplete: $data['accessibilityIncomplete'] ?? null,
            performanceMetrics: $data['performanceMetrics'] ?? null,
            metaRobots: $data['metaRobots'] ?? null,
            metaGooglebot: $data['metaGooglebot'] ?? null,
            openGraph: $data['openGraph'] ?? null,
            twitterCard: $data['twitterCard'] ?? null,
        );
    }
}
