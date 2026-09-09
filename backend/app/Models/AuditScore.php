<?php

namespace App\Models;

use App\Enums\AuditCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditScore extends Model
{
    protected $fillable = [
        'audit_id',
        'category',
        'score',
        'is_applicable',
        'breakdown',
        'not_applicable_reason',
        'detection_evidence',
    ];

    protected function casts(): array
    {
        return [
            'category' => AuditCategory::class,
            'is_applicable' => 'boolean',
            'breakdown' => 'array',
            'detection_evidence' => 'array',
        ];
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }
}
