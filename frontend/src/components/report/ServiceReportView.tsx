import { useState } from 'react';
import { Card } from '../ui/Card';
import { Badge } from '../ui/Badge';
import { EmptyState } from '../ui/EmptyState';
import { IssueCard } from './IssueCard';
import { PageFindingCard } from './PageFindingCard';
import type { Severity, ServiceReport } from '../../types';

const SEVERITY_ORDER: Severity[] = ['critical', 'high', 'medium', 'low'];

const SEVERITY_LABEL: Record<string, string> = {
  critical: 'Critical issues',
  high: 'High priority',
  medium: 'Medium priority',
  low: 'Low priority',
};

/**
 * The shared service-report template (§35). Every service renders through
 * this so the reading experience is identical across SEO, Performance,
 * Accessibility and the rest, while the underlying findings stay
 * service-specific.
 */
export function ServiceReportView({
  report,
  onDownloadPdf,
  canDownloadPdf,
}: {
  report: ServiceReport;
  onDownloadPdf?: () => void;
  canDownloadPdf?: boolean;
}) {
  const [severityFilter, setSeverityFilter] = useState<Severity | 'all'>('all');
  const [categoryFilter, setCategoryFilter] = useState<string>('all');

  if (!report.is_applicable) {
    return (
      <Card>
        <h2 className="text-lg font-semibold text-slate-900 dark:text-white">{report.name}</h2>
        <Badge tone="gray">Not applicable</Badge>
        <p className="mt-3 text-sm text-slate-600 dark:text-slate-300">{report.not_applicable_reason}</p>
      </Card>
    );
  }

  const visibleIssues = report.issues.filter(
    (issue) =>
      (severityFilter === 'all' || issue.severity === severityFilter) &&
      (categoryFilter === 'all' || issue.category === categoryFilter),
  );

  const quickWins = report.recommendations.filter((r) => r.effort === 'Low');

  return (
    <div className="flex flex-col gap-6">
      {/* Score + what it means */}
      <Card>
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h2 className="text-xl font-bold text-slate-900 dark:text-white">{report.name}</h2>
            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
              {report.severity_summary.total} issue{report.severity_summary.total === 1 ? '' : 's'} found
            </p>
          </div>
          <div className="flex items-start gap-4">
            <div className="text-right">
              <p className="text-4xl font-bold text-slate-900 dark:text-white">{report.score ?? 'N/A'}</p>
              <p className="text-xs uppercase tracking-wide text-slate-400">out of 100</p>
            </div>
            {onDownloadPdf && (
              <button
                type="button"
                onClick={onDownloadPdf}
                disabled={!canDownloadPdf}
                className="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
              >
                Download PDF
              </button>
            )}
          </div>
        </div>

        {report.what_this_means && (
          <p className="mt-4 text-sm text-slate-600 dark:text-slate-300">{report.what_this_means}</p>
        )}

        {report.coverage_note && (
          <div className="mt-4 rounded-md border border-amber-300 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950/40">
            <p className="text-xs font-semibold uppercase tracking-wide text-amber-800 dark:text-amber-300">
              Incomplete assessment
            </p>
            <p className="mt-1 text-sm text-amber-900 dark:text-amber-200">{report.coverage_note}</p>
          </div>
        )}

        <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
          {SEVERITY_ORDER.map((severity) => (
            <div key={severity} className="rounded-md bg-slate-50 p-3 dark:bg-slate-800/50">
              <p className="text-2xl font-bold text-slate-900 dark:text-white">{report.severity_summary[severity] ?? 0}</p>
              <p className="text-xs capitalize text-slate-500 dark:text-slate-400">{severity}</p>
            </div>
          ))}
        </div>
      </Card>

      {/* Quick wins — low-effort fixes surfaced ahead of the full list */}
      {quickWins.length > 0 && (
        <Card>
          <h3 className="font-semibold text-slate-900 dark:text-white">Quick wins</h3>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Low-effort fixes you can make first.
          </p>
          <ul className="mt-3 space-y-2">
            {quickWins.slice(0, 5).map((rec, index) => (
              <li key={`${rec.title}-${index}`} className="rounded-md border border-slate-200 p-3 dark:border-slate-800">
                <p className="text-sm font-medium text-slate-900 dark:text-white">{rec.title}</p>
                <p className="mt-1 text-sm text-slate-600 dark:text-slate-300">{rec.recommended_action}</p>
              </li>
            ))}
          </ul>
        </Card>
      )}

      {/* Category breakdown */}
      {report.categories.length > 0 && (
        <Card>
          <h3 className="font-semibold text-slate-900 dark:text-white">Category breakdown</h3>
          <div className="mt-3 flex flex-wrap gap-2">
            {report.categories.map((category) => (
              <button
                key={category.name}
                type="button"
                onClick={() => setCategoryFilter(categoryFilter === category.name ? 'all' : category.name)}
                className={`rounded-full border px-3 py-1 text-sm transition ${
                  categoryFilter === category.name
                    ? 'border-indigo-500 bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300'
                    : 'border-slate-300 text-slate-600 hover:border-slate-400 dark:border-slate-700 dark:text-slate-300'
                }`}
              >
                {category.name} ({category.issue_count})
              </button>
            ))}
          </div>
        </Card>
      )}

      {/* Issues, filterable */}
      <Card>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h3 className="font-semibold text-slate-900 dark:text-white">Issues</h3>
          <div className="flex flex-wrap gap-1">
            {(['all', ...SEVERITY_ORDER] as const).map((severity) => (
              <button
                key={severity}
                type="button"
                onClick={() => setSeverityFilter(severity)}
                className={`rounded-md px-2.5 py-1 text-xs font-medium capitalize transition ${
                  severityFilter === severity
                    ? 'bg-indigo-600 text-white'
                    : 'text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800'
                }`}
              >
                {severity === 'all' ? 'All' : SEVERITY_LABEL[severity]}
              </button>
            ))}
          </div>
        </div>

        <div className="mt-4 space-y-3">
          {visibleIssues.length === 0 ? (
            <EmptyState
              title={report.issues.length === 0 ? 'No issues found in this area' : 'No issues match these filters'}
            />
          ) : (
            visibleIssues.map((issue, index) => <IssueCard key={`${issue.code}-${index}`} issue={issue} />)
          )}
        </div>

        {typeof report.hidden_issue_count === 'number' && report.hidden_issue_count > 0 && (
          <p className="mt-4 rounded-md bg-slate-50 p-3 text-sm text-slate-600 dark:bg-slate-800/50 dark:text-slate-300">
            {report.hidden_issue_count} more issue{report.hidden_issue_count === 1 ? '' : 's'} found. Sign in as the
            owner of this audit to see the full report.
          </p>
        )}
      </Card>

      {/* AI analysis, clearly labelled as interpretation */}
      {report.ai.used && (
        <Card>
          <h3 className="font-semibold text-slate-900 dark:text-white">AI analysis</h3>
          {report.ai.available === false ? (
            <p className="mt-2 text-sm text-amber-800 dark:text-amber-300">
              AI analysis was not available for this audit{report.ai.error ? `: ${report.ai.error}` : '.'}
            </p>
          ) : report.ai.analysis ? (
            <p className="mt-2 whitespace-pre-wrap text-sm text-slate-600 dark:text-slate-300">{report.ai.analysis}</p>
          ) : (
            <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
              AI analysis is included in the full report.
            </p>
          )}
        </Card>
      )}

      {/* Page-by-page findings */}
      <Card>
        <h3 className="font-semibold text-slate-900 dark:text-white">Page-by-page findings</h3>
        {report.pages.length === 0 ? (
          <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
            {typeof report.hidden_page_count === 'number' && report.hidden_page_count > 0
              ? `Findings for ${report.hidden_page_count} page(s) are available in the full report.`
              : 'No page-specific findings in this area.'}
          </p>
        ) : (
          <div className="mt-3 space-y-3">
            {report.pages.map((page) => (
              <PageFindingCard key={page.url} page={page} />
            ))}
          </div>
        )}
      </Card>
    </div>
  );
}
