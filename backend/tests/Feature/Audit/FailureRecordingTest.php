<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditStatus;
use App\Models\Audit;
use App\Models\Website;
use App\Services\Audit\AuditLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The failure path must never itself be able to fail (§49).
 *
 * `audits.error_message` was `varchar(191)` — a width inherited from
 * `Builder::defaultStringLength(191)`, which exists so utf8mb4 *indexes*
 * fit MySQL 5.7's key limit and has nothing to do with prose. The code
 * writing it truncated to 250.
 *
 * So any failure whose message ran past 191 characters made the write
 * that *records* the failure throw. The job recording it then died, and
 * the audit was left stranded in an active state holding its owner's
 * concurrent slot forever — exactly the condition §38's lifecycle exists
 * to make impossible, defeated by a column width.
 *
 * Observed in production on audit #25: a `CrawlWebsiteJob` failed, could
 * not say why, and left the audit in `crawling` indefinitely.
 */
class FailureRecordingTest extends TestCase
{
    use RefreshDatabase;

    private function makeAudit(): Audit
    {
        $website = Website::create([
            'name' => 'example.com', 'primary_url' => 'https://example.com/',
            'domain' => 'example.com', 'normalized_domain' => 'example.com',
            'platform' => 'unknown',
        ]);

        return Audit::create([
            'website_id' => $website->id, 'status' => AuditStatus::Crawling, 'max_pages' => 5,
        ]);
    }

    public function test_a_long_failure_message_still_terminates_the_audit(): void
    {
        $audit = $this->makeAudit();

        // The shape of a real crawler failure: a Symfony process error
        // carries the whole command line, which is well past 191
        // characters before the actual reason even begins.
        $message = 'The command "'.str_repeat('C:\\path\\to\\node.exe --url=https://example.com ', 8).'" failed.';

        $this->assertGreaterThan(191, strlen($message));

        $moved = app(AuditLifecycle::class)->markFailed($audit, $message, 'crawling');

        $this->assertTrue($moved);
        $this->assertSame(AuditStatus::Failed, $audit->fresh()->status);
        $this->assertSame('crawling', $audit->fresh()->failed_stage);
        $this->assertNotNull($audit->fresh()->terminated_at, 'a failed audit must release its slot');
    }

    public function test_the_message_is_kept_rather_than_cut_to_the_old_width(): void
    {
        $audit = $this->makeAudit();
        $message = str_repeat('Navigation failed: net::ERR_CONNECTION_TIMED_OUT at https://example.com/. ', 5);

        app(AuditLifecycle::class)->markFailed($audit, $message, 'crawling');

        // Enough of it to diagnose from, which 191 characters was not.
        $this->assertGreaterThan(191, strlen((string) $audit->fresh()->error_message));
    }

    public function test_a_pathological_message_is_bounded(): void
    {
        $audit = $this->makeAudit();

        app(AuditLifecycle::class)->markFailed($audit, str_repeat('x', 50_000), 'crawling');

        // Bounded because a 50KB stack trace helps nobody reading a
        // report — an editorial limit now, not a structural one.
        $this->assertLessThanOrEqual(
            AuditLifecycle::MAX_ERROR_MESSAGE + 3,
            strlen((string) $audit->fresh()->error_message),
        );
        $this->assertSame(AuditStatus::Failed, $audit->fresh()->status);
    }

    public function test_every_error_message_column_is_text_not_a_narrow_string(): void
    {
        // The guard that actually matters, and the one this suite cannot
        // otherwise provide: SQLite ignores varchar lengths entirely, so
        // the tests above pass just as happily against the broken
        // varchar(191) schema that caused the outage. Asserting the
        // declared *type* is what catches a revert to `string()`.
        foreach (['audits', 'crawl_urls', 'crawl_jobs'] as $table) {
            $this->assertSame(
                'text',
                Schema::getColumnType($table, 'error_message'),
                "{$table}.error_message must be TEXT — a 191-character cap on a failure message makes the write that records a failure throw",
            );
        }
    }
}
