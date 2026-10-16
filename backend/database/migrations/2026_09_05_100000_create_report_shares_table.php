<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Password-protected report shares (§50).
 *
 * A share is a capability handed to someone with no account: a long
 * random token in a URL, plus a password. Both are stored hashed, for
 * different reasons.
 *
 * **The token is hashed** (SHA-256) because it is a bearer credential. A
 * read of this table — a backup, a log, an over-broad admin query —
 * would otherwise hand over working links to every shared report in the
 * system. Hashed, the column is useless to anyone who did not already
 * have the URL. SHA-256 rather than bcrypt because lookup is by exact
 * match on every request and must stay a single indexed query; the token
 * carries 256 bits of entropy, so there is nothing to brute-force and
 * nothing for a slow hash to protect against.
 *
 * **The password is bcrypt**, because it is chosen by a human and is
 * therefore guessable. Opposite problem, opposite tool.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            // Who created it, kept even if the share outlives their
            // interest in it — the audit log needs an actor.
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();

            // SHA-256 hex of the token. Unique so a lookup is one indexed
            // query and a collision is impossible rather than merely
            // unlikely.
            $table->string('token_hash', 64)->unique();
            $table->string('password_hash');

            // Share permissions. Defaults are the conservative reading of
            // "share a report": the findings, not the artifacts.
            $table->boolean('allow_pdf')->default(false);
            $table->boolean('allow_screenshots')->default(true);
            $table->boolean('allow_recommendations')->default(true);

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            // Owner-facing status, not analytics: "has anyone opened
            // this?" is the question a person asks about a link they sent.
            $table->timestamp('last_viewed_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);

            $table->timestamps();

            // The owner's list of shares for one audit.
            $table->index(['audit_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_shares');
    }
};
