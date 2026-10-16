<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site-level crawl findings — robots.txt and sitemap (§44).
 *
 * The crawler has always fetched both to decide what to crawl, then
 * thrown the result away. The SEO audit has to report on them, and
 * re-fetching at analysis time would be both a second request to the
 * site and a second answer: the site may have changed between the crawl
 * and the analysis, and the audit must report what the crawl actually
 * saw.
 *
 * Every "found" column is a nullable boolean on purpose. False means the
 * fetch succeeded and there was nothing there; null means the fetch
 * itself failed, and "we could not tell" is not the same finding as
 * "there is no sitemap".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_site_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('robots_txt_url', 2048)->nullable();
            $table->boolean('robots_txt_found')->nullable();
            $table->unsignedInteger('robots_disallow_count')->nullable();
            $table->json('robots_sitemaps')->nullable();

            $table->boolean('sitemap_found')->nullable();
            $table->json('sitemap_urls')->nullable();
            $table->unsignedInteger('sitemap_url_count')->nullable();
            // Whether the sitemap was declared in robots.txt or only found
            // at the conventional /sitemap.xml path. A sitemap a crawler
            // can only find by guessing is a weaker signal than a declared
            // one, and the report says which it was.
            $table->boolean('sitemap_declared_in_robots')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_site_signals');
    }
};
