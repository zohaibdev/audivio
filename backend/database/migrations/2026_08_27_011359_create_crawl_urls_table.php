<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawl_urls', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('crawl_job_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('http_status')->nullable();
            $table->unsignedInteger('depth')->default(0);
            $table->string('discovered_via')->default('link');
            $table->string('error_message')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamps();

            $table->index(['crawl_job_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_urls');
    }
};
