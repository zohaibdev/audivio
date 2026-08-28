<?php

namespace App\Enums;

/**
 * Where a performance number came from. Every metric carries this so the
 * report can always attribute a figure rather than presenting browser
 * measurements and Lighthouse scores as one indistinguishable blend.
 */
enum MetricSource: string
{
    /** Measured directly by our own Playwright crawl. */
    case Crawler = 'crawler';

    /** Returned by Google PageSpeed Insights (Lighthouse). */
    case PageSpeed = 'pagespeed';
}
