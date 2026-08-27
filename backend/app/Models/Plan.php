<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'price_cents',
        'max_pages',
        'daily_audit_limit',
        'full_recrawls',
        'is_default',
        'is_active',
        'features',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'features' => 'array',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * pdf_export / scheduled_audits / audit_history-style boolean perks
     * stored in the `features` JSON column — unrelated to auditFeatures()
     * below despite the similar name; kept separate on purpose (§31).
     */
    public function hasFeature(string $key): bool
    {
        return (bool) ($this->features[$key] ?? false);
    }

    /**
     * The audit-category registry (performance, accessibility, cro, seo,
     * aio, ecommerce, product_auditor) this plan has access to — see
     * App\Services\Plan\FeatureAccessResolver, which is the only place
     * that should call hasAuditFeature() outside tests/admin controllers.
     */
    public function auditFeatures(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'plan_features')
            ->withPivot('is_enabled')
            ->withTimestamps();
    }

    public function hasAuditFeature(string $key): bool
    {
        return $this->auditFeatures()
            ->where('features.key', $key)
            ->where('features.is_enabled', true)
            ->wherePivot('is_enabled', true)
            ->exists();
    }

    public function allowedAiModels(): BelongsToMany
    {
        return $this->belongsToMany(AiModel::class, 'plan_ai_models')->withTimestamps();
    }

    public function isModelAllowed(AiModel $model): bool
    {
        return $this->allowedAiModels()->where('ai_models.id', $model->id)->exists();
    }
}
