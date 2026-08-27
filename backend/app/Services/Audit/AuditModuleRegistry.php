<?php

namespace App\Services\Audit;

use App\Contracts\AuditModule;
use App\Enums\AuditStatus;
use App\Jobs\Audit\RunAuditModuleJob;
use App\Models\Audit;

/**
 * Single source of truth for which AuditModule classes run after a crawl
 * completes. Phase 4 (AI auditors — ecommerce, landing_page, conversion,
 * aio) adds entries here rather than touching the crawl jobs.
 */
class AuditModuleRegistry
{
    /**
     * @return class-string<AuditModule>[]
     */
    public static function modules(): array
    {
        return [
            SeoAuditModule::class,
            AccessibilityAuditModule::class,
            PerformanceAuditModule::class,
        ];
    }

    public static function dispatchAnalysis(Audit $audit): void
    {
        $audit->update(['status' => AuditStatus::Analyzing]);

        foreach (self::modules() as $moduleClass) {
            RunAuditModuleJob::dispatch($audit, $moduleClass);
        }
    }
}
