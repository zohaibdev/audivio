<?php

namespace App\Services\Audit;

use App\Contracts\AuditModule;
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
            ProductAuditModule::class,
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
        // Through the lifecycle, so a cancelled audit is not silently
        // restarted into `analyzing` by a crawl job that finished after
        // the user cancelled. A refused transition means the audit already
        // reached a terminal state and there is nothing left to analyze.
        if (! app(AuditLifecycle::class)->markAnalyzing($audit)) {
            return;
        }

        // Classified once, before any module runs (§46). Every module
        // that can be Not Applicable reads this, so deciding it per module
        // would risk two of them disagreeing about what the site even is.
        self::classify($audit);

        $modules = self::modulesFor($audit);

        if ($modules === []) {
            // Every category is disabled for this plan, so nothing will
            // assess this site at all.
            //
            // This used to dispatch scoring anyway, and
            // ScoreCalculator::calculateOverall() returns 0 for an empty
            // set — so the audit completed and reported an overall score
            // of **0**. That is the worst output this product could
            // produce: a site nobody looked at, presented as
            // catastrophically bad, in a report indistinguishable from a
            // real one. It is the same fabrication the scoring rules
            // forbid everywhere else (§45), arrived at by omission.
            //
            // Failing is the honest end state. `completed` means "there is
            // something to look at" (§38), and here there is not. The
            // message names the cause, because the usual reading of a
            // failed audit is "the site broke the crawler" and this is a
            // configuration problem an admin can fix (§51, §56).
            app(AuditLifecycle::class)->markFailed(
                $audit,
                'No audit services are enabled for this plan, so there was nothing to assess. '
                .'Enable at least one audit service on the plan and run the audit again.',
                'analyzing',
            );

            return;
        }

        foreach ($modules as $moduleClass) {
            RunAuditModuleJob::dispatch($audit, $moduleClass);
        }
    }

    /**
     * Records what kind of site this audit is looking at, on the audit and
     * on the website (§46).
     *
     * Written to the audit because it decides which services that audit
     * reported at all: a site that adds a shop next month must not
     * retroactively change what last month's report said it skipped, or
     * why. The website copy is only the latest known type, for listings.
     */
    private static function classify(Audit $audit): void
    {
        $audit->loadMissing(['pages', 'website']);

        $classification = app(SiteClassifier::class)->classify($audit);

        $audit->update([
            'site_type' => $classification->type->value,
            'site_type_confidence' => $classification->confidence,
            'site_type_signals' => $classification->signals,
        ]);

        $audit->website?->update(['site_type' => $classification->type->value]);
    }
}
