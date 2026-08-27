<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'screenshot_path',
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

    public function issues(): HasMany
    {
        return $this->hasMany(AuditIssue::class);
    }
}
