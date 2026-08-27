<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_pages', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crawl_url_id')->nullable()->constrained()->nullOnDelete();
            $table->string('url', 2048);
            $table->unsignedInteger('http_status')->nullable();
            $table->string('title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('canonical_url', 2048)->nullable();
            $table->json('redirect_chain')->nullable();
            $table->json('headings')->nullable();
            $table->json('links')->nullable();
            $table->json('images')->nullable();
            $table->json('forms')->nullable();
            $table->json('structured_data')->nullable();
            $table->json('cta_elements')->nullable();
            $table->string('screenshot_path')->nullable();
            $table->unsignedBigInteger('html_size_bytes')->nullable();
            $table->unsignedInteger('load_time_ms')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamp('crawled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_pages');
    }
};
