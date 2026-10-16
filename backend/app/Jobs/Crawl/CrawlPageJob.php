<?php

namespace App\Jobs\Crawl;

use App\Enums\CrawlJobStatus;
use App\Enums\CrawlUrlStatus;
use App\Models\CrawlJob;
use App\Models\CrawlUrl;
use App\Services\Audit\AuditLifecycle;
use App\Services\Audit\AuditModuleRegistry;
use App\Services\Crawler\CrawlOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CrawlPageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly CrawlUrl $crawlUrl) {}

    public function middleware(): array
    {
        return [new RateLimited('crawler')];
    }

    public function handle(CrawlOrchestrator $orchestrator, AuditLifecycle $lifecycle): void
    {
        $crawlJob = $this->crawlUrl->crawlJob;
        $audit = $crawlJob->audit;

        // Cancelled (or already failed) while this page sat in the queue.
        // Stopping here is what makes cancellation actually cheap: the
        // remaining pages are simply never fetched.
        if ($lifecycle->shouldStop($audit)) {
            return;
        }

        if ($this->hasExceededCrawlTimeout($crawlJob)) {
            $this->abandon($crawlJob, $audit);

            return;
        }

        $newUrls = $orchestrator->crawlDiscoveredUrl($this->crawlUrl);

        $audit->increment('pages_crawled');
        $lifecycle->touch($audit);

        foreach ($newUrls as $newUrl) {
            self::dispatch($newUrl);
        }

        $this->finalizeIfComplete($crawlJob->fresh(), $audit);
    }

    /**
     * A crawl is bounded by wall-clock time as well as by page count. Page
     * and request timeouts only bound one fetch each — a site that answers
     * every request slowly but successfully, or that keeps yielding new
     * links, can hold a queue worker for hours without either firing.
     */
    private function hasExceededCrawlTimeout($crawlJob): bool
    {
        $timeout = (int) config('crawler.crawl_timeout');

        if ($timeout <= 0 || $crawlJob->started_at === null) {
            return false;
        }

        return $crawlJob->started_at->addSeconds($timeout)->isPast();
    }

    /**
     * Stop crawling but still analyze. The pages already fetched are valid
     * findings, so the audit is completed from what was gathered rather
     * than thrown away — the alternative is a user who waited out the
     * timeout getting nothing at all.
     */
    private function abandon($crawlJob, $audit): void
    {
        // Every page job still queued for this crawl will reach the same
        // conclusion, so the transition out of Running is made
        // conditionally: whichever job wins does the finalizing, the rest
        // see zero rows affected and stop. Without this, each of them
        // would dispatch a second round of analysis.
        $claimed = CrawlJob::where('id', $crawlJob->id)
            ->where('status', CrawlJobStatus::Running)
            ->update(['status' => CrawlJobStatus::Completed, 'completed_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        Log::channel('crawler')->warning('Crawl abandoned: exceeded crawl_timeout', [
            'audit_id' => $audit->id,
            'crawl_job_id' => $crawlJob->id,
            'crawl_timeout' => config('crawler.crawl_timeout'),
        ]);

        $crawlJob->urls()
            ->whereIn('status', [CrawlUrlStatus::Pending, CrawlUrlStatus::Crawling])
            ->update(['status' => CrawlUrlStatus::Skipped]);

        AuditModuleRegistry::dispatchAnalysis($audit);
    }

    private function finalizeIfComplete($crawlJob, $audit): void
    {
        $stillPending = $crawlJob->urls()
            ->whereIn('status', [CrawlUrlStatus::Pending, CrawlUrlStatus::Crawling])
            ->exists();

        if ($stillPending) {
            return;
        }

        // Claimed conditionally, exactly as abandon() does. Two workers
        // finishing the last two pages at the same moment both see
        // "nothing pending" — and with an unconditional update both went
        // on to dispatch a full round of module jobs, so every audit
        // module ran twice and wrote its issues twice. Whichever job wins
        // the transition out of Running does the finalizing; the rest see
        // zero rows affected and stop.
        $claimed = CrawlJob::where('id', $crawlJob->id)
            ->where('status', CrawlJobStatus::Running)
            ->update(['status' => CrawlJobStatus::Completed, 'completed_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        AuditModuleRegistry::dispatchAnalysis($audit);
    }

    /**
     * One page failing is not the audit failing — the crawl continues with
     * the pages that did work (CrawlOrchestrator already records the
     * per-URL failure). But if this was the *last* outstanding page, the
     * pipeline would otherwise stall here with no job left to finalize the
     * crawl, so completion is re-checked on the way out.
     */
    public function failed(?\Throwable $e): void
    {
        $crawlJob = $this->crawlUrl->crawlJob;

        if ($crawlJob === null) {
            return;
        }

        $this->crawlUrl->update(['status' => CrawlUrlStatus::Failed]);

        $this->finalizeIfComplete($crawlJob->fresh(), $crawlJob->audit);
    }
}
