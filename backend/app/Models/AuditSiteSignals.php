<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the crawl learned about the site as a whole — robots.txt and the
 * sitemap (§44). One row per audit, written once during discovery.
 *
 * `*_found` is deliberately nullable: false means "fetched, not there",
 * null means "could not tell". The SEO module reports those differently,
 * because telling an owner they have no sitemap when the request merely
 * timed out sends them to fix something that is not broken.
 */
class AuditSiteSignals extends Model
{
    protected $table = 'audit_site_signals';

    protected $fillable = [
        'audit_id',
        'robots_txt_url',
        'robots_txt_found',
        'robots_disallow_count',
        'robots_sitemaps',
        'sitemap_found',
        'sitemap_urls',
        'sitemap_url_count',
        'sitemap_declared_in_robots',
    ];

    protected function casts(): array
    {
        return [
            'robots_txt_found' => 'boolean',
            'robots_sitemaps' => 'array',
            'sitemap_found' => 'boolean',
            'sitemap_urls' => 'array',
            'sitemap_declared_in_robots' => 'boolean',
        ];
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }
}
