<?php

namespace App\Models;

use App\Enums\ScheduleFrequency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditSchedule extends Model
{
    protected $fillable = [
        'user_id',
        'website_id',
        'frequency',
        'is_active',
        'next_run_at',
        'last_run_at',
    ];

    protected function casts(): array
    {
        return [
            'frequency' => ScheduleFrequency::class,
            'is_active' => 'boolean',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
