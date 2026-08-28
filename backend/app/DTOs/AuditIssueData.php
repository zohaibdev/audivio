<?php

namespace App\DTOs;

use App\Enums\IssueSeverity;

final readonly class AuditIssueData
{
    /**
     * @param  int[]  $affectedPageIds  For an issue spanning more than one
     *                                  page, every affected page's ID —
     *                                  RunAuditModuleJob syncs these into
     *                                  the issue_pages pivot so the issue
     *                                  is queryable by page and vice versa
     *                                  (see PerPageIssueAggregator). A
     *                                  single-page issue uses
     *                                  $auditPageId instead and leaves
     *                                  this empty.
     */
    public function __construct(
        public IssueSeverity $severity,
        public string $code,
        public string $message,
        public ?string $recommendation = null,
        public ?int $auditPageId = null,
        public array $meta = [],
        public array $affectedPageIds = [],
    ) {}
}
