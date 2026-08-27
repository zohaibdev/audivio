<?php

namespace App\Models;

use App\Enums\CrawlUrlStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrawlUrl extends Model
{
    protected $fillable = [
        'crawl_job_id',
        'url',
        'status',
        'http_status',
        'depth',
        'discovered_via',
        'error_message',
        'attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CrawlUrlStatus::class,
            'attempted_at' => 'datetime',
        ];
    }

    public function crawlJob(): BelongsTo
    {
        return $this->belongsTo(CrawlJob::class);
    }
}
