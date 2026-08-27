<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_scores', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            $table->string('category')->index(); // one of the AuditCategory enum values, or 'overall'
            $table->unsignedTinyInteger('score');
            $table->json('breakdown')->nullable(); // per-rule point deductions, for transparency
            $table->timestamps();

            $table->unique(['audit_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_scores');
    }
};
