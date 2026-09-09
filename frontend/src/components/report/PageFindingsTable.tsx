import type { ReportPageFinding } from '../../types';

export function PageFindingsTable({ pages }: { pages: ReportPageFinding[] }) {
  if (pages.length === 0) return null;

  return (
    <div className="overflow-x-auto rounded-xl border border-line">
      <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-line">
        <thead className="bg-surface-2">
          <tr>
            <th scope="col" className="px-4 py-2 text-left font-medium text-muted">URL</th>
            <th scope="col" className="px-4 py-2 text-left font-medium text-muted">Status</th>
            <th scope="col" className="px-4 py-2 text-left font-medium text-muted">Title</th>
            <th scope="col" className="px-4 py-2 text-left font-medium text-muted">Issues</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-line bg-white dark:divide-line dark:bg-slate-900">
          {pages.map((page) => (
            <tr key={page.url}>
              <td className="max-w-xs truncate px-4 py-2 text-muted">{page.url}</td>
              <td className="px-4 py-2 text-muted">{page.http_status}</td>
              <td className="max-w-xs truncate px-4 py-2 text-muted">{page.title ?? '—'}</td>
              <td className="px-4 py-2 text-muted">{page.issue_count}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
