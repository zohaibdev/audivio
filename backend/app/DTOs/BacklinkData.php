<?php

namespace App\DTOs;

use Carbon\CarbonInterface;

/**
 * A backlink profile as reported by one provider (§44).
 *
 * Every field is what that provider said, attributed to it by name and
 * stamped with when it was retrieved. Backlink indexes disagree with each
 * other substantially, and a number presented without its source reads as
 * an objective fact about the web rather than one index's view of it.
 */
final readonly class BacklinkData
{
    /**
     * @param  array<int, array{domain: string, url?: string, anchor_text?: string|null, authority?: int|null}>  $topReferrers
     */
    public function __construct(
        public string $domain,
        public string $providerName,
        public CarbonInterface $fetchedAt,
        public ?int $totalBacklinks = null,
        public ?int $referringDomains = null,
        /** The provider's own authority/rating score, on its own scale. */
        public ?int $domainAuthority = null,
        public ?string $authorityScale = null,
        public array $topReferrers = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'domain' => $this->domain,
            'provider' => $this->providerName,
            'fetched_at' => $this->fetchedAt->toIso8601String(),
            'total_backlinks' => $this->totalBacklinks,
            'referring_domains' => $this->referringDomains,
            'domain_authority' => $this->domainAuthority,
            'authority_scale' => $this->authorityScale,
            'top_referrers' => $this->topReferrers,
        ];
    }
}
