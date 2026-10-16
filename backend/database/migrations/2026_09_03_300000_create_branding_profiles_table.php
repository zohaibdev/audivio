<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * White-label branding for exported reports (§48).
 *
 * One row per user: this is the agency's own identity, not a per-audit or
 * per-website setting, and an agency that puts its logo on one report
 * wants it on all of them.
 *
 * The row exists independently of whether the user's plan currently
 * allows white-labelling. Branding is data the user entered; entitlement
 * is a question asked at render time. Deleting their logo because they
 * downgraded — and losing it if they upgrade again — would be destroying
 * their input to enforce a limit that has a much cheaper enforcement
 * point.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branding_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('brand_name')->nullable();
            // A path on the private disk, served through the same signed,
            // authorized route pattern as screenshots — a logo is not
            // secret, but a guessable path keyed by user id leaks which
            // accounts exist.
            $table->string('logo_path', 2048)->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->string('contact_website', 2048)->nullable();
            $table->text('footer_text')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branding_profiles');
    }
};
