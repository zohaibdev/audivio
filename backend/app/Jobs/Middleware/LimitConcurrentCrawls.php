<?php

namespace App\Jobs\Middleware;

use App\Enums\CrawlJobStatus;
use App\Models\CrawlJob;

/**
 * Caps how many audits may be crawling at the same time.
 *
 * The `crawler` rate limiter bounds how *fast* pages are fetched; it says
 * nothing about how many crawls run at once. Without this, a burst of
 * submissions starts a Chromium subprocess per audit simultaneously and
 * the box runs out of memory long before any rate limit is reached.
 *
 * Counting `crawl_jobs` rows rather than using a Redis semaphore keeps
 * this working on every queue driver the app supports, at the cost of one
 * cheap indexed count per job start.
 */
class LimitConcurrentCrawls
{
    public function handle(object $job, \Closure $next): mixed
    {
        $limit = (int) config('crawler.max_concurrent_crawls');

        if ($limit <= 0) {
            return $next($job);
        }

        $running = CrawlJob::where('status', CrawlJobStatus::Running)->count();

        if ($running >= $limit) {
            // Released rather than failed: the audit is legitimate and
            // nothing is wrong with it — it just arrived while the box was
            // full, so it waits for a slot. The delay is deliberately
            // longer than a page fetch so a released job doesn't spin.
            return $job->release(30);
        }

        return $next($job);
    }
}
