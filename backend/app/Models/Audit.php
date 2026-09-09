<?php

namespace App\Models;

use App\Enums\AuditStatus;
use App\Enums\SiteType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Audit extends Model
{
    protected $fillable = [
        'website_id',
        'user_id',
        'status',
        'site_type',
        'site_type_confidence',
        'site_type_signals',
        'max_pages',
        'pages_crawled',
        'error_message',
        'failed_stage',
        'started_at',
        'completed_at',
        'terminated_at',
        'progress_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AuditStatus::class,
            'site_type' => SiteType::class,
            'site_type_signals' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'terminated_at' => 'datetime',
            'progress_at' => 'datetime',
        ];
    }

    /**
     * What the crawl learned about robots.txt and the sitemap (§44).
     * Absent on audits that predate site-signal recording, and on audits
     * whose crawl never reached discovery.
     */
    public function siteSignals(): HasOne
    {
        return $this->hasOne(AuditSiteSignals::class);
    }

    public function shares(): HasMany
    {
        return $this->hasMany(ReportShare::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function crawlJob(): HasOne
    {
        return $this->hasOne(CrawlJob::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(AuditPage::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(AuditIssue::class);
    }

    public function scores(): HasMany
    {
        return $this->hasMany(AuditScore::class);
    }

    public function report(): HasOne
    {
        return $this->hasOne(Report::class);
    }

    public function screenshots(): HasMany
    {
        return $this->hasMany(Screenshot::class);
    }

    /** The immutable record of what this audit found (see section 38). */
    public function snapshot(): HasOne
    {
        return $this->hasOne(AuditSnapshot::class);
    }

    /** Audits still holding one of their owner's concurrent slots. */
    public function scopeActive($query)
    {
        return $query->whereIn('status', AuditStatus::activeValues());
    }
}
