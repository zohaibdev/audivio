<?php

namespace App\DTOs;

use App\Enums\SiteType;

/**
 * What kind of site this is, how sure we are, and why (§46).
 *
 * The evidence is not decoration. A classification decides which services
 * are reported at all, so a reader who disagrees with it needs to see
 * what it was based on — otherwise a wrong answer is indistinguishable
 * from an arbitrary one.
 */
final readonly class SiteClassification
{
    /**
     * @param  string[]  $signals  Human-readable evidence, strongest first.
     * @param  array<string, int>  $scores  Every type's score, for transparency.
     */
    public function __construct(
        public SiteType $type,
        public int $confidence,
        public array $signals,
        public array $scores = [],
    ) {}

    /**
     * A strict majority of the evidence. At or below half, the winning
     * type is not meaningfully ahead of the others — a 50/50 split is a
     * tie, not a decision — and the honest answer is `Other`.
     */
    public function isConfident(): bool
    {
        return $this->confidence > 50;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'label' => $this->type->label(),
            'description' => $this->type->description(),
            'confidence' => $this->confidence,
            'is_confident' => $this->isConfident(),
            'signals' => $this->signals,
            'scores' => $this->scores,
        ];
    }
}
