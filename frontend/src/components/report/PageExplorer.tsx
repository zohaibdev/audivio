import { Fragment, useMemo, useState } from 'react';
import { Card } from '../ui/Card';
import { EmptyState } from '../ui/EmptyState';
import { ScreenshotViewer } from './ScreenshotViewer';
import type { ExplorerPage } from '../../types';

type SortKey = 'severity' | 'score' | 'issues' | 'url';

/**
 * Same bands the service score cards use, so a page scoring 55 reads as
 * the same kind of result wherever it appears.
 */
function scoreTone(score: number): string {
  if (score >= 90) return 'text-emerald-600 dark:text-emerald-400';
  if (score >= 50) return 'text-amber-600 dark:text-amber-400';
  return 'text-red-600 dark:text-red-400';
}

/**
 * Cross-service view of every crawled page — search, filter by service,
 * sort, and drill into any page's screenshots. Answers "which of my pages
 * are worst", which the per-service reports can't show on their own.
 */
export function PageExplorer({ pages }: { pages: ExplorerPage[] }) {
  const [search, setSearch] = useState('');
  const [serviceFilter, setServiceFilter] = useState<string>('all');
  const [sort, setSort] = useState<SortKey>('severity');
  const [openUrl, setOpenUrl] = useState<string | null>(null);

  const services = useMemo(() => {
    const keys = new Set<string>();
    pages.forEach((page) => Object.keys(page.issues_by_service).forEach((key) => keys.add(key)));
    return [...keys].sort();
  }, [pages]);

  const visible = useMemo(() => {
    const filtered = pages.filter((page) => {
      const matchesSearch =
        search === '' ||
        page.url.toLowerCase().includes(search.toLowerCase()) ||
        (page.title ?? '').toLowerCase().includes(search.toLowerCase());
      const matchesService = serviceFilter === 'all' || (page.issues_by_service[serviceFilter] ?? 0) > 0;
      return matchesSearch && matchesService;
    });

    return [...filtered].sort((a, b) => {
      if (sort === 'url') return a.url.localeCompare(b.url);
      if (sort === 'issues') return b.issue_count - a.issue_count;
      // Worst score first: the same direction as every other sort here,
      // so switching between them never reverses what "top" means.
      if (sort === 'score') return a.score - b.score;
      return (
        (b.severity_summary.critical ?? 0) - (a.severity_summary.critical ?? 0) ||
        (b.severity_summary.high ?? 0) - (a.severity_summary.high ?? 0) ||
        b.issue_count - a.issue_count
      );
    });
  }, [pages, search, serviceFilter, sort]);

  return (
    <Card>
      <h3 className="font-semibold text-ink">Page explorer</h3>
      <p className="mt-1 text-sm text-muted">
        Every page we crawled, worst first. {pages.length} page{pages.length === 1 ? '' : 's'} analyzed.
      </p>

      <div className="mt-4 flex flex-wrap gap-2">
        <input
          type="search"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          placeholder="Search pages…"
          aria-label="Search pages"
          className="min-w-48 flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white"
        />
        <select
          value={serviceFilter}
          onChange={(event) => setServiceFilter(event.target.value)}
          aria-label="Filter by service"
          className="rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
        >
          <option value="all">All services</option>
          {services.map((service) => (
            <option key={service} value={service}>
              {service}
            </option>
          ))}
        </select>
        <select
          value={sort}
          onChange={(event) => setSort(event.target.value as SortKey)}
          aria-label="Sort pages"
          className="rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
        >
          <option value="severity">Sort: worst first</option>
          <option value="score">Sort: lowest score</option>
          <option value="issues">Sort: most issues</option>
          <option value="url">Sort: URL</option>
        </select>
      </div>

      {visible.length === 0 ? (
        <div className="mt-4">
          <EmptyState title="No pages match your filters" />
        </div>
      ) : (
        <div className="mt-4 overflow-x-auto">
          <table className="w-full min-w-[640px] text-left text-sm">
            <thead className="border-b border-line text-xs uppercase text-muted dark:border-line dark:text-faint">
              <tr>
                <th className="py-2 pr-3">Page</th>
                <th className="px-3 py-2">Score</th>
                <th className="px-3 py-2">Status</th>
                <th className="px-3 py-2">Critical</th>
                <th className="px-3 py-2">High</th>
                <th className="px-3 py-2">Total</th>
                <th className="px-3 py-2" />
              </tr>
            </thead>
            <tbody className="divide-y divide-line">
              {visible.map((page) => (
                <Fragment key={page.url}>
                  <tr>
                    <td className="max-w-xs py-2 pr-3">
                      <p className="truncate font-medium text-ink">{page.title ?? '—'}</p>
                      <p className="truncate font-mono text-xs text-muted">{page.url}</p>
                    </td>
                    <td className="px-3 py-2">
                      <span className={`font-semibold ${scoreTone(page.score)}`}>{page.score}</span>
                    </td>
                    <td className="px-3 py-2 text-muted">{page.http_status}</td>
                    <td className="px-3 py-2 font-medium text-red-600 dark:text-red-400">
                      {page.severity_summary.critical ?? 0}
                    </td>
                    <td className="px-3 py-2 font-medium text-amber-600 dark:text-amber-400">
                      {page.severity_summary.high ?? 0}
                    </td>
                    <td className="px-3 py-2 text-muted">{page.issue_count}</td>
                    <td className="px-3 py-2">
                      <button
                        type="button"
                        onClick={() => setOpenUrl(openUrl === page.url ? null : page.url)}
                        className="text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400"
                      >
                        {openUrl === page.url ? 'Hide' : 'View'}
                      </button>
                    </td>
                  </tr>
                  {openUrl === page.url && (
                    <tr>
                      <td colSpan={7} className="bg-slate-50 p-4 dark:bg-slate-800/40">
                        <ScreenshotViewer screenshots={page.screenshots} alt={page.title ?? page.url} />
                        <div className="mt-3 flex flex-wrap gap-2">
                          {Object.entries(page.issues_by_service).map(([service, count]) => (
                            <span
                              key={service}
                              className="rounded-full bg-white px-2.5 py-0.5 text-xs text-slate-600 dark:bg-slate-900 dark:text-slate-300"
                            >
                              {service}: {count}
                            </span>
                          ))}
                        </div>
                      </td>
                    </tr>
                  )}
                </Fragment>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  );
}
