<?php

namespace App\Jobs\Crawl;

use App\Enums\CrawlJobStatus;
use App\Enums\CrawlUrlStatus;
use App\Models\CrawlUrl;
use App\Services\Audit\AuditModuleRegistry;
use App\Services\Crawler\CrawlOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

class CrawlPageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly CrawlUrl $crawlUrl) {}

    public function middleware(): array
    {
        return [new RateLimited('crawler')];
    }

    public function handle(CrawlOrchestrator $orchestrator): void
    {
        $crawlJob = $this->crawlUrl->crawlJob;
        $audit = $crawlJob->audit;

        $newUrls = $orchestrator->crawlDiscoveredUrl($this->crawlUrl);

        $audit->increment('pages_crawled');

        foreach ($newUrls as $newUrl) {
            self::dispatch($newUrl);
        }

        $this->finalizeIfComplete($crawlJob->fresh(), $audit);
    }

    private function finalizeIfComplete($crawlJob, $audit): void
    {
        $stillPending = $crawlJob->urls()
            ->whereIn('status', [CrawlUrlStatus::Pending, CrawlUrlStatus::Crawling])
            ->exists();

        if ($stillPending) {
            return;
        }

        $crawlJob->update(['status' => CrawlJobStatus::Completed, 'completed_at' => now()]);
        AuditModuleRegistry::dispatchAnalysis($audit);
    }
}
