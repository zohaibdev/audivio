<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happened to a share, and who did it (§50).
 *
 * The owner handed a link to someone outside the system, so the only
 * account of what that link did is the one kept here: opened, downloaded,
 * failed the password. Failed attempts matter most — a run of them is the
 * signal that a link has reached someone it was not meant for, and
 * without a record the owner has no way to know.
 *
 * `report_share_id` is deliberately **nullable** so a revoked-and-deleted
 * share does not take its own history with it. The events are the audit
 * trail; deleting the subject must not erase the record of what it did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_share_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_share_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('audit_id')->nullable()->constrained()->nullOnDelete();

            $table->string('event', 40)->index();

            // Recorded for failed-attempt forensics, and truncated: a
            // user agent is attacker-controlled and unbounded.
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('context')->nullable();

            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_share_events');
    }
};
