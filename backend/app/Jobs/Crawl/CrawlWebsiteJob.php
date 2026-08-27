<?php

namespace App\Jobs\Crawl;

use App\Enums\AuditStatus;
use App\Enums\CrawlJobStatus;
use App\Models\Audit;
use App\Services\Crawler\CrawlOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CrawlWebsiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly Audit $audit)
    {
    }

    public function middleware(): array
    {
        return [new RateLimited('crawler')];
    }

    public function handle(CrawlOrchestrator $orchestrator): void
    {
        $this->audit->update(['status' => AuditStatus::Crawling, 'started_at' => now()]);

        try {
            $crawlJob = $orchestrator->startCrawl($this->audit);
        } catch (\Throwable $e) {
            Log::channel('crawler')->error('Website crawl failed to start', [
                'audit_id' => $this->audit->id,
                'error' => $e->getMessage(),
            ]);

            $this->audit->update([
                'status' => AuditStatus::Failed,
                'error_message' => Str::limit($e->getMessage(), 250),
                'completed_at' => now(),
            ]);

            return;
        }

        $this->audit->update(['pages_crawled' => 1]);

        $pendingUrls = $crawlJob->urls()->where('status', 'pending')->get();

        foreach ($pendingUrls as $crawlUrl) {
            CrawlPageJob::dispatch($crawlUrl);
        }

        if ($pendingUrls->isEmpty()) {
            $crawlJob->update(['status' => CrawlJobStatus::Completed, 'completed_at' => now()]);
            $this->audit->update(['status' => AuditStatus::Completed, 'completed_at' => now()]);
        }
    }
}
