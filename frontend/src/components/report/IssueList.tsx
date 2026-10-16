import { useState } from 'react';
import { SeverityBadge } from '../ui/Badge';
import { EmptyState } from '../ui/EmptyState';
import type { ReportIssue } from '../../types';

function IssueRow({ issue }: { issue: ReportIssue }) {
  const [expanded, setExpanded] = useState(false);

  return (
    <li className="border-b border-slate-100 py-4 last:border-0 dark:border-line">
      <button
        type="button"
        onClick={() => setExpanded((value) => !value)}
        className="flex w-full items-start justify-between gap-4 text-left"
        aria-expanded={expanded}
      >
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <SeverityBadge severity={issue.severity} />
            <span className="text-xs uppercase tracking-wide text-faint">{issue.category.replace('_', ' ')}</span>
          </div>
          <p className="mt-1 font-medium text-ink">{issue.message}</p>
          {issue.page_url && <p className="mt-0.5 truncate text-xs text-muted">{issue.page_url}</p>}
        </div>
        <span aria-hidden="true" className="mt-1 shrink-0 text-faint">
          {expanded ? '−' : '+'}
        </span>
      </button>
      {expanded && (
        <div className="mt-3 space-y-2 rounded-md bg-slate-50 p-4 text-sm dark:bg-slate-800/50">
          <p>
            <span className="font-medium text-ink">Why it matters: </span>
            <span className="text-muted">
              {issue.severity === 'critical' || issue.severity === 'high'
                ? 'This issue can meaningfully hurt conversions, accessibility compliance, or search visibility.'
                : 'This is a lower-priority improvement worth addressing when higher-severity issues are resolved.'}
            </span>
          </p>
          {issue.recommendation && (
            <p>
              <span className="font-medium text-ink">Recommendation: </span>
              <span className="text-muted">{issue.recommendation}</span>
            </p>
          )}
          <p className="text-xs text-faint">
            Code: <code>{issue.code}</code>
          </p>
        </div>
      )}
    </li>
  );
}

export function IssueList({ issues, title }: { issues: ReportIssue[]; title: string }) {
  if (issues.length === 0) {
    return (
      <div>
        <h2 className="mb-3 text-lg font-semibold text-ink">{title}</h2>
        <EmptyState title="No issues in this category" description="Nice — nothing to flag here." />
      </div>
    );
  }

  return (
    <div>
      <h2 className="mb-3 text-lg font-semibold text-ink">
        {title} <span className="text-sm font-normal text-muted">({issues.length})</span>
      </h2>
      <ul className="rounded-xl border border-line bg-white px-4 dark:border-line dark:bg-slate-900">
        {issues.map((issue, index) => (
          <IssueRow key={`${issue.code}-${index}`} issue={issue} />
        ))}
      </ul>
    </div>
  );
}
