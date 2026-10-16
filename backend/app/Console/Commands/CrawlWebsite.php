<?php

namespace App\Console\Commands;

use App\Enums\AuditStatus;
use App\Jobs\Crawl\CrawlWebsiteJob;
use App\Models\Audit;
use App\Models\Report;
use App\Models\Website;
use App\Services\Crawler\SsrfValidationException;
use App\Services\Crawler\UrlNormalizer;
use App\Services\Crawler\UrlSecurityValidator;
use App\Services\Plan\EntitlementResolver;
use App\Services\Report\ReportVisibilityFilter;
use Illuminate\Console\Command;

class CrawlWebsite extends Command
{
    protected $signature = 'audit:crawl {url} {--pages=5} {--wait}';

    protected $description = 'Create an audit for a URL and dispatch the crawl (for Phase 2 testing, ahead of the public API endpoint)';

    public function handle(UrlSecurityValidator $validator): int
    {
        $url = $this->argument('url');
        $maxPages = (int) $this->option('pages');

        try {
            $validator->assertSafe($url);
        } catch (SsrfValidationException $e) {
            $this->error("Refusing to crawl: {$e->getMessage()}");

            return self::FAILURE;
        }

        $domain = parse_url($url, PHP_URL_HOST);

        // Always a fresh row, like any anonymous submission — this command
        // has no user to resolve an existing website against.
        $website = Website::create([
            'primary_url' => $url,
            'domain' => $domain,
            'normalized_domain' => app(UrlNormalizer::class)->normalizedDomain($url),
        ]);

        $audit = Audit::create([
            'website_id' => $website->id,
            'status' => AuditStatus::Queued,
            'max_pages' => $maxPages,
        ]);

        CrawlWebsiteJob::dispatch($audit);

        $this->info("Audit #{$audit->id} queued for {$url} (max {$maxPages} pages).");
        // The configured connection, not a guess: this said "redis" while
        // the app runs the database driver, so anyone following it started
        // a worker on a queue that would never receive the job (§56).
        $connection = config('queue.default');

        $this->line("Run `php artisan queue:work {$connection}` in another terminal if it is not already running.");

        if (! $this->option('wait')) {
            return self::SUCCESS;
        }

        $this->info('Waiting for crawl to complete (Ctrl+C to stop watching)...');

        while (true) {
            $audit->refresh();

            if ($audit->status->isTerminal()) {
                break;
            }

            usleep(500_000);
        }

        if ($audit->status === AuditStatus::Failed) {
            $this->error("Audit failed: {$audit->error_message}");

            return self::FAILURE;
        }

        // GenerateReportJob is dispatched from inside CalculateScoreJob, so
        // with a real queue driver it can still be pending for a moment
        // after Audit.status flips to Completed — wait for it too.
        $report = null;
        for ($i = 0; $i < 20 && ! $report; $i++) {
            $report = Report::where('audit_id', $audit->id)->first();
            if (! $report) {
                usleep(250_000);
            }
        }

        $this->info("Audit #{$audit->id} completed. Pages crawled: {$audit->pages_crawled}.");
        $this->table(
            ['Page ID', 'URL', 'HTTP', 'Title'],
            $audit->pages()->get(['id', 'url', 'http_status', 'title'])->map(fn ($p) => [
                $p->id, $p->url, $p->http_status, $p->title,
            ])->all(),
        );

        $this->newLine();
        $this->info('Scores:');
        $this->table(
            ['Category', 'Score'],
            $audit->scores()->get(['category', 'score'])->map(fn ($s) => [$s->category->value, $s->score])->all(),
        );

        $this->newLine();
        $this->info('Issues:');
        $this->table(
            ['Category', 'Severity', 'Code', 'Message'],
            $audit->issues()->get(['category', 'severity', 'code', 'message'])->map(fn ($i) => [
                $i->category->value, $i->severity->value, $i->code, $i->message,
            ])->all(),
        );

        $this->newLine();
        if (! $report) {
            $this->warn('No report was generated (GenerateReportJob has not run yet — is a queue worker running?).');

            return self::SUCCESS;
        }

        $filter = app(ReportVisibilityFilter::class);
        // This command has no authenticated caller, so the restricted view
        // it prints is the guest tier's — the same one a visitor sees. The
        // full view is what an owner on an unrestricted plan gets, printed
        // alongside so the two are comparable at a glance.
        $guest = app(EntitlementResolver::class)->forOrDeny(null);
        $full = Entitlements::fromGuestConfig(
            array_merge(config('entitlements.guest'), ['report_visibility_percent' => 100]),
        );

        $this->info('Report (anonymous/free view):');
        $anonymousView = $filter->filter($report->content, entitlements: $guest);
        $this->line('  Visible issues: '.count($anonymousView['issues'])." (hidden: {$anonymousView['hidden_issue_count']})");
        $this->line('  Visible recommendations: '.count($anonymousView['recommendations'])." (hidden: {$anonymousView['hidden_recommendation_count']})");

        $this->newLine();
        $this->info('Report (full view):');
        $fullView = $filter->filter($report->content, entitlements: $full);
        $this->line('  Issues: '.count($fullView['issues']));
        $this->line('  Recommendations: '.count($fullView['recommendations']));
        $aiCategories = array_keys($fullView['ai_analysis']);
        $this->line('  AI analysis available for: '.($aiCategories === [] ? '(none)' : implode(', ', $aiCategories)));

        return self::SUCCESS;
    }
}
