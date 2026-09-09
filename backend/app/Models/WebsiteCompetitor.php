<?php

namespace App\Models;

use App\Enums\CompetitorSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A competitor of one website, either named during onboarding or found by
 * discovery (section 42).
 *
 * Every analysis column is nullable and stays null until a comparison run
 * fills it. Null means "not analyzed", which is emphatically not a score
 * of zero — a competitor we could not reach must never read as a
 * competitor doing badly.
 */
class WebsiteCompetitor extends Model
{
    protected $fillable = [
        'website_id', 'name', 'url', 'normalized_domain', 'source', 'reason',
        'aio_score', 'pages_analyzed', 'topics', 'visibility', 'analysis_error', 'analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'source' => CompetitorSource::class,
            'topics' => 'array',
            'visibility' => 'array',
            'analyzed_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function isAnalyzed(): bool
    {
        return $this->analyzed_at !== null && $this->analysis_error === null;
    }

    public function displayName(): string
    {
        return $this->name ?: $this->normalized_domain;
    }
}
