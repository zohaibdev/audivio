<?php

namespace App\Services\Crawler;

use App\Enums\PlatformName;

class PlatformDetector
{
    /**
     * @param  array<string, string>  $headers  Response headers, keys lower-cased
     */
    public function detect(string $html, array $headers = []): PlatformName
    {
        $headers = array_change_key_case($headers, CASE_LOWER);

        if ($this->isShopify($html, $headers)) {
            return PlatformName::Shopify;
        }

        $isWordPress = $this->isWordPress($html);

        if ($isWordPress && $this->isWooCommerce($html)) {
            return PlatformName::WooCommerce;
        }

        if ($isWordPress) {
            return PlatformName::WordPress;
        }

        if ($this->isWebflow($html)) {
            return PlatformName::Webflow;
        }

        if ($this->isWix($html, $headers)) {
            return PlatformName::Wix;
        }

        if ($this->hasKnownGeneratorMeta($html)) {
            return PlatformName::Custom;
        }

        return PlatformName::Unknown;
    }

    private function isShopify(string $html, array $headers): bool
    {
        return isset($headers['x-shopid'])
            || isset($headers['x-sorting-hat-shopid'])
            || str_contains($html, 'cdn.shopify.com')
            || str_contains($html, 'Shopify.shop')
            || str_contains($html, '/cdn/shop/');
    }

    private function isWordPress(string $html): bool
    {
        return str_contains($html, 'wp-content')
            || str_contains($html, 'wp-includes')
            || (bool) preg_match('/<meta\s+name=["\']generator["\']\s+content=["\']WordPress/i', $html);
    }

    private function isWooCommerce(string $html): bool
    {
        return str_contains($html, 'woocommerce') || str_contains($html, 'wc-block');
    }

    private function isWebflow(string $html): bool
    {
        return (bool) preg_match('/data-wf-(site|page)/i', $html) || str_contains($html, 'webflow.js');
    }

    private function isWix(string $html, array $headers): bool
    {
        return isset($headers['x-wix-request-id'])
            || str_contains($html, 'static.wixstatic.com')
            || str_contains($html, '_wixCIDX')
            || str_contains($html, 'wix.com');
    }

    private function hasKnownGeneratorMeta(string $html): bool
    {
        return (bool) preg_match('/<meta\s+name=["\']generator["\']\s+content=["\'][^"\']+["\']/i', $html);
    }
}
