<?php

namespace App\Enums;

/**
 * What kind of website this is (§46).
 *
 * The audit reports different things for a shop than for a blog, and
 * running every module against every site produces scores that are
 * meaningless at best and misleading at worst — an ecommerce score on a
 * portfolio site is not a low score, it is a category error.
 *
 * Classification is a **judgement from measured signals**, so it always
 * carries the evidence behind it and a confidence, and `Other` is a real
 * answer rather than a failure. A site the signals do not agree about is
 * better reported as unclassified than confidently mislabelled.
 */
enum SiteType: string
{
    case Ecommerce = 'ecommerce';
    case ServiceBusiness = 'service_business';
    case Saas = 'saas';
    case Blog = 'blog';
    case Portfolio = 'portfolio';
    case LandingPage = 'landing_page';
    case LocalBusiness = 'local_business';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Ecommerce => 'Ecommerce store',
            self::ServiceBusiness => 'Service business',
            self::Saas => 'SaaS product',
            self::Blog => 'Blog or publication',
            self::Portfolio => 'Portfolio',
            self::LandingPage => 'Single landing page',
            self::LocalBusiness => 'Local business',
            self::Other => 'Unclassified',
        };
    }

    /**
     * What this classification means for the reader, in their terms.
     */
    public function description(): string
    {
        return match ($this) {
            self::Ecommerce => 'Sells products directly through the site, with a cart and checkout.',
            self::ServiceBusiness => 'Sells services rather than products — enquiries and bookings rather than a cart.',
            self::Saas => 'Offers software as a subscription, typically with sign-up, pricing tiers and a trial or demo.',
            self::Blog => 'Publishes articles as its main content, organised by date or topic.',
            self::Portfolio => 'Showcases work or projects, usually for an individual or studio.',
            self::LandingPage => 'A single page built around one action, with no wider site around it.',
            self::LocalBusiness => 'Serves customers at a physical location, with an address and opening hours.',
            self::Other => 'The signals on this site did not point clearly at any one type.',
        };
    }

    /**
     * Whether an ecommerce assessment can honestly be produced for this
     * type of site.
     *
     * Local businesses are included: many of them sell online as well, so
     * the type alone does not rule it out — the ecommerce detector's own
     * evidence decides. What this excludes is the set of sites where an
     * ecommerce score would be a category error no matter what a stray
     * "checkout" link suggested.
     */
    public function canSellOnline(): bool
    {
        return match ($this) {
            self::Ecommerce, self::LocalBusiness, self::Other => true,
            // A SaaS product does sell, but through subscription sign-up
            // rather than a cart — the ecommerce checks (add to cart,
            // shipping, returns) do not describe it.
            self::Saas, self::ServiceBusiness, self::Blog,
            self::Portfolio, self::LandingPage => false,
        };
    }

    /**
     * Why an ecommerce assessment was skipped for this type — written to
     * be read by the site's owner, not by us.
     */
    public function ecommerceSkipReason(): string
    {
        return match ($this) {
            self::Saas => 'This looks like a SaaS product rather than a shop. The ecommerce checks cover carts, shipping and returns, which do not apply to a subscription sign-up, so no ecommerce score is shown.',
            self::ServiceBusiness => 'This looks like a service business rather than a shop. There is nothing to add to a cart, so an ecommerce score would not describe anything about this site.',
            self::Blog => 'This looks like a blog or publication. It publishes content rather than selling products, so the ecommerce checks do not apply.',
            self::Portfolio => 'This looks like a portfolio site. It showcases work rather than selling products, so the ecommerce checks do not apply.',
            self::LandingPage => 'This looks like a single landing page built around one action. There is no store to assess.',
            default => 'No evidence of an online store was found on the pages that were crawled, so no ecommerce score is shown.',
        };
    }
}
