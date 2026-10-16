<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Error messages are prose, not keys (§49).
 *
 * These were `string()` columns, which `Builder::defaultStringLength(191)`
 * caps at varchar(191) so utf8mb4 indexes fit MySQL 5.7's 767-byte limit.
 * Nothing indexes an error message, so they inherited a constraint that
 * exists for a completely unrelated reason — and the code writing them
 * truncated to 250, comfortably past the width.
 *
 * The consequence was worse than a lost message: the write that *records*
 * a failure threw, so the job recording it died, and the audit was left
 * stranded in `crawling` holding its owner's concurrent slot forever —
 * exactly the state §38's lifecycle exists to make impossible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audits', function (Blueprint $table) {
            $table->text('error_message')->nullable()->change();
        });

        Schema::table('crawl_urls', function (Blueprint $table) {
            $table->text('error_message')->nullable()->change();
        });

        Schema::table('crawl_jobs', function (Blueprint $table) {
            $table->text('error_message')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Truncating on the way down would silently destroy the very
        // messages this migration exists to preserve, so anything longer
        // than the old width is cut explicitly first.
        foreach (['audits', 'crawl_urls', 'crawl_jobs'] as $table) {
            DB::table($table)
                ->whereNotNull('error_message')
                // SUBSTR, not LEFT: LEFT is MySQL-only, and the rollback
                // test runs on SQLite — which is exactly where a one-way
                // migration goes unnoticed until someone needs it.
                ->update(['error_message' => DB::raw('SUBSTR(error_message, 1, 191)')]);
        }

        Schema::table('audits', function (Blueprint $table) {
            $table->string('error_message')->nullable()->change();
        });

        Schema::table('crawl_urls', function (Blueprint $table) {
            $table->string('error_message')->nullable()->change();
        });

        Schema::table('crawl_jobs', function (Blueprint $table) {
            $table->string('error_message')->nullable()->change();
        });
    }
};
