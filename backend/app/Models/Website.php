<?php

namespace App\Models;

use App\Enums\AuditStatus;
use App\Enums\PlatformName;
use App\Enums\SiteType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Website extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'primary_url',
        'domain',
        'normalized_domain',
        'platform',
        'site_type',
        'category',
        'description',
        'products_or_services',
        'aio_goal',
        'country',
        'budget',
        'average_product_cost',
        'competitors',
    ];

    protected function casts(): array
    {
        return [
            'platform' => PlatformName::class,
            'site_type' => SiteType::class,
            'competitors' => 'array',
            'budget' => 'decimal:2',
            'average_product_cost' => 'decimal:2',
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

    /** Custom AIO questions the owner has added (section 42). */
    public function aioPrompts(): HasMany
    {
        return $this->hasMany(WebsiteAioPrompt::class);
    }

    /**
     * Competitor *records* — the analyzable set, both owner-named and
     * discovered. Distinct from the `competitors` JSON column, which is
     * the owner's raw declared list from the profile form: one is what
     * they told us, the other is what we analyzed (section 42).
     */
    public function competitorRecords(): HasMany
    {
        return $this->hasMany(WebsiteCompetitor::class);
    }

    /** The single most recent audit — used by the "my websites" listing so the frontend gets one object, not an array. */
    public function latestAudit(): HasOne
    {
        return $this->hasOne(Audit::class)->latestOfMany();
    }

    /**
     * The most recent audit that actually finished. Distinct from
     * `latestAudit`: a card must show the last audit whose scores mean
     * something, not a run that is still crawling (no scores yet) or one
     * that failed (partial scores). Without this split, starting a re-audit
     * would blank out the scores a user could see a moment earlier.
     */
    public function latestCompletedAudit(): HasOne
    {
        // `ofMany` with a constraint closure, not `where(...)->latestOfMany()`:
        // the latter filters the outer join but leaves the subquery picking
        // MAX(id) across *all* of the website's audits, so a website whose
        // newest audit is still running resolves to nothing at all.
        return $this->hasOne(Audit::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->where('status', AuditStatus::Completed),
        );
    }

    /** What to show when the user has not named the website themselves. */
    public function displayName(): string
    {
        return $this->name ?: ($this->normalized_domain ?: $this->domain);
    }
}
