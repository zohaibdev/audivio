<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class AuditPage extends Model
{
    protected $fillable = [
        'audit_id',
        'crawl_url_id',
        'url',
        'http_status',
        'title',
        'meta_description',
        'canonical_url',
        'redirect_chain',
        'headings',
        'links',
        'images',
        'forms',
        'structured_data',
        'cta_elements',
        'accessibility_violations',
        'performance_metrics',
        'html_size_bytes',
        'load_time_ms',
        'raw_data',
        'crawled_at',
    ];

    protected function casts(): array
    {
        return [
            'redirect_chain' => 'array',
            'headings' => 'array',
            'links' => 'array',
            'images' => 'array',
            'forms' => 'array',
            'structured_data' => 'array',
            'cta_elements' => 'array',
            'accessibility_violations' => 'array',
            'performance_metrics' => 'array',
            'raw_data' => 'array',
            'crawled_at' => 'datetime',
        ];
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    public function crawlUrl(): BelongsTo
    {
        return $this->belongsTo(CrawlUrl::class);
    }

    /**
     * Issues where this page is the *only* affected page (audit_page_id
     * points directly here) — see multiPageIssues() for issues that span
     * several pages, and allIssues() to get both together.
     */
    public function issues(): HasMany
    {
        return $this->hasMany(AuditIssue::class);
    }

    /**
     * Issues that affect this page *and* at least one other page, linked
     * via the issue_pages pivot (see PerPageIssueAggregator).
     */
    public function multiPageIssues(): BelongsToMany
    {
        return $this->belongsToMany(AuditIssue::class, 'issue_pages')->withTimestamps();
    }

    public function screenshots(): HasMany
    {
        return $this->hasMany(Screenshot::class);
    }

    /**
     * Every issue affecting this page, single- and multi-page combined —
     * Eloquent can't union a hasMany and a belongsToMany into one query,
     * so this merges them application-side.
     *
     * @return Collection<int, AuditIssue>
     */
    public function allIssues(): Collection
    {
        return $this->issues->concat($this->multiPageIssues);
    }
}
