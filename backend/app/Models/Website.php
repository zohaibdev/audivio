<?php

namespace App\Models;

use App\Enums\PlatformName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Website extends Model
{
    protected $fillable = [
        'user_id',
        'url',
        'domain',
        'platform',
    ];

    protected function casts(): array
    {
        return [
            'platform' => PlatformName::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function audits(): HasMany
    {
        return $this->hasMany(Audit::class);
    }

    public function auditSchedules(): HasMany
    {
        return $this->hasMany(AuditSchedule::class);
    }

    /** The single most recent audit — used by the "my websites" listing so the frontend gets one object, not an array. */
    public function latestAudit(): HasOne
    {
        return $this->hasOne(Audit::class)->latestOfMany();
    }
}
