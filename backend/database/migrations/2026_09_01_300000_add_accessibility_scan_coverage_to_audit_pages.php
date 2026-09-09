<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the accessibility scan *passed* and what it could not decide (§43).
 *
 * Violations alone cannot answer "how many checks passed" — a page with no
 * violations is indistinguishable from a page axe never managed to scan,
 * and reporting the first as a clean result when it was really the second
 * would be the worst kind of wrong answer here.
 *
 * Both are nullable and both mean "not recorded", which is exactly what
 * they are for every audit that ran before this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_pages', function (Blueprint $table) {
            $table->json('accessibility_passes')->nullable()->after('accessibility_violations');
            // axe's "incomplete" results: rules it ran but could not decide
            // automatically. Kept apart from passes on purpose — "needs a
            // human to check" is not "fine".
            $table->json('accessibility_incomplete')->nullable()->after('accessibility_passes');
        });
    }

    public function down(): void
    {
        Schema::table('audit_pages', function (Blueprint $table) {
            $table->dropColumn(['accessibility_passes', 'accessibility_incomplete']);
        });
    }
};
