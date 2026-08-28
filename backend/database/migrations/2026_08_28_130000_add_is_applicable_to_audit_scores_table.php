<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_scores', function (Blueprint $table) {
            // A category a module determined doesn't apply to this site
            // (e.g. Ecommerce on a site with no commerce signals) must show
            // as "Not applicable," never a fabricated number — score stays
            // nullable and is_applicable=false rather than inventing 0/100.
            $table->unsignedTinyInteger('score')->nullable()->change();
            $table->boolean('is_applicable')->default(true)->after('score');
        });
    }

    public function down(): void
    {
        Schema::table('audit_scores', function (Blueprint $table) {
            $table->dropColumn('is_applicable');
            $table->unsignedTinyInteger('score')->nullable(false)->change();
        });
    }
};
