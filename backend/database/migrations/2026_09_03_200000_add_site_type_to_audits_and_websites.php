<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What kind of site each audit concluded it was looking at (§46).
 *
 * Stored on the **audit**, not only the website, because it decides which
 * services that audit reported at all. A site that adds a shop next month
 * must not retroactively change what last month's report says it skipped
 * and why.
 *
 * Mirrored onto `websites` as the latest known type, purely so listings
 * and cards can show it without loading an audit.
 *
 * Nullable throughout: audits that ran before this was captured were
 * never classified, which is not the same as being unclassifiable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audits', function (Blueprint $table) {
            $table->string('site_type', 40)->nullable()->after('status');
            $table->unsignedTinyInteger('site_type_confidence')->nullable()->after('site_type');
            // The evidence behind the decision. A classification that
            // silences a whole service has to be able to show its working.
            $table->json('site_type_signals')->nullable()->after('site_type_confidence');
        });

        Schema::table('websites', function (Blueprint $table) {
            $table->string('site_type', 40)->nullable()->after('platform');
        });
    }

    public function down(): void
    {
        Schema::table('audits', function (Blueprint $table) {
            $table->dropColumn(['site_type', 'site_type_confidence', 'site_type_signals']);
        });

        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn('site_type');
        });
    }
};
