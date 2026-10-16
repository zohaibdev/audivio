<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiUsageLog extends Model
{
    protected $fillable = [
        'provider',
        'service',
        'request_type',
        'user_id',
        'audit_id',
        'model',
        'prompt_version',
        'success',
        'http_status',
        'response_time_ms',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'estimated_cost',
        'error_category',
    ];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'estimated_cost' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }
}
