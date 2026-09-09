<?php

namespace App\Services\Audit;

use App\Contracts\AuditModule;
use App\DTOs\AuditModuleResult;
use App\Enums\AuditCategory;
use App\Models\Audit;
use App\Services\Seo\LinkGraph;
use App\Services\Seo\PageSeoChecks;
use App\Services\Seo\SiteSeoChecks;
use App\Services\Seo\SiteSeoCoverage;

/**
 * The deep SEO audit (§44).
 *
 * Twenty-four checks split across two collaborators by the only
 * distinction that matters to them: `PageSeoChecks` answers questions
 * about a single page, `SiteSeoChecks` answers questions that only exist
 * across pages — duplicates, orphans, redirect chains, robots.txt.
 *
 * The link graph is built once and passed to both. Broken links, orphan
 * pages, nofollow links and links into redirects are four different
 * questions about the same structure, and rebuilding it per check would
 * be four traversals for one answer.
 *
 * Per-page findings go through `PerPageIssueAggregator` so that a problem
 * on 24 pages is one issue with a page list, not 24 issues that flatten
 * the score. Site-wide findings already carry their own affected pages
 * and bypass it.
 */
class SeoAuditModule implements AuditModule
{
    public function __construct(
        private readonly PageSeoChecks $pageChecks,
        private readonly SiteSeoChecks $siteChecks,
        private readonly SiteSeoCoverage $coverage,
    ) {}

    public function category(): AuditCategory
    {
        return AuditCategory::Seo;
    }

    public function run(Audit $audit): AuditModuleResult
    {
        $audit->loadMissing(['pages', 'website', 'siteSignals']);

        $links = new LinkGraph($audit, $audit->website->domain);

        $perPageIssues = [];

        foreach ($audit->pages as $page) {
            $perPageIssues = array_merge($perPageIssues, $this->pageChecks->run($page, $links));
        }

        $issues = array_merge(
            PerPageIssueAggregator::aggregate($perPageIssues),
            $this->siteChecks->run($audit, $links),
        );

        [$score, $breakdown] = ScoreCalculator::deductPoints($issues);

        // What the audit was able to look at, and which of the named
        // checks actually ran. Without this, a check that silently did
        // nothing (because the crawl never captured its input) is
        // indistinguishable from a check that found no problems.
        $breakdown['coverage'] = $this->coverage->summarize($audit, $links);

        return new AuditModuleResult(
            category: $this->category(),
            score: $score,
            issues: $issues,
            breakdown: $breakdown,
        );
    }
}
