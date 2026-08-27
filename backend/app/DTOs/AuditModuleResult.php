<?php

namespace App\DTOs;

use App\Enums\AuditCategory;

final readonly class AuditModuleResult
{
    /**
     * @param  AuditIssueData[]  $issues
     * @param  array<string, int>  $breakdown  Rule code => points deducted, for score transparency
     */
    public function __construct(
        public AuditCategory $category,
        public int $score,
        public array $issues = [],
        public array $breakdown = [],
    ) {}
}
