import { Link } from 'react-router-dom';
import { ReportOverviewCard } from './ReportOverviewCard';
import { IssueList } from './IssueList';
import { RecommendationList } from './RecommendationList';
import { PageFindingsTable } from './PageFindingsTable';
import { Button } from '../ui/Button';
import type { Report } from '../../types';

export function ReportView({
  report,
  onDownloadPdf,
  canDownloadPdf,
}: {
  report: Report;
  onDownloadPdf?: () => void;
  canDownloadPdf?: boolean;
}) {
  const criticalAndHigh = report.issues.filter((issue) => issue.severity === 'critical' || issue.severity === 'high');
  const other = report.issues.filter((issue) => issue.severity !== 'critical' && issue.severity !== 'high');
  const aiCategories = Object.entries(report.ai_analysis ?? {});

  return (
    <div className="flex flex-col gap-8">
      <ReportOverviewCard overview={report.overview} />

      {!report.is_full_access && (
        <div className="rounded-xl border border-indigo-200 bg-indigo-50 p-6 text-center dark:border-indigo-900 dark:bg-indigo-950/40">
          <p className="font-medium text-indigo-800 dark:text-indigo-300">
            {report.hidden_issue_count ?? 0} more issue(s) and {report.hidden_recommendation_count ?? 0} more recommendation(s) are hidden.
          </p>
          <p className="mt-1 text-sm text-indigo-700 dark:text-indigo-400">Sign up for a free account to see your full report.</p>
          <Link
            to="/signup"
            className="mt-4 inline-block rounded-md bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-700"
          >
            Unlock full report
          </Link>
        </div>
      )}

      {report.is_full_access && onDownloadPdf && (
        <div className="flex justify-end">
          <Button variant="secondary" onClick={onDownloadPdf} disabled={!canDownloadPdf} title={canDownloadPdf ? undefined : 'PDF export is not included in your current plan'}>
            Download PDF report
          </Button>
        </div>
      )}

      <IssueList issues={criticalAndHigh} title="Critical & High Priority Issues" />
      {report.is_full_access && <IssueList issues={other} title="Other Issues" />}

      <div>
        <h2 className="mb-3 text-lg font-semibold text-slate-900 dark:text-white">Recommendations</h2>
        <RecommendationList recommendations={report.recommendations} />
      </div>

      {report.is_full_access && aiCategories.length > 0 && (
        <div>
          <h2 className="mb-3 text-lg font-semibold text-slate-900 dark:text-white">
            AI Analysis <span className="text-sm font-normal text-slate-500">(AI-generated)</span>
          </h2>
          <div className="space-y-3">
            {aiCategories.map(([category, text]) => (
              <div key={category} className="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                  {category.replace('_', ' ')}
                </p>
                <p className="mt-1 whitespace-pre-wrap text-sm text-slate-700 dark:text-slate-300">{text}</p>
              </div>
            ))}
          </div>
        </div>
      )}

      {report.is_full_access && report.pages && report.pages.length > 0 && (
        <div>
          <h2 className="mb-3 text-lg font-semibold text-slate-900 dark:text-white">Page-Level Findings</h2>
          <PageFindingsTable pages={report.pages} />
        </div>
      )}
    </div>
  );
}
