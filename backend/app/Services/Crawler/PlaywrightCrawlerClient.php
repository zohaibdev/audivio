<?php

namespace App\Services\Crawler;

use App\DTOs\CrawledPageData;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Bridge to the Node/Playwright crawler in the sibling `crawler/` directory.
 * PHP has no mature Playwright binding, so each page fetch is run as a
 * subprocess that prints one JSON object to stdout.
 */
class PlaywrightCrawlerClient
{
    public function crawl(string $url, ?string $screenshotAbsoluteDir = null): CrawledPageData
    {
        $args = [
            config('crawler.node_binary'),
            config('crawler.script_path'),
            "--url={$url}",
            '--timeout='.config('crawler.page_timeout'),
            '--max-file-size-mb='.config('crawler.max_file_size_mb'),
            '--user-agent='.config('crawler.user_agent'),
        ];

        if ($screenshotAbsoluteDir !== null) {
            $args[] = "--screenshot-dir={$screenshotAbsoluteDir}";
        }

        $process = new Process($args, timeout: config('crawler.page_timeout') + 15);
        $process->run();

        $output = trim($process->getOutput());
        $decoded = $output !== '' ? json_decode($output, true) : null;

        if (! $process->isSuccessful()) {
            $message = $decoded['error'] ?? $process->getErrorOutput() ?: 'Unknown crawler subprocess failure';

            Log::channel('crawler')->warning('Page crawl failed', [
                'url' => $url,
                'exit_code' => $process->getExitCode(),
                'error' => $message,
            ]);

            throw new CrawlerException("Failed to crawl {$url}: {$message}");
        }

        if (! is_array($decoded) || isset($decoded['error'])) {
            $message = $decoded['error'] ?? 'Crawler returned unparseable output';

            Log::channel('crawler')->warning('Page crawl returned error payload', [
                'url' => $url,
                'error' => $message,
            ]);

            throw new CrawlerException("Failed to crawl {$url}: {$message}");
        }

        return CrawledPageData::fromArray($decoded);
    }
}
