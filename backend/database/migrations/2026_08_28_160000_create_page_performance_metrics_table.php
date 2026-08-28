<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per (page, source, strategy). The unique key is what
        // structurally prevents a mobile result being read as desktop or
        // one page's numbers being attributed to another: every metric
        // physically belongs to exactly one URL and one strategy.
        //
        // `source` distinguishes browser-measured data (our own Playwright
        // crawl) from Google PageSpeed/Lighthouse, so the report can always
        // say where a number came from rather than blending them.
        Schema::create('page_performance_metrics', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_page_id')->constrained()->cascadeOnDelete();
            $table->string('source');   // crawler | pagespeed
            $table->string('strategy'); // mobile | desktop

            // Lighthouse performance score, PageSpeed only. Nullable
            // because the crawler does not compute one — a missing score
            // must read as "not measured", never as 0.
            $table->unsignedTinyInteger('score')->nullable();

            // Core Web Vitals and supporting metrics. All nullable: a
            // metric the tool did not report stays null rather than being
            // defaulted to a number nobody measured.
            $table->unsignedInteger('lcp_ms')->nullable();
            $table->decimal('cls', 6, 4)->nullable();
            $table->unsignedInteger('tbt_ms')->nullable();
            $table->unsignedInteger('fcp_ms')->nullable();
            $table->unsignedInteger('ttfb_ms')->nullable();
            $table->unsignedInteger('speed_index_ms')->nullable();

            // Resource breakdown (crawler-measured).
            $table->unsignedInteger('total_requests')->nullable();
            $table->unsignedBigInteger('total_transfer_bytes')->nullable();
            $table->unsignedInteger('third_party_requests')->nullable();
            $table->unsignedBigInteger('third_party_bytes')->nullable();
            $table->unsignedInteger('dom_nodes')->nullable();
            $table->json('by_type')->nullable(); // {js:{requests,bytes}, css:{...}, ...}

            $table->timestamps();

            $table->unique(['audit_page_id', 'source', 'strategy']);
            $table->index(['audit_id', 'source', 'strategy']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_performance_metrics');
    }
};
