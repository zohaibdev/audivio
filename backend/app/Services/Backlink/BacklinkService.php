<?php

namespace App\Services\Backlink;

use App\Contracts\BacklinkProvider;
use App\DTOs\BacklinkData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The single entry point for backlink data (§44).
 *
 * Backlinks are the one part of this audit that cannot be measured from
 * the site itself: they are links on other people's domains, and knowing
 * about them requires an index of the web. This product does not have one,
 * so it either asks a configured provider or says it has no data.
 *
 * The result shape always distinguishes three states, and callers must
 * render them differently:
 *
 * - `not_configured` — no provider is set up. Not a failure; the feature
 *   is simply off.
 * - `unavailable` — a provider is configured but could not answer.
 * - `available` — real data, attributed to the provider that supplied it.
 *
 * **Nothing here ever synthesizes a number.** There is no fallback
 * estimate, no modelled value, and no zero standing in for "unknown". A
 * plausible invented backlink count would be believed, and being believed
 * is exactly what makes it harmful.
 */
class BacklinkService
{
    public function __construct(private readonly BacklinkProvider $provider) {}

    /**
     * @return array<string, mixed>
     */
    public function forDomain(string $domain): array
    {
        if (! $this->provider->isConfigured()) {
            return [
                'status' => 'not_configured',
                'provider' => null,
                'data' => null,
                'message' => 'Backlink analysis is not enabled. Connect a backlink data provider to see referring domains and link counts.',
            ];
        }

        try {
            $data = $this->fetchCached($domain);
        } catch (\Throwable $e) {
            Log::channel('crawler')->warning('Backlink provider failed', [
                'provider' => $this->provider->name(),
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);

            $data = null;
        }

        if ($data === null) {
            return [
                'status' => 'unavailable',
                'provider' => $this->provider->name(),
                'data' => null,
                // Deliberately not "this site has no backlinks" — the
                // provider failing to answer says nothing about the site.
                'message' => 'The backlink provider could not return data for this domain. No backlink figures are shown rather than an estimate.',
            ];
        }

        return [
            'status' => 'available',
            'provider' => $this->provider->name(),
            'data' => $data->toArray(),
            'message' => null,
        ];
    }

    /**
     * Backlink figures are re-read every time a report is rendered — the
     * web page, each PDF export, every refresh — so without this a single
     * report view is one billed third-party call, and a shared report is
     * many.
     *
     * Only *successful* answers are cached. An outage must not be pinned
     * in place for an hour: "we could not find out" has to be able to
     * become "here is the data" as soon as the provider recovers.
     */
    private function fetchCached(string $domain): ?BacklinkData
    {
        $ttl = (int) config('backlinks.cache_ttl', 3600);

        if ($ttl <= 0) {
            return $this->provider->fetch($domain);
        }

        $key = "backlinks:{$this->provider->name()}:{$domain}";
        $cached = Cache::get($key);

        if ($cached instanceof BacklinkData) {
            return $cached;
        }

        $data = $this->provider->fetch($domain);

        if ($data !== null) {
            Cache::put($key, $data, $ttl);
        }

        return $data;
    }

    public function isEnabled(): bool
    {
        return $this->provider->isConfigured();
    }
}
