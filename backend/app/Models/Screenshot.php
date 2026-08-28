<?php

namespace App\Models;

use App\Enums\ScreenshotViewport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Screenshot extends Model
{
    protected $fillable = [
        'audit_id',
        'audit_page_id',
        'viewport',
        'width',
        'height',
        'storage_path',
    ];

    protected function casts(): array
    {
        return [
            'viewport' => ScreenshotViewport::class,
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
