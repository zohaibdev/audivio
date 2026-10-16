<?php

use App\Enums\AuditStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widens the audit lifecycle (queued/validating/generating_report/
 * cancelled) and gives plans a concurrent-audit limit.
 *
 * The `pending` → `queued` rename is a data migration, not just a naming
 * preference: "pending" said nothing about *what* it was pending on, and
 * the new lifecycle needs a name for "accepted, waiting for a worker"
 * that is distinct from "a worker has it and is validating".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // How many audits this plan's subscribers may have running at
            // once. Distinct from `daily_audit_limit`, which caps volume
            // over a day; this caps what is in flight at any instant, and
            // is what stops one account queueing a thousand crawls.
            $table->unsignedInteger('concurrent_audit_limit')->default(1)->after('daily_audit_limit');
        });

        Schema::table('audits', function (Blueprint $table) {
            // Which stage was running when the audit failed or was
            // cancelled. `error_message` says what went wrong; without
            // this there is no structured way to ask "are we failing in
            // the crawler or in the analysis?".
            $table->string('failed_stage')->nullable()->after('error_message');

            // Set when the audit reaches any terminal state, including
            // cancellation. `completed_at` keeps its narrower meaning of
            // "finished successfully", so a report can go on trusting it.
            $table->timestamp('terminated_at')->nullable()->after('completed_at');

            // Bumped every time a worker makes progress, so stuck-audit
            // recovery can distinguish "slow but alive" from "abandoned"
            // without depending on `updated_at`, which any unrelated write
            // to the row would refresh.
            $table->timestamp('progress_at')->nullable()->after('terminated_at');
        });

        DB::table('audits')->where('status', 'pending')->update([
            'status' => AuditStatus::Queued->value,
        ]);

        // Existing finished audits get a terminal timestamp so recovery
        // and slot accounting see a consistent picture from day one.
        DB::table('audits')
            ->whereIn('status', [AuditStatus::Completed->value, AuditStatus::Failed->value])
            ->whereNull('terminated_at')
            ->update(['terminated_at' => DB::raw('COALESCE(completed_at, updated_at)')]);

        Schema::create('audit_snapshots', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('audit_id')->unique()->constrained()->cascadeOnDelete();

            // Denormalized on purpose: a snapshot has to keep meaning even
            // after the website is renamed, re-audited, or deleted. These
            // are what the audit saw, not what is true now.
            $table->string('website_url');
            $table->string('domain');

            // The versions of the moving parts that produced this result.
            // Without them a two-month-old score cannot be compared to
            // today's — a change in scoring weights is indistinguishable
            // from a change in the site.
            $table->string('crawler_version');
            $table->string('scoring_version');
            $table->string('prompt_version');
            $table->string('ai_provider')->nullable();
            $table->string('ai_model')->nullable();

            $table->unsignedInteger('pages_analyzed')->default(0);
            $table->timestamp('audited_at');

            // The full immutable result: scores, service results, issues,
            // recommendations, screenshots, performance data.
            $table->json('content');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_snapshots');

        DB::table('audits')->whereIn('status', [
            AuditStatus::Queued->value,
            AuditStatus::Validating->value,
        ])->update(['status' => 'pending']);

        DB::table('audits')->where('status', AuditStatus::GeneratingReport->value)
            ->update(['status' => AuditStatus::Analyzing->value]);

        DB::table('audits')->where('status', AuditStatus::Cancelled->value)
            ->update(['status' => AuditStatus::Failed->value]);

        Schema::table('audits', function (Blueprint $table) {
            $table->dropColumn(['failed_stage', 'terminated_at', 'progress_at']);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('concurrent_audit_limit');
        });
    }
};
