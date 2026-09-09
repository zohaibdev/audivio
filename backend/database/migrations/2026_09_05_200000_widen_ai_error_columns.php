<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The same defect as §49, in the AI failure paths (§53).
 *
 * §49 widened the crawl and audit error columns but stopped there. Two
 * more columns had the identical mismatch — `string()` capped at
 * varchar(191) by `Builder::defaultStringLength(191)`, written by code
 * that truncates to 250:
 *
 *   AiAnalysisService     substr($e->getMessage(), 0, 250) -> ai_analyses.error
 *   ProviderResolver      substr($message, 0, 250)         -> api_providers.last_error
 *
 * This server runs STRICT_ALL_TABLES, so an over-length write throws
 * rather than truncating, and both writes sit inside the handler that is
 * recording a provider failure. A provider returning a long error message
 * would therefore have its real error replaced by a database exception —
 * the failure record destroyed by the act of writing it.
 *
 * Nothing indexes either column, so neither ever needed the 191-byte
 * index limit that shaped them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_analyses', function (Blueprint $table) {
            $table->text('error')->nullable()->change();
        });

        Schema::table('api_providers', function (Blueprint $table) {
            $table->text('last_error')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Cut explicitly before narrowing, for the reason §49 gives:
        // letting the column change do it would silently destroy the
        // messages this migration exists to keep. SUBSTR, not LEFT —
        // LEFT is MySQL-only and the rollback has to work everywhere.
        DB::table('ai_analyses')
            ->whereNotNull('error')
            ->update(['error' => DB::raw('SUBSTR(error, 1, 191)')]);

        DB::table('api_providers')
            ->whereNotNull('last_error')
            ->update(['last_error' => DB::raw('SUBSTR(last_error, 1, 191)')]);

        Schema::table('ai_analyses', function (Blueprint $table) {
            $table->string('error')->nullable()->change();
        });

        Schema::table('api_providers', function (Blueprint $table) {
            $table->string('last_error')->nullable()->change();
        });
    }
};
