<?php

namespace App\Services\Report;

use App\Models\Audit;
use App\Models\Report;

/**
 * Renders the full report (never the filtered anonymous/free view — PDF
 * export is only ever reached after a full-access check, see
 * AuditController::reportPdf) to a PDF binary via dompdf. Self-contained
 * HTML/CSS only — dompdf can't fetch remote assets by default, and this
 * report has no need for any.
 */
class ReportPdfService
{
    public function render(Audit $audit, Report $report): string
    {
        return app('dompdf.wrapper')
            ->loadView('reports.pdf', [
                'audit' => $audit,
                'content' => $report->content,
            ])
            ->output();
    }
}
