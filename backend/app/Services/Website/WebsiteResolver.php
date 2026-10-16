<?php

namespace App\Services\Website;

use App\Models\User;
use App\Models\Website;
use App\Services\Crawler\UrlNormalizer;
use App\Services\User\UsageLimitService;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The single place a `websites` row is created or found for an audit
 * submission.
 *
 * Before this existed, `AuditController::store()` called
 * `Website::create()` unconditionally, so auditing the same site twice
 * produced two website records and two cards in "My Websites" — the
 * duplicate-card bug this class exists to make structurally impossible.
 */
class WebsiteResolver
{
    public function __construct(
        private readonly UrlNormalizer $normalizer,
        private readonly UsageLimitService $usageLimits,
    ) {}

    /**
     * Find the caller's existing record for this domain, or create it.
     *
     * @param  string  $normalizedUrl  already through UrlNormalizer::normalize()
     */
    public function resolve(?User $user, string $normalizedUrl): Website
    {
        $normalizedDomain = $this->normalizer->normalizedDomain($normalizedUrl);
        $domain = parse_url($normalizedUrl, PHP_URL_HOST);

        // Anonymous submissions always get a fresh row. There is no
        // identity to attach a durable record to, so the only thing a
        // shared row could key on is the domain itself — and that would
        // hand every anonymous visitor the audit history of every other
        // anonymous visitor who happened to audit the same site.
        // Duplicate anonymous rows are the correct trade: they are
        // invisible (anonymous users have no "My Websites" listing, §8)
        // and they leak nothing.
        if ($user === null) {
            return Website::create([
                'user_id' => null,
                'name' => $normalizedDomain,
                'primary_url' => $normalizedUrl,
                'domain' => $domain,
                'normalized_domain' => $normalizedDomain,
            ]);
        }

        $existing = Website::where('user_id', $user->id)
            ->where('normalized_domain', $normalizedDomain)
            ->first();

        // The website cap applies only to *adding* a site (§40). Re-auditing
        // one already owned must keep working even when the account is at
        // (or over) its limit — otherwise lowering a plan's cap would lock
        // subscribers out of the sites they already have rather than just
        // stopping them adding more.
        if ($existing === null) {
            $refusal = $this->usageLimits->websiteRefusalReason($user);

            if ($refusal !== null) {
                throw $refusal;
            }
        }

        if ($existing !== null) {
            // The submitted URL becomes the primary URL for future crawls:
            // re-auditing "example.com/pricing" after "example.com" is the
            // user saying that is the page they care about. The profile
            // fields they filled in are left untouched — a re-audit is not
            // an edit of the website record.
            $existing->update([
                'primary_url' => $normalizedUrl,
                'domain' => $domain,
            ]);

            return $existing;
        }

        try {
            return Website::create([
                'user_id' => $user->id,
                'name' => $normalizedDomain,
                'primary_url' => $normalizedUrl,
                'domain' => $domain,
                'normalized_domain' => $normalizedDomain,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two audits of the same new domain submitted at once: both
            // saw no existing row, both tried to insert. The unique index
            // is what actually prevents the duplicate; this just turns the
            // loser of that race into the ordinary "found it" path instead
            // of a 500.
            return Website::where('user_id', $user->id)
                ->where('normalized_domain', $normalizedDomain)
                ->firstOrFail();
        }
    }
}
