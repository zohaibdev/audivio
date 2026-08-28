<?php

namespace Tests\Unit\Services\Audit;

use App\DTOs\AuditIssueData;
use App\Enums\IssueSeverity;
use App\Services\Audit\PerPageIssueAggregator;
use Tests\TestCase;

class PerPageIssueAggregatorTest extends TestCase
{
    public function test_a_single_occurrence_of_a_code_passes_through_unchanged(): void
    {
        $issue = new AuditIssueData(
            severity: IssueSeverity::High,
            code: 'seo.missing_meta_description',
            message: 'Page is missing a meta description: https://example.com/a',
            auditPageId: 5,
            meta: ['url' => 'https://example.com/a'],
        );

        $result = PerPageIssueAggregator::aggregate([$issue]);

        $this->assertSame([$issue], $result);
    }

    public function test_repeated_occurrences_of_a_code_collapse_into_one_site_wide_issue(): void
    {
        $issues = [
            new AuditIssueData(IssueSeverity::High, 'seo.missing_meta_description', 'Page is missing a meta description: https://example.com/a', auditPageId: 1, meta: ['url' => 'https://example.com/a']),
            new AuditIssueData(IssueSeverity::High, 'seo.missing_meta_description', 'Page is missing a meta description: https://example.com/b', auditPageId: 2, meta: ['url' => 'https://example.com/b']),
            new AuditIssueData(IssueSeverity::High, 'seo.missing_meta_description', 'Page is missing a meta description: https://example.com/c', auditPageId: 3, meta: ['url' => 'https://example.com/c']),
        ];

        $result = PerPageIssueAggregator::aggregate($issues);

        $this->assertCount(1, $result);
        $this->assertNull($result[0]->auditPageId);
        $this->assertSame(3, $result[0]->meta['affected_page_count']);
        $this->assertSame(['https://example.com/a', 'https://example.com/b', 'https://example.com/c'], $result[0]->meta['affected_pages']);
        $this->assertSame([1, 2, 3], $result[0]->affectedPageIds);
        $this->assertCount(3, $result[0]->meta['instances']);
        $this->assertStringContainsString('(3 pages affected)', $result[0]->message);
    }

    public function test_different_codes_are_not_merged_together(): void
    {
        $issues = [
            new AuditIssueData(IssueSeverity::High, 'seo.missing_meta_description', 'x: https://example.com/a', auditPageId: 1, meta: ['url' => 'https://example.com/a']),
            new AuditIssueData(IssueSeverity::Medium, 'seo.missing_h1', 'y: https://example.com/a', auditPageId: 1, meta: ['url' => 'https://example.com/a']),
        ];

        $result = PerPageIssueAggregator::aggregate($issues);

        $this->assertCount(2, $result);
    }

    public function test_affected_pages_list_is_deduplicated(): void
    {
        $issues = [
            new AuditIssueData(IssueSeverity::Medium, 'seo.missing_h1', 'x: https://example.com/a', auditPageId: 1, meta: ['url' => 'https://example.com/a']),
            new AuditIssueData(IssueSeverity::Medium, 'seo.missing_h1', 'x: https://example.com/a', auditPageId: 1, meta: ['url' => 'https://example.com/a']),
        ];

        $result = PerPageIssueAggregator::aggregate($issues);

        $this->assertSame(['https://example.com/a'], $result[0]->meta['affected_pages']);
        $this->assertSame(1, $result[0]->meta['affected_page_count']);
    }
}
