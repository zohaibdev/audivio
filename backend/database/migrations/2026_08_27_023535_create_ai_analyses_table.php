<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_analyses', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            $table->string('category')->index();
            $table->string('provider');
            $table->string('context_hash', 64);
            $table->json('context')->nullable();
            $table->text('prompt');
            $table->longText('response')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();

            // One cached call per (audit, category, exact context) — a
            // re-run of analysis on unchanged crawl data must hit this
            // instead of spending tokens again.
            $table->unique(['audit_id', 'category', 'context_hash'], 'ai_analyses_cache_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_analyses');
    }
};
