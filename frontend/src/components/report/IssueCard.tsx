import { useState } from 'react';
import { Badge, SeverityBadge } from '../ui/Badge';
import type { ServiceIssue } from '../../types';

/**
 * One finding, written for a non-technical reader first: what's wrong,
 * why it matters, which pages, then — only on request — the raw evidence.
 * Progressive disclosure is the point; the technical detail is present but
 * never the first thing in the reader's way.
 */
export function IssueCard({ issue }: { issue: ServiceIssue }) {
  const [showTechnical, setShowTechnical] = useState(false);

  const evidenceEntries = Object.entries(issue.evidence ?? {}).filter(
    // `affected_pages`/`affected_page_count` are already rendered above as
    // a first-class list; repeating them as raw evidence is just noise.
    ([key]) => !['affected_pages', 'affected_page_count', 'instances'].includes(key),
  );

  const pages = issue.affected_pages ?? [];

  return (
    <div className="rounded-lg border border-slate-200 p-4 dark:border-slate-800">
      <div className="flex flex-wrap items-center gap-2">
        <SeverityBadge severity={issue.severity} />
        <Badge tone="gray">{issue.category}</Badge>
        {issue.detected_by === 'ai' && <Badge tone="purple">AI-assisted</Badge>}
        {issue.is_site_wide && <Badge tone="blue">Site-wide</Badge>}
      </div>

      <h4 className="mt-2 font-semibold text-slate-900 dark:text-white">{issue.title}</h4>
      <p className="mt-1 text-sm text-slate-600 dark:text-slate-300">{issue.message}</p>

      {issue.recommendation && (
        <div className="mt-3 rounded-md bg-indigo-50 p-3 dark:bg-indigo-950/40">
          <p className="text-xs font-semibold uppercase tracking-wide text-indigo-700 dark:text-indigo-300">How to fix it</p>
          <p className="mt-1 text-sm text-slate-700 dark:text-slate-200">{issue.recommendation}</p>
        </div>
      )}

      <p className="mt-3 text-xs text-slate-500 dark:text-slate-400">
        {issue.affected_page_count === 0
          ? 'Affects the site as a whole'
          : `Affects ${issue.affected_page_count} page${issue.affected_page_count === 1 ? '' : 's'}`}
      </p>

      {pages.length > 0 && (
        <ul className="mt-2 space-y-1">
          {pages.slice(0, 5).map((url) => (
            <li key={url} className="truncate font-mono text-xs text-slate-500 dark:text-slate-400">
              {url}
            </li>
          ))}
          {pages.length < issue.affected_page_count && (
            <li className="text-xs italic text-slate-400">
              …and {issue.affected_page_count - pages.length} more
            </li>
          )}
        </ul>
      )}

      {evidenceEntries.length > 0 && (
        <div className="mt-3">
          <button
            type="button"
            onClick={() => setShowTechnical((open) => !open)}
            className="text-xs font-medium text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200"
          >
            {showTechnical ? '▾ Hide technical details' : '▸ Technical details'}
          </button>
          {showTechnical && (
            <dl className="mt-2 space-y-1 rounded-md bg-slate-50 p-3 dark:bg-slate-800/50">
              <div className="flex gap-2 text-xs">
                <dt className="font-medium text-slate-500 dark:text-slate-400">Rule</dt>
                <dd className="font-mono text-slate-700 dark:text-slate-200">{issue.code}</dd>
              </div>
              {evidenceEntries.map(([key, value]) => (
                <div key={key} className="flex gap-2 text-xs">
                  <dt className="font-medium text-slate-500 dark:text-slate-400">{key}</dt>
                  <dd className="break-all font-mono text-slate-700 dark:text-slate-200">
                    {typeof value === 'object' ? JSON.stringify(value) : String(value)}
                  </dd>
                </div>
              ))}
            </dl>
          )}
        </div>
      )}
    </div>
  );
}
