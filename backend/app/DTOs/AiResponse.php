<?php

namespace App\DTOs;

final readonly class AiResponse
{
    public function __construct(
        public string $provider,
        public string $content,
        public array $raw = [],
        public ?int $promptTokens = null,
        public ?int $completionTokens = null,
        public ?int $responseTimeMs = null,
        public ?int $httpStatus = null,
    ) {}
}
