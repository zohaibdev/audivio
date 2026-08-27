<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_pages', function (Blueprint $table) {
            $table->json('accessibility_violations')->nullable()->after('cta_elements');
            // Populated only for the page(s) PageSpeed Insights was run against
            // (homepage by default — see PerformanceAuditModule) since PSI is
            // a slow, rate-limited external call, not run per-page.
            $table->json('performance_metrics')->nullable()->after('accessibility_violations');
        });
    }

    public function down(): void
    {
        Schema::table('audit_pages', function (Blueprint $table) {
            $table->dropColumn(['accessibility_violations', 'performance_metrics']);
        });
    }
};
