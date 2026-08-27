<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('audit_id')->unique()->constrained()->cascadeOnDelete();
            // Always the FULL report content, regardless of who eventually
            // views it — visibility (free/anonymous vs full) is applied at
            // read time by ReportVisibilityFilter, never baked in here, so
            // an audit owner who later upgrades sees the full version of
            // the same stored report without regenerating it.
            $table->json('content');
            $table->timestamp('generated_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
