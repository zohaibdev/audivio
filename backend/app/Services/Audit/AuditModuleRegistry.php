<?php

namespace App\Services\Audit;

use App\Contracts\AuditModule;
use App\Enums\AuditStatus;
use App\Jobs\Audit\CalculateScoreJob;
use App\Jobs\Audit\RunAuditModuleJob;
use App\Models\Audit;
use App\Services\Plan\FeatureAccessResolver;

/**
 * Single source of truth for which AuditModule classes run after a crawl
 * completes.
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
            EcommerceAuditModule::class,
            LandingPageAuditModule::class,
            ConversionAuditModule::class,
            AioAuditModule::class,
        ];
    }

    /**
     * The subset of modules() this specific audit's plan actually allows —
     * RunAuditModuleJob::dispatchScoreCalculationIfReady() must use this
     * same filtered list (not modules()) or an audit whose plan disables
     * any category would wait forever for a score that will never arrive.
     *
     * @return class-string<AuditModule>[]
     */
    public static function modulesFor(Audit $audit): array
    {
        $resolver = app(FeatureAccessResolver::class);

        return array_values(array_filter(
            self::modules(),
            fn (string $moduleClass) => $resolver->categoryAllowed($audit->user, app($moduleClass)->category()),
        ));
    }

    public static function dispatchAnalysis(Audit $audit): void
    {
        $audit->update(['status' => AuditStatus::Analyzing]);

        $modules = self::modulesFor($audit);

        if ($modules === []) {
            // Every category is disabled for this plan — no module job will
            // ever run to trigger scoring, so dispatch it directly.
            // ScoreCalculator::calculateOverall() already handles a zero-
            // category collection (returns 0), so this doesn't crash.
            CalculateScoreJob::dispatch($audit);

            return;
        }

        foreach ($modules as $moduleClass) {
            RunAuditModuleJob::dispatch($audit, $moduleClass);
        }
    }
}
