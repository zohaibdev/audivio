<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_issues', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_page_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('category')->index();
            $table->string('severity')->index();
            $table->string('code');
            $table->string('message');
            $table->text('recommendation')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['audit_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_issues');
    }
};
