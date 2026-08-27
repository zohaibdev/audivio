import { useEffect, useState } from 'react';
import * as adminApi from '../../api/admin';
import { apiErrorMessage } from '../../api/client';
import { Spinner } from '../../components/ui/Spinner';
import { ErrorState } from '../../components/ui/ErrorState';
import { EmptyState } from '../../components/ui/EmptyState';
import { Pagination } from '../../components/ui/Pagination';
import type { Paginated } from '../../types';

export function AdminWebsitesPage() {
  const [websites, setWebsites] = useState<Paginated<{ id: number; url: string; domain: string; audits_count: number }> | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);

  function load() {
    setError(null);
    adminApi
      .fetchWebsites(page)
      .then(setWebsites)
      .catch((err) => setError(apiErrorMessage(err)));
  }

  useEffect(load, [page]);

  if (error) return <ErrorState message={error} onRetry={load} />;
  if (!websites) return <Spinner />;

  return (
    <div>
      <h1 className="mb-6 text-2xl font-bold text-slate-900 dark:text-white">Websites</h1>
      {websites.data.length === 0 ? (
        <EmptyState title="No websites yet" />
      ) : (
        <div className="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-800">
          <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
            <thead className="bg-slate-50 dark:bg-slate-800/50">
              <tr>
                <th className="px-4 py-2 text-left font-medium text-slate-500 dark:text-slate-400">Domain</th>
                <th className="px-4 py-2 text-left font-medium text-slate-500 dark:text-slate-400">URL</th>
                <th className="px-4 py-2 text-left font-medium text-slate-500 dark:text-slate-400">Audits</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 bg-white dark:divide-slate-800 dark:bg-slate-900">
              {websites.data.map((website) => (
                <tr key={website.id}>
                  <td className="px-4 py-2 text-slate-700 dark:text-slate-300">{website.domain}</td>
                  <td className="max-w-xs truncate px-4 py-2 text-slate-500 dark:text-slate-400">{website.url}</td>
                  <td className="px-4 py-2 text-slate-500 dark:text-slate-400">{website.audits_count}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <Pagination currentPage={websites.current_page} lastPage={websites.last_page} total={websites.total} onChange={setPage} />
        </div>
      )}
    </div>
  );
}
