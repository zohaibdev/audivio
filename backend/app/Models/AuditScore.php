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
        'breakdown',
    ];

    protected function casts(): array
    {
        return [
            'category' => AuditCategory::class,
            'breakdown' => 'array',
        ];
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }
}
