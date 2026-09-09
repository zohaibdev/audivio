<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The sales workflow a consultation request feeds (§55).
 *
 * `leads` already carried a name, email, message, audit and a free-text
 * `website_url`. Three things it could not answer, all of which a
 * salesperson needs before the first call:
 *
 * - *Which website of ours is this?* `website_url` is a string somebody
 *   typed. It cannot be joined, so a lead could not be listed against the
 *   site it concerns.
 * - *What did they see?* The findings that prompted the request.
 * - *What do they want help with?* The services they picked.
 *
 * The pipeline also gains `proposal`, and `converted` becomes `won` — a
 * stage name, matching `lost`, rather than a metric name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // Nullable and set server-side from the audit, never from the
            // request: a public endpoint accepting a website id would let
            // anyone file leads against someone else's site.
            $table->foreignId('website_id')->nullable()->after('audit_id')
                ->constrained()->nullOnDelete();

            // The findings the visitor was looking at when they asked for
            // help, and the services they want. Stored as JSON rather than
            // pivot tables: neither is queried, both are read whole by one
            // salesperson looking at one lead.
            $table->json('issue_codes')->nullable()->after('message');
            $table->json('requested_services')->nullable()->after('issue_codes');

            $table->index(['status', 'created_at'], 'leads_status_created_at_index');
            $table->index('website_id');
        });

        // 'converted' named the outcome someone wanted to count; every
        // other case names a stage the lead is sitting in.
        DB::table('leads')->where('status', 'converted')->update(['status' => 'won']);
    }

    public function down(): void
    {
        DB::table('leads')->where('status', 'won')->update(['status' => 'converted']);

        // A lead that reached 'proposal' has no pre-§55 equivalent.
        // 'qualified' is the stage it passed through to get there, which
        // is the honest place to put it back — inventing 'contacted' or
        // dropping to 'new' would lose more.
        DB::table('leads')->where('status', 'proposal')->update(['status' => 'qualified']);

        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_status_created_at_index');
            $table->dropForeign(['website_id']);
            $table->dropIndex(['website_id']);
            $table->dropColumn(['website_id', 'issue_codes', 'requested_services']);
        });
    }
};
