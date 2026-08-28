<?php

namespace App\Services\Report;

use App\Models\Audit;

/**
 * Renders one *service* report to a PDF, from the same
 * ServiceReportBuilder output the web report renders from — so the two can
 * never disagree about scores, issues, or affected pages (§35).
 *
 * Always the full report: PDF export is only reachable after a
 * full-access check in AuditController, exactly like the combined PDF.
 */
class ServiceReportPdfService
{
    public function __construct(private readonly ServiceReportBuilder $builder) {}

    /**
     * @return array{filename: string, pdf: string}|null Null when the
     *                                                   service did not run for this audit — the caller turns that
     *                                                   into a 404 rather than emitting an empty PDF.
     */
    public function render(Audit $audit, string $service): ?array
    {
        $reports = $this->builder->buildAll($audit);

        if (! isset($reports[$service])) {
            return null;
        }

        $pdf = app('dompdf.wrapper')
            ->loadView('reports.service-pdf', [
                'audit' => $audit,
                'report' => $reports[$service],
            ])
            ->output();

        return [
            'filename' => "{$service}-report.pdf",
            'pdf' => $pdf,
        ];
    }
}
