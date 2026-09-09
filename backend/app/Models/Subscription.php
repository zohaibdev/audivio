<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = [
        'user_id',
        'plan_id',
        'status',
        'started_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'started_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Whether this subscription still entitles its owner to its plan.
     *
     * Both halves matter: a subscription can be marked Active but have run
     * past its `ends_at` (nothing sweeps them), and it can be Cancelled but
     * still inside a paid-up period. A lapsed subscription does not deny
     * everything — EntitlementResolver drops the account to the default
     * plan (section 40).
     */
    public function isActive(): bool
    {
        if ($this->status !== SubscriptionStatus::Active) {
            return false;
        }

        return $this->ends_at === null || $this->ends_at->isFuture();
    }
}
