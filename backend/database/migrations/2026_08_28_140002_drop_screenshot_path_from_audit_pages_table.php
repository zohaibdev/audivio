<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Superseded by the screenshots table (one page now has up to two
        // rows — mobile and desktop — instead of a single path column).
        // Nothing reads this column yet (verified before dropping it), so
        // there's no data migration to perform.
        Schema::table('audit_pages', function (Blueprint $table) {
            $table->dropColumn('screenshot_path');
        });
    }

    public function down(): void
    {
        Schema::table('audit_pages', function (Blueprint $table) {
            $table->string('screenshot_path')->nullable();
        });
    }
};
