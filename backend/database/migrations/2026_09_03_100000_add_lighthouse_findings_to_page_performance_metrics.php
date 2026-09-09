<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lighthouse's findings, the real-user field data, and the caching and
 * compression summaries (§45).
 *
 * These hang off `page_performance_metrics` rather than `audit_pages`
 * because they are **per strategy**: mobile and desktop produce different
 * opportunities with different savings, and the table's
 * `(audit_page_id, source, strategy)` unique key is what already
 * guarantees a desktop number can never be read as a mobile one. Putting
 * them on the page would collapse that distinction, which is exactly the
 * mistake this schema was shaped to prevent.
 *
 * All nullable: an audit whose PageSpeed run never happened has no
 * findings, which is not the same as having none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_performance_metrics', function (Blueprint $table) {
            // Failing audits Lighthouse attached a measured saving to.
            $table->json('opportunities')->nullable()->after('by_type');
            // Failing audits it did not — real problems with no number.
            $table->json('diagnostics')->nullable()->after('opportunities');

            // CrUX: what real visitors experienced. The only source of
            // INP, which no lab run can produce.
            $table->json('field_metrics')->nullable()->after('diagnostics');

            // Crawler-measured, from response headers.
            $table->json('caching')->nullable()->after('field_metrics');
            $table->json('compression')->nullable()->after('caching');
        });
    }

    public function down(): void
    {
        Schema::table('page_performance_metrics', function (Blueprint $table) {
            $table->dropColumn([
                'opportunities',
                'diagnostics',
                'field_metrics',
                'caching',
                'compression',
            ]);
        });
    }
};
