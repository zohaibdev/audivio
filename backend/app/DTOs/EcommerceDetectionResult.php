<?php

namespace App\DTOs;

final readonly class EcommerceDetectionResult
{
    /**
     * @param  string  $confidence  'none'|'low'|'medium'|'high'
     * @param  string[]  $signals  Human-readable evidence, shown in the report so a Not Applicable (or an enabled) decision is never a mystery
     */
    public function __construct(
        public bool $isEcommerce,
        public string $confidence,
        public array $signals,
    ) {}
}
