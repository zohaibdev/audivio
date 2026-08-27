import type { ReportPageFinding } from '../../types';

export function PageFindingsTable({ pages }: { pages: ReportPageFinding[] }) {
  if (pages.length === 0) return null;

  return (
    <div className="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-800">
      <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
        <thead className="bg-slate-50 dark:bg-slate-800/50">
          <tr>
            <th scope="col" className="px-4 py-2 text-left font-medium text-slate-500 dark:text-slate-400">URL</th>
            <th scope="col" className="px-4 py-2 text-left font-medium text-slate-500 dark:text-slate-400">Status</th>
            <th scope="col" className="px-4 py-2 text-left font-medium text-slate-500 dark:text-slate-400">Title</th>
            <th scope="col" className="px-4 py-2 text-left font-medium text-slate-500 dark:text-slate-400">Issues</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100 bg-white dark:divide-slate-800 dark:bg-slate-900">
          {pages.map((page) => (
            <tr key={page.url}>
              <td className="max-w-xs truncate px-4 py-2 text-slate-700 dark:text-slate-300">{page.url}</td>
              <td className="px-4 py-2 text-slate-500 dark:text-slate-400">{page.http_status}</td>
              <td className="max-w-xs truncate px-4 py-2 text-slate-500 dark:text-slate-400">{page.title ?? '—'}</td>
              <td className="px-4 py-2 text-slate-500 dark:text-slate-400">{page.issue_count}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
