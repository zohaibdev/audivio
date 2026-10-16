<?php

use App\Services\Backlink\NullBacklinkProvider;

/*
|--------------------------------------------------------------------------
| Backlink data provider (§44)
|--------------------------------------------------------------------------
| Backlinks live on other people's websites, so they cannot be measured by
| crawling the audited site. They require a third-party index.
|
| The default driver is `null`, meaning the product has no backlink data
| and says so. That is the correct behaviour, not a degraded one: the
| alternative — estimating a link profile — would produce a number a
| reader could not distinguish from a measured one.
|
| To enable, implement App\Contracts\BacklinkProvider, register it in
| `providers` below, and set BACKLINK_DRIVER.
*/

return [
    'driver' => env('BACKLINK_DRIVER', 'null'),

    /*
    | How long a successful answer is reused, in seconds. Reports are
    | re-rendered constantly and backlink profiles move slowly, so the
    | default trades an hour of staleness for not billing a third-party
    | call per page view. Set to 0 to disable caching entirely.
    |
    | Failures are never cached: an outage must be able to clear as soon
    | as the provider recovers.
    */
    'cache_ttl' => (int) env('BACKLINK_CACHE_TTL', 3600),

    'providers' => [
        'null' => NullBacklinkProvider::class,
    ],
];
