<?php

namespace App\Models;

use App\Enums\LeadStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    protected $fillable = [
        'audit_id',
        'website_id',
        'name',
        'email',
        'phone',
        'website_url',
        'message',
        'issue_codes',
        'requested_services',
        'source',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'issue_codes' => 'array',
            'requested_services' => 'array',
        ];
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    /**
     * The website this lead is about, set from the audit rather than from
     * the request — see ConsultationRequestService (§55).
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class);
    }
}
