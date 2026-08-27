<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One row per real audit category the app can gate (see
 * App\Services\Plan\FeatureAccessResolver for the category<->key mapping).
 * Not admin-creatable — rows are seeded once by FeatureSeeder and map 1:1
 * to actual AuditModule categories, so an admin-invented feature key would
 * have no audit logic to gate.
 */
class Feature extends Model
{
    protected $fillable = [
        'key',
        'name',
        'description',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plan_features')
            ->withPivot('is_enabled')
            ->withTimestamps();
    }
}
