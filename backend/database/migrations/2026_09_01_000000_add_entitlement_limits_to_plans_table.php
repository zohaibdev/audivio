<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The numeric half of a plan's entitlements (§40). The on/off half stays
 * in `plans.features` (JSON), keyed by App\Enums\PlanFeature.
 *
 * Nullable means *unlimited* throughout, not "unset". A zero would be a
 * real limit of none, and the two have to stay tellable apart: a plan with
 * `monthly_audit_limit = 0` grants no audits at all, while `null` grants
 * as many as the daily and concurrent limits allow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // How many website records a subscriber may own. Anonymous
            // website rows are internal bookkeeping and are not counted —
            // guests have no website listing to cap (see EntitlementResolver).
            $table->unsignedInteger('max_websites')->default(1)->after('max_pages');

            // Volume over a rolling calendar month, on top of the daily
            // cap. Null = no monthly ceiling of its own.
            $table->unsignedInteger('monthly_audit_limit')->nullable()->after('daily_audit_limit');

            // The most frequent recurring schedule this plan may pick.
            // Stored as a ScheduleFrequency value; a plan set to 'weekly'
            // refuses a daily schedule.
            $table->string('max_audit_frequency')->default('monthly')->after('full_recrawls');

            // How much of a report a viewer without full access sees, as a
            // percentage of the findings. 100 means the free view is the
            // full view; the split is still field-based underneath (§20),
            // this scales how many issues and recommendations survive it.
            $table->unsignedTinyInteger('report_visibility_percent')->default(100)->after('max_audit_frequency');

            // Cap on issues shown per service in a restricted view. Null =
            // every issue the percentage allows.
            $table->unsignedInteger('issues_per_service')->nullable()->after('report_visibility_percent');

            // AI budget. `ai_analyses_per_audit` bounds total AI calls for
            // one audit; `aio_prompt_limit` bounds the AIO module's own
            // prompts within that. Null = unlimited (still subject to the
            // provider's own configured request limits, §23).
            $table->unsignedInteger('ai_analyses_per_audit')->nullable()->after('issues_per_service');
            $table->unsignedInteger('aio_prompt_limit')->nullable()->after('ai_analyses_per_audit');
        });

        // The one seeded plan is the free default; give it the free-tier
        // shape explicitly rather than relying on the column defaults.
        //
        // Those defaults are deliberately *conservative* — one website,
        // monthly scheduling at most — so a plan an admin creates without
        // filling every field cannot accidentally give away the expensive
        // capacity. Getting a limit wrong in the restrictive direction
        // shows up as a support question; the permissive direction shows
        // up as a bill.
        DB::table('plans')->where('is_default', true)->update([
            'max_websites' => 1,
            'monthly_audit_limit' => 5,
            'max_audit_frequency' => 'monthly',
            // 100, not the guest tier's 50: an owner seeing their whole
            // report is existing behaviour ("Sign up to unlock the full
            // report"), and this migration adds the control rather than
            // re-pricing the product. An admin can lower it per plan.
            'report_visibility_percent' => 100,
            'issues_per_service' => null,
        ]);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'max_websites',
                'monthly_audit_limit',
                'max_audit_frequency',
                'report_visibility_percent',
                'issues_per_service',
                'ai_analyses_per_audit',
                'aio_prompt_limit',
            ]);
        });
    }
};
