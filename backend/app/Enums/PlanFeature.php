<?php

namespace App\Enums;

/**
 * The on/off entitlements a plan grants, stored in `plans.features` (JSON).
 *
 * Separate from `features`/`plan_features`, which is the **audit category**
 * registry (performance, seo, aio…) — that answers "which analysis runs",
 * this answers "what may this subscriber do with the result". The two were
 * already distinct before this enum existed (§31); naming the keys here
 * stops the JSON column from being free-form, so an admin cannot save a
 * typo'd key that silently disables nothing.
 */
enum PlanFeature: string
{
    case PdfExport = 'pdf_export';
    case ScheduledAudits = 'scheduled_audits';
    case AuditHistory = 'audit_history';
    case ReportSharing = 'report_sharing';
    case WhiteLabel = 'white_label';
    case CustomAioPrompts = 'custom_aio_prompts';
    case CompetitorDiscovery = 'competitor_discovery';

    /**
     * @return string[]
     */
    public static function keys(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::PdfExport => 'PDF export',
            self::ScheduledAudits => 'Scheduled audits',
            self::AuditHistory => 'Audit history',
            self::ReportSharing => 'Report sharing',
            self::WhiteLabel => 'White-label reports',
            self::CustomAioPrompts => 'Custom AIO prompts',
            self::CompetitorDiscovery => 'Competitor discovery',
        };
    }

    /**
     * Entitlements whose underlying feature is an explicit MVP exclusion
     * (CLAUDE.md): the flag is configurable and reported by the API so
     * plans can be priced and the UI can show what a tier includes, but no
     * white-label renderer or competitor crawler exists behind it yet.
     * Enforcement points will refuse them until they do.
     *
     * @return self[]
     */
    public static function notYetBuilt(): array
    {
        // CompetitorDiscovery left this list in section 42, and WhiteLabel
        // in section 48 — both of which built them. Nothing is currently
        // configurable-but-unbuilt; the method stays because the next
        // flag added ahead of its feature will need it again.
        return [];
    }

    public function isBuilt(): bool
    {
        return ! in_array($this, self::notYetBuilt(), true);
    }
}
