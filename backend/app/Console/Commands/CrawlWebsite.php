<?php

namespace App\Console\Commands;

use App\Enums\AuditStatus;
use App\Jobs\Crawl\CrawlWebsiteJob;
use App\Models\Audit;
use App\Models\Website;
use App\Services\Crawler\SsrfValidationException;
use App\Services\Crawler\UrlSecurityValidator;
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

        $website = Website::create([
            'url' => $url,
            'domain' => $domain,
        ]);

        $audit = Audit::create([
            'website_id' => $website->id,
            'status' => AuditStatus::Pending,
            'max_pages' => $maxPages,
        ]);

        CrawlWebsiteJob::dispatch($audit);

        $this->info("Audit #{$audit->id} queued for {$url} (max {$maxPages} pages).");
        $this->line('Run `php artisan queue:work redis` in another terminal if it is not already running.');

        if (! $this->option('wait')) {
            return self::SUCCESS;
        }

        $this->info('Waiting for crawl to complete (Ctrl+C to stop watching)...');

        while (true) {
            $audit->refresh();

            if (in_array($audit->status, [AuditStatus::Completed, AuditStatus::Failed], true)) {
                break;
            }

            usleep(500_000);
        }

        if ($audit->status === AuditStatus::Failed) {
            $this->error("Audit failed: {$audit->error_message}");

            return self::FAILURE;
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

        return self::SUCCESS;
    }
}
