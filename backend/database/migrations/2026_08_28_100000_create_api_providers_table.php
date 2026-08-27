<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_providers', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('provider_type');
            $table->boolean('is_enabled')->default(false);
            // Encrypted at rest (Eloquent's `encrypted` cast, AES-256-CBC via
            // APP_KEY) — never returned by any API response (see
            // ApiProvider::$hidden); only a masked preview is ever exposed.
            $table->text('api_key')->nullable();
            $table->string('model')->nullable();
            // Per-provider settings that don't apply uniformly across
            // providers (temperature, mobile/desktop toggles, limits, cost
            // rates) live here rather than as dedicated nullable columns —
            // keeps the schema provider-agnostic so a future provider needs
            // no migration, just a new config shape.
            $table->json('config')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_providers');
    }
};
