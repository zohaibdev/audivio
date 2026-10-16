<?php

namespace App\Models;

use App\Enums\AuditCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAnalysis extends Model
{
    protected $fillable = [
        'audit_id',
        'category',
        'provider',
        'model',
        'prompt_version',
        'context_hash',
        'context',
        'prompt',
        'response',
        'prompt_tokens',
        'completion_tokens',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'category' => AuditCategory::class,
            'context' => 'array',
        ];
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }
}
