<?php

namespace App\DTOs;

use App\Enums\AuditCategory;

final readonly class AuditModuleResult
{
    /**
     * @param  int|null  $score  Null means "not applicable" — never a
     *                           manufactured number when there isn't enough
     *                           evidence to score this category (e.g.
     *                           Ecommerce on a site with no commerce
     *                           signals). Callers must check
     *                           $isApplicable, not `$score === 0`, to tell
     *                           "not applicable" apart from "scored zero."
     * @param  AuditIssueData[]  $issues
     * @param  array<string, int>  $breakdown  Rule code => points deducted, for score transparency
     * @param  string[]  $detectionEvidence  Human-readable signals behind an applicability decision (e.g. ecommerce detection confidence) — shown in the report so "why is this N/A" is never a mystery
     */
    public function __construct(
        public AuditCategory $category,
        public ?int $score,
        public array $issues = [],
        public array $breakdown = [],
        public bool $isApplicable = true,
        public ?string $notApplicableReason = null,
        public array $detectionEvidence = [],
    ) {}
}
