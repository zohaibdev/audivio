<?php

namespace App\DTOs;

use App\Enums\IssueSeverity;

final readonly class AuditIssueData
{
    public function __construct(
        public IssueSeverity $severity,
        public string $code,
        public string $message,
        public ?string $recommendation = null,
        public ?int $auditPageId = null,
        public array $meta = [],
    ) {
    }
}
