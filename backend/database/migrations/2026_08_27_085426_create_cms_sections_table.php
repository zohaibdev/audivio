<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_sections', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('cms_page_id')->constrained()->cascadeOnDelete();
            // e.g. hero, services, pricing, faq, testimonials, navigation,
            // footer, cta, seo — the spec's cms.sections list. Not an enum:
            // admins should be able to add new named sections without a
            // migration.
            $table->string('section_key');
            $table->json('content');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['cms_page_id', 'section_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_sections');
    }
};
