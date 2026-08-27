<?php

namespace App\Models;

use App\Enums\AuditCategory;
use App\Enums\IssueSeverity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditIssue extends Model
{
    protected $fillable = [
        'audit_id',
        'audit_page_id',
        'category',
        'severity',
        'code',
        'message',
        'recommendation',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'category' => AuditCategory::class,
            'severity' => IssueSeverity::class,
            'meta' => 'array',
        ];
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    public function auditPage(): BelongsTo
    {
        return $this->belongsTo(AuditPage::class);
    }
}
