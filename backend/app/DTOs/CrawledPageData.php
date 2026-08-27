<?php

namespace App\DTOs;

final readonly class CrawledPageData
{
    public function __construct(
        public string $url,
        public int $httpStatus,
        public array $redirectChain,
        public int $loadTimeMs,
        public ?string $screenshotPath,
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
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            url: $data['url'],
            httpStatus: $data['httpStatus'],
            redirectChain: $data['redirectChain'] ?? [],
            loadTimeMs: $data['loadTimeMs'] ?? 0,
            screenshotPath: $data['screenshotPath'] ?? null,
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
        );
    }
}
