<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business onboarding context, custom AIO prompts, and competitor
 * records (§42).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            // The last onboarding field (§42). What this business wants to
            // be found for in AI search — the single most useful piece of
            // context for the AIO module, because "is this page clear to a
            // machine reader" is unanswerable without knowing what it is
            // meant to be clear *about*.
            $table->text('aio_goal')->nullable()->after('products_or_services');
        });

        Schema::table('plans', function (Blueprint $table) {
            // How many custom AIO prompts a website may define.
            //
            // Deliberately separate from `aio_prompt_limit`, which caps how
            // many AIO prompts one audit may *execute* — that is cost
            // control (§40), this is a product feature. Overloading one
            // column with both would make "raise the free tier's custom
            // prompts" also raise everyone's AI bill.
            $table->unsignedInteger('custom_aio_prompt_limit')->default(0)->after('aio_prompt_limit');
        });

        Schema::create('website_aio_prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->text('prompt');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['website_id', 'sort_order']);
        });

        Schema::create('website_competitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();

            $table->string('name')->nullable();
            $table->string('url')->nullable();
            $table->string('normalized_domain');

            // 'user' — named during onboarding. 'discovered' — suggested by
            // the discovery service. Kept apart so a discovery run can never
            // overwrite or silently drop what the user told us themselves.
            $table->string('source')->default('user');
            $table->text('reason')->nullable();

            // Populated by the comparison run. Null means "not analyzed
            // yet", which is not the same as a score of zero — a competitor
            // we could not reach must not read as a competitor doing badly.
            $table->unsignedTinyInteger('aio_score')->nullable();
            $table->unsignedInteger('pages_analyzed')->nullable();
            $table->json('topics')->nullable();
            $table->json('visibility')->nullable();
            $table->string('analysis_error')->nullable();
            $table->timestamp('analyzed_at')->nullable();

            $table->timestamps();

            // One row per competitor domain per website: re-running
            // discovery updates rather than duplicates.
            $table->unique(['website_id', 'normalized_domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_competitors');
        Schema::dropIfExists('website_aio_prompts');

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('custom_aio_prompt_limit');
        });

        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn('aio_goal');
        });
    }
};
