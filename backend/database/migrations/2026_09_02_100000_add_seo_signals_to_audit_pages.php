<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The page-level signals the deep SEO audit needs (§44).
 *
 * All nullable, and null means "not recorded" — which is every page
 * crawled before this migration. That distinction matters here more than
 * usual: "no robots meta tag" and "we never looked" lead to opposite
 * conclusions about whether a page is indexable, and a report that
 * confuses them tells the owner their page is fine when it may be hidden
 * from search entirely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_pages', function (Blueprint $table) {
            // Raw directive strings, parsed at read time rather than
            // stored pre-interpreted: the parsing rules change, the
            // markup the site served does not.
            $table->string('meta_robots', 500)->nullable()->after('canonical_url');
            $table->string('meta_googlebot', 500)->nullable()->after('meta_robots');

            $table->json('open_graph')->nullable()->after('structured_data');
            $table->json('twitter_card')->nullable()->after('open_graph');

            // Kept for X-Robots-Tag, which is an indexability directive
            // that never appears in the HTML at all — a page can look
            // perfectly indexable in markup and be excluded by a header.
            $table->json('response_headers')->nullable()->after('twitter_card');
        });
    }

    public function down(): void
    {
        Schema::table('audit_pages', function (Blueprint $table) {
            $table->dropColumn([
                'meta_robots',
                'meta_googlebot',
                'open_graph',
                'twitter_card',
                'response_headers',
            ]);
        });
    }
};
