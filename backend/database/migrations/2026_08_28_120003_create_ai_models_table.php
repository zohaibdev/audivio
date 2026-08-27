<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catalog of individually addressable models per provider, distinct
        // from api_providers.model (the one model a provider is currently
        // actively calling). Admins register the models a provider exposes
        // here, then assign a subset of them to each plan via
        // plan_ai_models — see App\Services\AI\PlanAiModelResolver.
        Schema::create('ai_models', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('api_provider_id')->constrained('api_providers')->cascadeOnDelete();
            $table->string('model_id');
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->decimal('input_cost_per_1k_tokens', 10, 6)->nullable();
            $table->decimal('output_cost_per_1k_tokens', 10, 6)->nullable();
            $table->unsignedInteger('context_window')->nullable();
            $table->json('capabilities')->nullable();
            $table->timestamps();
            $table->unique(['api_provider_id', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_models');
    }
};
