<?php

namespace App\Enums;

enum ScreenshotViewport: string
{
    case Desktop = 'desktop';
    case Mobile = 'mobile';

    /**
     * Consistent audit viewport dimensions — every screenshot of this
     * viewport, across every audit, uses the same size so scores/
     * screenshots are comparable page to page and audit to audit.
     */
    public function width(): int
    {
        return match ($this) {
            self::Desktop => 1366,
            self::Mobile => 390,
        };
    }

    public function height(): int
    {
        return match ($this) {
            self::Desktop => 900,
            self::Mobile => 844,
        };
    }
}
