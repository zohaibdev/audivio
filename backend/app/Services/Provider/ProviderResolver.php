<?php

namespace App\Services\Provider;

use App\Models\ApiProvider;
use App\Services\AI\AiProviderException;

/**
 * Shared "is this provider usable right now" check for every provider
 * class (AI or audit-utility) — replaces each provider reading its own
 * settings straight out of config/*.php. No constructor dependencies, so
 * it's safe to instantiate directly (`new ProviderResolver`) from inside
 * a provider class without going through the container.
 */
class ProviderResolver
{
    public function resolve(string $slug): ApiProvider
    {
        $provider = ApiProvider::where('slug', $slug)->first();

        if ($provider === null || ! $provider->is_enabled || ! $provider->isConfigured()) {
            throw new AiProviderException("The '{$slug}' provider is not configured or is disabled.");
        }

        return $provider;
    }

    /**
     * Like resolve(), but doesn't require the provider to be enabled —
     * only that it has credentials at all. Used by "Test Connection" so
     * an admin can validate a key before turning the provider on.
     */
    public function resolveConfigured(string $slug): ApiProvider
    {
        $provider = ApiProvider::where('slug', $slug)->first();

        if ($provider === null || ! $provider->isConfigured()) {
            throw new AiProviderException("The '{$slug}' provider has no API key configured.");
        }

        return $provider;
    }

    public function recordSuccess(ApiProvider $provider): void
    {
        $provider->update(['last_success_at' => now(), 'last_error' => null]);
    }

    public function recordFailure(ApiProvider $provider, string $message): void
    {
        $provider->update(['last_failure_at' => now(), 'last_error' => substr($message, 0, 250)]);
    }
}
