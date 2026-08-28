<?php

namespace App\Services\Audit;

use App\Models\AuditPage;
use Illuminate\Support\Collection;

/**
 * Identifies which crawled pages are actually product pages, so the
 * product audit only ever runs against real products.
 *
 * Deliberately stricter than EcommerceDetector's site-level check: that
 * one asks "is this a store at all", this one asks "is *this page* a
 * product page". A site can pass the first and still have no crawled
 * product page (e.g. only the cart and account pages were reached), in
 * which case the product audit is Not Applicable rather than scored
 * against non-product pages.
 */
class ProductPageDetector
{
    /**
     * @return Collection<int, AuditPage>
     */
    public function detect(Collection $pages): Collection
    {
        return $pages->filter(fn (AuditPage $page) => $this->isProductPage($page))->values();
    }

    public function isProductPage(AuditPage $page): bool
    {
        // Product structured data is definitive on its own — it is the
        // page explicitly declaring itself a product.
        if ($this->productSchema($page) !== null) {
            return true;
        }

        // Otherwise require a product-shaped URL *plus* a corroborating
        // buy signal. Either alone is too weak: /shop/ is often a listing
        // page, and an "add to cart" link can appear in a nav bar on
        // every page of the site.
        $urlLooksLikeProduct = (bool) preg_match('#/(product|products|item|p)/[^/]+#i', $page->url);

        return $urlLooksLikeProduct && $this->hasAddToCart($page);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function productSchema(AuditPage $page): ?array
    {
        foreach ($page->structured_data ?? [] as $entry) {
            if (($entry['@type'] ?? null) === 'Product') {
                return $entry;
            }
        }

        return null;
    }

    public function hasAddToCart(AuditPage $page): bool
    {
        return collect($page->cta_elements ?? [])->contains(
            // 'basket'/'bag' cover UK/EU stores — see the matching
            // keyword list in the crawler's extract.mjs, which must
            // capture the element before this can ever match it.
            fn ($cta) => preg_match('/\badd to (cart|basket|bag)\b|\b(buy now|purchase)\b/i', $cta['text'] ?? '')
        );
    }
}
