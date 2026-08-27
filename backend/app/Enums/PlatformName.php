<?php

namespace App\Enums;

enum PlatformName: string
{
    case Shopify = 'shopify';
    case WordPress = 'wordpress';
    case WooCommerce = 'woocommerce';
    case Webflow = 'webflow';
    case Wix = 'wix';
    case Custom = 'custom';
    case Unknown = 'unknown';
}
