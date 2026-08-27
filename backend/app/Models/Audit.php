<?php

namespace App\Models;

use App\Enums\AuditStatus;
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
        'max_pages',
        'pages_crawled',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AuditStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
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
}
