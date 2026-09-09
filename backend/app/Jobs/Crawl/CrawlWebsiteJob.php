<?php

namespace App\Jobs\Crawl;

use App\Enums\CrawlJobStatus;
use App\Jobs\Middleware\LimitConcurrentCrawls;
use App\Models\Audit;
use App\Services\Audit\AuditLifecycle;
use App\Services\Audit\AuditModuleRegistry;
use App\Services\Crawler\CrawlOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

class CrawlWebsiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly Audit $audit) {}

    public function middleware(): array
    {
        return [new LimitConcurrentCrawls, new RateLimited('crawler')];
    }

    public function handle(CrawlOrchestrator $orchestrator, AuditLifecycle $lifecycle): void
    {
        // A cancellation cannot reach into a running job and stop it, so
        // every job asks at its own start. This is the first checkpoint.
        if ($lifecycle->shouldStop($this->audit)) {
            return;
        }

        // Validating is a real stage, not decoration: the orchestrator
        // re-runs the SSRF checks before touching the network (§26), and a
        // user watching the status should see that rather than an
        // unexplained pause in "queued".
        $lifecycle->markValidating($this->audit);
        $lifecycle->markCrawling($this->audit);

        try {
            $crawlJob = $orchestrator->startCrawl($this->audit);
        } catch (\Throwable $e) {
            $lifecycle->markFailed($this->audit, $e->getMessage(), 'crawling');

            return;
        }

        $this->audit->update(['pages_crawled' => 1]);
        $lifecycle->touch($this->audit);

        $pendingUrls = $crawlJob->urls()->where('status', 'pending')->get();

        foreach ($pendingUrls as $crawlUrl) {
            CrawlPageJob::dispatch($crawlUrl);
        }

        if ($pendingUrls->isEmpty()) {
            $crawlJob->update(['status' => CrawlJobStatus::Completed, 'completed_at' => now()]);
            AuditModuleRegistry::dispatchAnalysis($this->audit);
        }
    }

    /**
     * The queue gave up on this job — `tries` exhausted, the job timed
     * out, or the worker died mid-run. Without this the audit sits in
     * `crawling` forever holding a concurrent slot nothing will ever
     * release, which is exactly the stuck-audit class this phase closes.
     */
    public function failed(?\Throwable $e): void
    {
        app(AuditLifecycle::class)->markFailed(
            $this->audit,
            $e?->getMessage() ?? 'The crawl job failed without reporting a reason.',
            'crawling',
        );
    }
}
