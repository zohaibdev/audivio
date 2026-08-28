<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Repairs screenshot rows written before CrawlOrchestrator::toPublicPath()
 * correctly normalized separators: those stored the full absolute
 * filesystem path instead of a path relative to the public disk, which
 * made every affected screenshot 403 when served from /storage.
 *
 * Data-only and idempotent — rows already relative are left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $base = rtrim(str_replace('\\', '/', Storage::disk(config('crawler.screenshot_disk'))->path('')), '/').'/';

        DB::table('screenshots')->orderBy('id')->chunkById(200, function ($rows) use ($base) {
            foreach ($rows as $row) {
                $normalized = str_replace('\\', '/', $row->storage_path);

                if (! str_starts_with($normalized, $base)) {
                    continue;
                }

                DB::table('screenshots')
                    ->where('id', $row->id)
                    ->update(['storage_path' => ltrim(substr($normalized, strlen($base)), '/')]);
            }
        });
    }

    public function down(): void
    {
        // Deliberately irreversible: the previous values were broken paths
        // that never resolved, so restoring them has no value and the
        // original absolute prefix is environment-specific anyway.
    }
};
