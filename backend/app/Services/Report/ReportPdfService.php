<?php

namespace App\Services\Report;

use App\Models\Audit;
use App\Models\Report;
use App\Services\Plan\Entitlements;

/**
 * Renders the combined report to a PDF binary via dompdf. Self-contained
 * HTML/CSS only — dompdf can't fetch remote assets by default, and this
 * report has no need for any.
 *
 * Reachable only by the audit's owner, and rendered through the **same
 * visibility filter as the web report** (§48): export used to bypass the
 * plan's visibility percentage entirely, so a plan showing 30% on screen
 * handed over 100% as a download.
 */
class ReportPdfService
{
    public function __construct(
        private readonly ReportVisibilityFilter $filter,
        private readonly BrandingResolver $branding,
    ) {}

    public function render(Audit $audit, Report $report, ?Entitlements $entitlements = null): string
    {
        $content = $entitlements === null
            ? $report->content
            : $this->filter->filter($report->content, $entitlements);

        return app('dompdf.wrapper')
            ->loadView('reports.pdf', [
                'audit' => $audit,
                'content' => $content,
                'branding' => $this->branding->forAudit($audit),
            ])
            ->output();
    }
}
