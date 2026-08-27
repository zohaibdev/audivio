<?php

namespace Database\Seeders;

use App\Models\ApiProvider;
use Illuminate\Database\Seeder;

/**
 * Seeds the four providers this app knows how to call. Provider
 * configuration (API keys, models, limits) moved from .env/config/*.php
 * to this DB-backed, admin-editable table — if an equivalent .env value
 * is already set (from before this migration), it's imported once here
 * as a convenience so an existing deployment doesn't go dark; from then
 * on the admin UI is the source of truth, not .env.
 */
class ProviderSeeder extends Seeder
{
    public function run(): void
    {
        $this->seed('claude', 'Claude (Anthropic)', 'ai', env('ANTHROPIC_API_KEY'), env('ANTHROPIC_MODEL', 'claude-sonnet-5'), [
            'max_tokens' => 2048,
            'request_timeout' => 60,
        ]);

        $this->seed('openai', 'OpenAI (ChatGPT)', 'ai', env('OPENAI_API_KEY'), env('OPENAI_MODEL', 'gpt-5'), [
            'max_tokens' => 2048,
            'request_timeout' => 60,
        ]);

        $this->seed('gemini', 'Google Gemini', 'ai', env('GEMINI_API_KEY'), env('GEMINI_MODEL', 'gemini-3-pro'), [
            'max_tokens' => 2048,
            'request_timeout' => 60,
        ]);

        $this->seed('pagespeed', 'Google PageSpeed Insights', 'audit', env('PAGESPEED_API_KEY'), null, [
            'mobile_enabled' => true,
            'desktop_enabled' => false,
            'request_timeout' => 45,
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function seed(string $slug, string $name, string $type, ?string $apiKey, ?string $model, array $config): void
    {
        $existing = ApiProvider::where('slug', $slug)->first();

        if ($existing !== null) {
            return;
        }

        // Normalize an empty-string env value (e.g. `GEMINI_API_KEY=` with
        // nothing after it) to null — storing '' would still encrypt to a
        // non-null ciphertext blob, which would make isConfigured() think
        // a key exists when there's really nothing usable there.
        $apiKey = $apiKey !== null && $apiKey !== '' ? $apiKey : null;

        ApiProvider::create([
            'slug' => $slug,
            'name' => $name,
            'provider_type' => $type,
            'is_enabled' => $apiKey !== null,
            'api_key' => $apiKey,
            'model' => $model,
            'config' => $config,
        ]);
    }
}
