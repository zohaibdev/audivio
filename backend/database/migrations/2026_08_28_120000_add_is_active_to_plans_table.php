<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // Distinct from is_default: a retired plan can stay is_active=false
            // (hidden from /api/plans, blocked from new subscriptions) while
            // existing subscribers keep it, without deleting historical data.
            $table->boolean('is_active')->default(true)->after('is_default');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
