<?php

namespace App\Services\Audit;

use App\DTOs\EcommerceDetectionResult;
use App\Enums\PlatformName;
use App\Models\Audit;

/**
 * Weighted, multi-signal ecommerce detection — replaces a single loose
 * regex match against generic CTA text ("buy", "checkout", "shop now"),
 * which produced false positives on real non-commerce sites: a nonprofit
 * site selling event tickets ("Checkout" button for a ticket order) or a
 * donation flow ("Donate now") could trip a bare word-boundary match with
 * no other corroborating signal. Detection now requires a minimum
 * confidence score built from several independent signals before the
 * Ecommerce module runs at all (see EcommerceAuditModule) — a single weak
 * signal alone (e.g. one generic CTA word) is not enough.
 */
class EcommerceDetector
{
    public function detect(Audit $audit): EcommerceDetectionResult
    {
        $pages = $audit->pages;
        $signals = [];
        $score = 0;

        if ($pages->contains(fn ($p) => $this->hasProductSchema($p))) {
            $signals[] = 'Product structured data (schema.org Product) found';
            $score += 3;
        }

        if (in_array($audit->website->platform, [PlatformName::Shopify, PlatformName::WooCommerce], true)) {
            $signals[] = "Detected ecommerce platform: {$audit->website->platform->value}";
            $score += 3;
        }

        if ($pages->contains(fn ($p) => $this->hasAddToCartCta($p))) {
            // "Add to cart" specifically (not a generic "buy"/"checkout"
            // word) is close to ecommerce-exclusive phrasing — strong on
            // its own, unlike the weak generic-CTA signal below.
            $signals[] = 'An "add to cart" call-to-action was found';
            $score += 3;
        }

        if ($pages->contains(fn ($p) => $this->hasCartOrCheckoutLink($p))) {
            // Weak, not medium: a "/checkout" URL segment alone is just as
            // ambiguous as generic CTA text — a nonprofit's ticket-order
            // flow (e.g. /tickets/checkout/) matches this with zero
            // product evidence anywhere on the site. Verified against a
            // real audit where this signal + one generic CTA (2+1=3)
            // used to clear the old threshold on a non-ecommerce site.
            $signals[] = 'A link pointing to a cart/checkout/basket URL was found';
            $score += 1;
        }

        if ($pages->contains(fn ($p) => $this->hasPriceOrSku($p))) {
            $signals[] = 'Price or SKU data found in structured data';
            $score += 2;
        }

        $productUrlPages = $pages->filter(fn ($p) => preg_match('#/(product|products|shop|item)(/|$)#i', $p->url))->count();
        if ($productUrlPages >= 2) {
            $signals[] = "{$productUrlPages} crawled pages match product/shop URL patterns";
            $score += 1;
        }

        // Generic transaction-adjacent words ("buy", "checkout", "shop
        // now") are weak on their own — a nonprofit's ticket-order
        // "Checkout" button or a donation flow's "Buy a ticket" CTA
        // matches this without the site selling any products at all. This
        // signal alone must never clear the detection threshold; it only
        // nudges the score when paired with something stronger.
        if ($pages->contains(fn ($p) => $this->hasGenericTransactionCta($p))) {
            $signals[] = 'A generic purchase-adjacent call-to-action (e.g. "buy", "checkout") was found';
            $score += 1;
        }

        $confidence = match (true) {
            $score >= 5 => 'high',
            $score >= 3 => 'medium',
            $score >= 1 => 'low',
            default => 'none',
        };

        // Below "medium" (a single weak/low signal, like one product-style
        // URL or nothing at all) is not enough to call a site ecommerce —
        // this is the threshold that fixes the false-positive bug: a
        // generic "checkout" CTA alone used to be sufficient on its own,
        // now it contributes to the score but can't clear the bar by itself.
        $threshold = config('audit_scoring.ecommerce.min_confidence_score', 3);

        return new EcommerceDetectionResult(
            isEcommerce: $score >= $threshold,
            confidence: $confidence,
            signals: $signals,
        );
    }

    private function hasProductSchema($page): bool
    {
        return collect($page->structured_data ?? [])->contains(fn ($entry) => ($entry['@type'] ?? null) === 'Product');
    }

    private function hasPriceOrSku($page): bool
    {
        return collect($page->structured_data ?? [])->contains(
            fn ($entry) => isset($entry['sku']) || isset($entry['offers']['price']) || isset($entry['offers'][0]['price'])
        );
    }

    private function hasCartOrCheckoutLink($page): bool
    {
        return collect($page->links ?? [])->contains(
            fn ($link) => preg_match('#/(cart|checkout|basket)(/|$)#i', parse_url($link['href'] ?? '', PHP_URL_PATH) ?? '')
        );
    }

    private function hasAddToCartCta($page): bool
    {
        return collect($page->cta_elements ?? [])->contains(
            // Includes the UK/EU 'basket'/'bag' wording — see the matching
            // keyword list in the crawler's extract.mjs.
            fn ($cta) => preg_match('/\badd\s*to\s*(cart|basket|bag)\b/i', $cta['text'] ?? '')
        );
    }

    private function hasGenericTransactionCta($page): bool
    {
        return collect($page->cta_elements ?? [])->contains(
            fn ($cta) => preg_match('/\b(buy|checkout|shop now)\b/i', $cta['text'] ?? '')
        );
    }
}
