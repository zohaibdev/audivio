<?php

namespace App\Models;

use App\Enums\AiTaskComplexity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A single addressable model exposed by an ApiProvider (e.g. Claude's
 * "claude-3-5-sonnet-20241022"), distinct from ApiProvider::$model (the one
 * model that provider is currently actively calling). Plans reference a
 * subset of these via plan_ai_models — see App\Services\AI\PlanAiModelResolver.
 */
class AiModel extends Model
{
    protected $fillable = [
        'api_provider_id',
        'model_id',
        'display_name',
        'tier',
        'description',
        'is_enabled',
        'input_cost_per_1k_tokens',
        'output_cost_per_1k_tokens',
        'context_window',
        'capabilities',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'tier' => AiTaskComplexity::class,
            'input_cost_per_1k_tokens' => 'float',
            'output_cost_per_1k_tokens' => 'float',
            'context_window' => 'integer',
            'capabilities' => 'array',
        ];
    }

    public function apiProvider(): BelongsTo
    {
        return $this->belongsTo(ApiProvider::class);
    }

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plan_ai_models')->withTimestamps();
    }

    /**
     * Usable right now: enabled at the model level and the owning provider
     * is enabled + has real credentials. Does not check plan assignment —
     * see Plan::isModelAllowed() / PlanAiModelResolver for that.
     */
    /**
     * What one thousand tokens of each kind costs, used to rank equally
     * capable models cheapest-first (§53). A model with no rates recorded
     * sorts last rather than free — an unknown price is not a low one.
     */
    public function blendedCostPer1k(): ?float
    {
        if ($this->input_cost_per_1k_tokens === null && $this->output_cost_per_1k_tokens === null) {
            return null;
        }

        return ($this->input_cost_per_1k_tokens ?? 0.0) + ($this->output_cost_per_1k_tokens ?? 0.0);
    }

    public function isUsable(): bool
    {
        return $this->is_enabled && $this->apiProvider->is_enabled && $this->apiProvider->isConfigured();
    }
}
