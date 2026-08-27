<?php

namespace App\Models;

use App\Enums\CrawlJobStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrawlJob extends Model
{
    protected $fillable = [
        'audit_id',
        'status',
        'total_urls',
        'processed_urls',
        'failed_urls',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CrawlJobStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    public function urls(): HasMany
    {
        return $this->hasMany(CrawlUrl::class);
    }
}
