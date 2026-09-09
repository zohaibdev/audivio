<?php

namespace App\Contracts;

use App\DTOs\BacklinkData;

/**
 * A source of backlink data for a domain (§44).
 *
 * Backlinks cannot be measured by crawling the site being audited — they
 * live on other people's websites — so every implementation of this is a
 * wrapper around a third-party index. That has one consequence this
 * interface exists to enforce: **when there is no provider, the product
 * reports that it has no backlink data.** It never estimates, models, or
 * infers a backlink profile, because a fabricated number here is
 * indistinguishable from a real one to the reader and would be trusted
 * exactly as much.
 */
interface BacklinkProvider
{
    public function name(): string;

    /**
     * Whether this provider has everything it needs to answer. A provider
     * missing its credentials returns false rather than throwing, so the
     * audit can report "not configured" instead of failing.
     */
    public function isConfigured(): bool;

    /**
     * Real backlink data for a domain, or null when the provider could not
     * answer — an outage, a rate limit, an unknown domain.
     *
     * Null is a legitimate result and must be reported as "no data", never
     * substituted with zeros: "this domain has no backlinks" and "we could
     * not find out" are opposite claims about the same site.
     */
    public function fetch(string $domain): ?BacklinkData;
}
