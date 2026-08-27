<?php

namespace App\Models;

use App\Enums\PlatformName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
