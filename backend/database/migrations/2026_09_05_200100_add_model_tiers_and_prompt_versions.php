<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Model tiers and prompt-version attribution (§53).
 *
 * `ai_models.tier` says what a model is fit for, so a simple extraction
 * can be served by a cheap model while open-ended reasoning still reaches
 * a strong one. Everything defaults to `standard`: an unclassified model
 * must not silently become the cheap option for work that needed better.
 *
 * `prompt_version` on both AI records answers a question that could not be
 * answered before: which prompt produced this result, and what did that
 * version cost. `audit_snapshots` already recorded a version per audit
 * (§38), but an audit spans many calls and a cost report is per call.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_models', function (Blueprint $table) {
            $table->string('tier', 20)->default('standard')->after('display_name');
        });

        Schema::table('ai_analyses', function (Blueprint $table) {
            // Nullable, and deliberately not backfilled: rows written
            // before this migration were produced by a prompt version
            // nobody recorded, and inventing one would make guesses
            // indistinguishable from measurements.
            $table->string('prompt_version', 50)->nullable()->after('provider');
            $table->string('model')->nullable()->after('provider');
        });

        Schema::table('api_usage_logs', function (Blueprint $table) {
            $table->string('prompt_version', 50)->nullable()->after('model');
        });

        Schema::table('api_usage_logs', function (Blueprint $table) {
            // The model usage chart groups by this, over a date range.
            $table->index(['model', 'created_at'], 'api_usage_logs_model_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('api_usage_logs', function (Blueprint $table) {
            $table->dropIndex('api_usage_logs_model_created_at_index');
            $table->dropColumn('prompt_version');
        });

        Schema::table('ai_analyses', function (Blueprint $table) {
            $table->dropColumn(['prompt_version', 'model']);
        });

        Schema::table('ai_models', function (Blueprint $table) {
            $table->dropColumn('tier');
        });
    }
};
