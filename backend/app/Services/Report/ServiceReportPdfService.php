<?php

namespace App\Services\Report;

use App\Models\Audit;
use App\Services\Plan\Entitlements;

/**
 * Renders one *service* report to a PDF, from the same
 * ServiceReportBuilder output the web report renders from — so the two can
 * never disagree about scores, issues, or affected pages (§35).
 *
 * Reachable only by the audit's owner (checked in AuditController), and
 * rendered through the **same visibility filter as the web report** —
 * ownership decides whose plan applies, the plan decides how much (§40).
 */
class ServiceReportPdfService
{
    public function __construct(
        private readonly ServiceReportBuilder $builder,
        private readonly ServiceReportVisibilityFilter $filter,
        private readonly BrandingResolver $branding,
    ) {}

    /**
     * @return array{filename: string, pdf: string}|null Null when the
     *                                                   service did not run for this audit — the caller turns that
     *                                                   into a 404 rather than emitting an empty PDF.
     */
    public function render(Audit $audit, string $service, ?Entitlements $entitlements = null): ?array
    {
        $reports = $this->builder->buildAll($audit);

        if (! isset($reports[$service])) {
            return null;
        }

        // Filtered by the owner's own plan (§48). Export used to render
        // the full report unconditionally, so an owner on a plan that
        // shows 30% of a report on the web could download 100% of it as a
        // PDF — the visibility limit was one button away from being
        // bypassed. Same data, same filter, same answer in both places.
        $report = $entitlements === null
            ? $reports[$service]
            : $this->filter->filter($reports[$service], $entitlements);

        $pdf = app('dompdf.wrapper')
            ->loadView('reports.service-pdf', [
                'audit' => $audit,
                'report' => $report,
                'branding' => $this->branding->forAudit($audit),
            ])
            ->output();

        return [
            'filename' => "{$service}-report.pdf",
            'pdf' => $pdf,
        ];
    }
}
