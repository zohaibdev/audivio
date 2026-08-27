<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_usage_logs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            // Plain string, not a FK to api_providers — mirrors this
            // codebase's existing convention for small fixed vocabularies
            // (audit_scores.category, usage_logs.action) and means a usage
            // log's identity survives independently of the provider row.
            $table->string('provider')->index();
            $table->string('service');
            $table->string('request_type')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('audit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model')->nullable();
            $table->boolean('success');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->decimal('estimated_cost', 10, 6)->nullable();
            $table->string('error_category')->nullable();
            $table->timestamps();

            $table->index(['provider', 'created_at']);
            $table->index(['success', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_usage_logs');
    }
};
