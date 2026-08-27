<?php

namespace App\Services\Audit;

use App\Contracts\AuditModule;
use App\DTOs\AuditIssueData;
use App\DTOs\AuditModuleResult;
use App\Enums\AuditCategory;
use App\Enums\IssueSeverity;
use App\Models\Audit;
use App\Models\AuditPage;

class PerformanceAuditModule implements AuditModule
{
    public function __construct(
        private readonly PageSpeedService $pageSpeed,
    ) {
    }

    public function category(): AuditCategory
    {
        return AuditCategory::Performance;
    }

    public function run(Audit $audit): AuditModuleResult
    {
        $pages = $audit->pages;
        $issues = [];

        foreach ($pages as $page) {
            $issues = array_merge(
                $issues,
                $this->checkLoadTime($page),
                $this->checkPageWeight($page),
                $this->checkImageDimensions($page),
            );
        }

        $pagespeedPageCount = config('audit_scoring.performance.pagespeed_pages');
        foreach ($pages->take($pagespeedPageCount) as $page) {
            $issues = array_merge($issues, $this->checkPageSpeedInsights($page));
        }

        [$score, $breakdown] = ScoreCalculator::deductPoints($issues);

        return new AuditModuleResult(category: $this->category(), score: $score, issues: $issues, breakdown: $breakdown);
    }

    /** @return AuditIssueData[] */
    private function checkLoadTime(AuditPage $page): array
    {
        $max = config('audit_scoring.performance.max_load_time_ms');

        if (($page->load_time_ms ?? 0) > $max) {
            return [new AuditIssueData(
                severity: IssueSeverity::Medium,
                code: 'performance.slow_load_time',
                message: "Page took {$page->load_time_ms}ms to load (budget: {$max}ms): {$page->url}",
                recommendation: 'Investigate render-blocking resources, unoptimized assets, or slow server response time.',
                auditPageId: $page->id,
                meta: ['url' => $page->url, 'load_time_ms' => $page->load_time_ms],
            )];
        }

        return [];
    }

    /** @return AuditIssueData[] */
    private function checkPageWeight(AuditPage $page): array
    {
        $max = config('audit_scoring.performance.max_page_weight_bytes');

        if (($page->html_size_bytes ?? 0) > $max) {
            $kb = round($page->html_size_bytes / 1024);
            $maxKb = round($max / 1024);

            return [new AuditIssueData(
                severity: IssueSeverity::Low,
                code: 'performance.large_html',
                message: "HTML size is {$kb}KB (budget: {$maxKb}KB): {$page->url}",
                auditPageId: $page->id,
                meta: ['url' => $page->url, 'bytes' => $page->html_size_bytes],
            )];
        }

        return [];
    }

    /** @return AuditIssueData[] */
    private function checkImageDimensions(AuditPage $page): array
    {
        $missing = collect($page->images ?? [])
            ->filter(fn ($img) => empty($img['width']) || empty($img['height']))
            ->count();

        if ($missing === 0) {
            return [];
        }

        return [new AuditIssueData(
            severity: IssueSeverity::Low,
            code: 'performance.missing_image_dimensions',
            message: "{$missing} image(s) missing explicit width/height (risk of layout shift): {$page->url}",
            recommendation: 'Set explicit width/height (or aspect-ratio) on images to prevent cumulative layout shift.',
            auditPageId: $page->id,
            meta: ['url' => $page->url, 'count' => $missing],
        )];
    }

    /** @return AuditIssueData[] */
    private function checkPageSpeedInsights(AuditPage $page): array
    {
        $result = $this->pageSpeed->analyze($page->url);

        if ($result === null) {
            return [];
        }

        $page->update(['performance_metrics' => $result]);

        $issues = [];

        if ($result['score'] < 50) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Critical,
                code: 'performance.pagespeed_score',
                message: "PageSpeed Insights performance score is {$result['score']}/100: {$page->url}",
                recommendation: 'Review the full PageSpeed Insights report for prioritized fixes.',
                auditPageId: $page->id,
                meta: ['url' => $page->url, 'score' => $result['score']],
            );
        } elseif ($result['score'] < 90) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Medium,
                code: 'performance.pagespeed_score',
                message: "PageSpeed Insights performance score is {$result['score']}/100: {$page->url}",
                auditPageId: $page->id,
                meta: ['url' => $page->url, 'score' => $result['score']],
            );
        }

        if ($result['lcpMs'] !== null && $result['lcpMs'] > 2500) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::High,
                code: 'performance.lcp',
                message: 'Largest Contentful Paint is '.round($result['lcpMs']).'ms (should be under 2500ms): '.$page->url,
                auditPageId: $page->id,
                meta: ['url' => $page->url, 'lcp_ms' => $result['lcpMs']],
            );
        }

        if ($result['cls'] !== null && $result['cls'] > 0.1) {
            $issues[] = new AuditIssueData(
                severity: IssueSeverity::Medium,
                code: 'performance.cls',
                message: 'Cumulative Layout Shift is '.round($result['cls'], 3).' (should be under 0.1): '.$page->url,
                auditPageId: $page->id,
                meta: ['url' => $page->url, 'cls' => $result['cls']],
            );
        }

        return $issues;
    }
}
