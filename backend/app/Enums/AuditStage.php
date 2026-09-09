<?php

namespace App\Enums;

/**
 * The stages a running audit passes through, as a person would describe
 * them (§51).
 *
 * Finer-grained than `AuditStatus`, which is the state machine the
 * lifecycle enforces (§38). One status covers several stages — `crawling`
 * spans discovery, fetching and screenshots — because the states worth
 * *enforcing* and the states worth *showing someone waiting* are not the
 * same set.
 *
 * Every stage here is derived from something measured: a row that exists,
 * a counter that moved, a score that was written. None of them is a timer
 * pretending to be progress.
 */
enum AuditStage: string
{
    case Preparing = 'preparing';
    case Validating = 'validating';
    case Discovering = 'discovering';
    case Crawling = 'crawling';
    case Screenshots = 'screenshots';
    case Performance = 'performance';
    case Seo = 'seo';
    case Accessibility = 'accessibility';
    case Cro = 'cro';
    case Aio = 'aio';
    case Recommendations = 'recommendations';
    case BuildingReport = 'building_report';

    public function label(): string
    {
        return match ($this) {
            self::Preparing => 'Preparing',
            self::Validating => 'Validating website',
            self::Discovering => 'Discovering pages',
            self::Crawling => 'Crawling',
            self::Screenshots => 'Capturing screenshots',
            self::Performance => 'Performance analysis',
            self::Seo => 'SEO analysis',
            self::Accessibility => 'Accessibility analysis',
            self::Cro => 'CRO analysis',
            self::Aio => 'AIO analysis',
            self::Recommendations => 'Generating recommendations',
            self::BuildingReport => 'Building report',
        };
    }

    /**
     * The audit category whose completion marks this stage done, for the
     * stages that map to an analysis module. Null for the crawl stages,
     * which are tracked by counters instead.
     */
    public function category(): ?AuditCategory
    {
        return match ($this) {
            self::Performance => AuditCategory::Performance,
            self::Seo => AuditCategory::Seo,
            self::Accessibility => AuditCategory::Accessibility,
            self::Cro => AuditCategory::Conversion,
            self::Aio => AuditCategory::Aio,
            default => null,
        };
    }

    /**
     * In order, so a progress list can render the whole journey with the
     * finished ones behind and the remaining ones ahead.
     *
     * @return self[]
     */
    public static function ordered(): array
    {
        return self::cases();
    }
}
