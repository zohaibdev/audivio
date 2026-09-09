<?php

namespace App\Models;

use App\Enums\ShareEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded thing that happened to a share (§50).
 *
 * Append-only by convention: nothing in the application updates or
 * deletes these, and `report_share_id` is nullable so deleting a share
 * leaves its history intact.
 */
class ReportShareEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'report_share_id',
        'audit_id',
        'event',
        'ip_address',
        'user_agent',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'event' => ShareEvent::class,
            'context' => 'array',
        ];
    }

    public function share(): BelongsTo
    {
        return $this->belongsTo(ReportShare::class, 'report_share_id');
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }
}
