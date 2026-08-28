<?php

namespace App\Enums;

/**
 * Which device profile a performance measurement describes. Mobile is the
 * primary audit priority — see PerformanceReportBuilder, which orders and
 * presents mobile first throughout.
 */
enum MetricStrategy: string
{
    case Mobile = 'mobile';
    case Desktop = 'desktop';
}
