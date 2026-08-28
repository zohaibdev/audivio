import { useState } from 'react';
import { IssueCard } from './IssueCard';
import { ScreenshotViewer } from './ScreenshotViewer';
import type { ServicePageFinding } from '../../types';

/** One crawled page's findings for a single service, collapsed by default
 * so a 25-page report stays readable. */
export function PageFindingCard({ page }: { page: ServicePageFinding }) {
  const [expanded, setExpanded] = useState(false);
  const critical = page.severity_summary.critical ?? 0;
  const high = page.severity_summary.high ?? 0;

  return (
    <div className="rounded-lg border border-slate-200 dark:border-slate-800">
      <button
        type="button"
        onClick={() => setExpanded((open) => !open)}
        className="flex w-full items-center justify-between gap-3 p-4 text-left"
      >
        <div className="min-w-0">
          <p className="truncate text-sm font-medium text-slate-900 dark:text-white">{page.title ?? page.url}</p>
          <p className="truncate font-mono text-xs text-slate-500 dark:text-slate-400">{page.url}</p>
        </div>
        <div className="flex shrink-0 items-center gap-3 text-xs">
          {critical > 0 && <span className="font-semibold text-red-600 dark:text-red-400">{critical} critical</span>}
          {high > 0 && <span className="font-semibold text-amber-600 dark:text-amber-400">{high} high</span>}
          <span className="text-slate-500 dark:text-slate-400">{page.issue_count} total</span>
          <span className="text-slate-400">{expanded ? '▾' : '▸'}</span>
        </div>
      </button>

      {expanded && (
        <div className="border-t border-slate-200 p-4 dark:border-slate-800">
          <ScreenshotViewer screenshots={page.screenshots} alt={page.title ?? page.url} />
          <div className="mt-4 space-y-3">
            {page.issues.map((issue, index) => (
              <IssueCard key={`${issue.code}-${index}`} issue={issue} />
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
