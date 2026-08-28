<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screenshots', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_page_id')->constrained()->cascadeOnDelete();
            $table->string('viewport'); // 'mobile' | 'desktop' — see App\Enums\ScreenshotViewport
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('storage_path');
            $table->timestamps();

            // One screenshot per page per viewport — capturing a second one
            // for the same (page, viewport) replaces it via updateOrCreate
            // rather than accumulating duplicates.
            $table->unique(['audit_page_id', 'viewport']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screenshots');
    }
};
