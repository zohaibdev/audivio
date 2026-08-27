<?php

namespace Tests\Unit\Services\Crawler;

use App\Enums\PlatformName;
use App\Services\Crawler\PlatformDetector;
use Tests\TestCase;

class PlatformDetectorTest extends TestCase
{
    private PlatformDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new PlatformDetector;
    }

    public function test_detects_shopify_from_cdn_reference(): void
    {
        $html = '<html><script src="https://cdn.shopify.com/s/files/1/x.js"></script></html>';
        $this->assertSame(PlatformName::Shopify, $this->detector->detect($html));
    }

    public function test_detects_shopify_from_header(): void
    {
        $this->assertSame(PlatformName::Shopify, $this->detector->detect('<html></html>', ['X-ShopId' => '12345']));
    }

    public function test_detects_shopify_from_header_case_insensitively(): void
    {
        $this->assertSame(PlatformName::Shopify, $this->detector->detect('<html></html>', ['x-shopid' => '12345']));
    }

    public function test_detects_wordpress_from_wp_content(): void
    {
        $html = '<html><link href="/wp-content/themes/x/style.css"></html>';
        $this->assertSame(PlatformName::WordPress, $this->detector->detect($html));
    }

    public function test_detects_wordpress_from_generator_meta(): void
    {
        $html = '<html><head><meta name="generator" content="WordPress 6.4"></head></html>';
        $this->assertSame(PlatformName::WordPress, $this->detector->detect($html));
    }

    public function test_detects_woocommerce_as_wordpress_plus_woocommerce_markers(): void
    {
        $html = '<html><body class="woocommerce"><div class="wp-content"></div></body></html>';
        $this->assertSame(PlatformName::WooCommerce, $this->detector->detect($html));
    }

    public function test_wordpress_without_woocommerce_markers_is_plain_wordpress(): void
    {
        $html = '<html><div class="wp-includes"></div></html>';
        $this->assertSame(PlatformName::WordPress, $this->detector->detect($html));
    }

    public function test_detects_webflow_from_data_attribute(): void
    {
        $html = '<html data-wf-site="abc123"><body></body></html>';
        $this->assertSame(PlatformName::Webflow, $this->detector->detect($html));
    }

    public function test_detects_wix_from_static_asset_domain(): void
    {
        $html = '<html><script src="https://static.wixstatic.com/x.js"></script></html>';
        $this->assertSame(PlatformName::Wix, $this->detector->detect($html));
    }

    public function test_detects_wix_from_header(): void
    {
        $this->assertSame(PlatformName::Wix, $this->detector->detect('<html></html>', ['X-Wix-Request-Id' => 'abc']));
    }

    public function test_shopify_signal_takes_priority_over_wordpress(): void
    {
        // A page that (implausibly) matches both — Shopify must win since
        // it's checked first in detect().
        $html = '<html><script src="https://cdn.shopify.com/x.js"></script><link href="/wp-content/x.css"></html>';
        $this->assertSame(PlatformName::Shopify, $this->detector->detect($html));
    }

    public function test_unrecognized_generator_meta_is_custom(): void
    {
        $html = '<html><head><meta name="generator" content="Some Other CMS 1.0"></head></html>';
        $this->assertSame(PlatformName::Custom, $this->detector->detect($html));
    }

    public function test_no_signals_at_all_is_unknown(): void
    {
        $html = '<html><body><h1>Hand-built page</h1></body></html>';
        $this->assertSame(PlatformName::Unknown, $this->detector->detect($html));
    }

    public function test_empty_html_is_unknown(): void
    {
        $this->assertSame(PlatformName::Unknown, $this->detector->detect(''));
    }
}
