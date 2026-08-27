<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A configurable external service (AI provider or audit utility like
 * PageSpeed). `api_key` is encrypted at rest and never serialized — see
 * `$hidden` — callers get `masked_key` instead. `config` carries every
 * setting that doesn't apply uniformly across providers (temperature,
 * request limits, cost rates, mobile/desktop toggles) so a new provider
 * needs a config shape, not a migration.
 */
class ApiProvider extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'provider_type',
        'is_enabled',
        'api_key',
        'model',
        'config',
        'last_success_at',
        'last_failure_at',
        'last_error',
    ];

    protected $hidden = [
        'api_key',
    ];

    protected $appends = [
        'masked_key',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'api_key' => 'encrypted',
            'config' => 'array',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function getMaskedKeyAttribute(): ?string
    {
        $key = $this->attributes['api_key'] ?? null;

        if ($key === null) {
            return null;
        }

        // Decrypt once (via the cast, through the real accessor) rather
        // than duplicating decryption logic here.
        $plain = $this->api_key;

        if ($plain === null || $plain === '') {
            return null;
        }

        $length = strlen($plain);

        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($plain, 0, 4).str_repeat('*', max(4, $length - 8)).substr($plain, -4);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->attributes['api_key'] ?? null);
    }

    public function getConfigValue(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Null when no per-token cost rate is configured for this provider —
     * callers must not invent a number the admin hasn't supplied.
     */
    public function estimateCost(?int $promptTokens, ?int $completionTokens): ?float
    {
        $inputRate = $this->getConfigValue('cost_per_1k_input_tokens');
        $outputRate = $this->getConfigValue('cost_per_1k_output_tokens');

        if ($inputRate === null && $outputRate === null) {
            return null;
        }

        $cost = (($promptTokens ?? 0) / 1000) * (float) ($inputRate ?? 0)
              + (($completionTokens ?? 0) / 1000) * (float) ($outputRate ?? 0);

        return round($cost, 6);
    }
}
