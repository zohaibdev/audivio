<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a service was skipped, in the module's own words (§46).
 *
 * `AuditModuleResult` has carried `notApplicableReason` and
 * `detectionEvidence` since §34, and nothing ever stored them: the report
 * substituted one generic sentence for every skipped service. So a
 * classifier that works out a site is a blog, and says so in terms its
 * owner would recognise, had that explanation discarded on the way to the
 * page it was written for.
 *
 * Nullable: an applicable service has nothing to explain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_scores', function (Blueprint $table) {
            $table->text('not_applicable_reason')->nullable()->after('is_applicable');
            // The signals behind the decision. A service that silently
            // vanishes from a report has to be able to show its working.
            $table->json('detection_evidence')->nullable()->after('not_applicable_reason');
        });
    }

    public function down(): void
    {
        Schema::table('audit_scores', function (Blueprint $table) {
            $table->dropColumn(['not_applicable_reason', 'detection_evidence']);
        });
    }
};
