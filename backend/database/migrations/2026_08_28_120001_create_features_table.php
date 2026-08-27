<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Centralized audit-feature registry (performance, accessibility,
        // cro, seo, aio, ecommerce, product_auditor) — distinct from
        // plans.features (a JSON column of unrelated boolean perks:
        // pdf_export, scheduled_audits, audit_history). Rows correspond to
        // real AuditCategory values wired in App\Services\Plan\FeatureAccessResolver,
        // so this registry is seeded once, not admin-creatable at runtime.
        Schema::create('features', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('features');
    }
};
