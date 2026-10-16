import { useEffect, useState } from 'react';
import * as adminApi from '../../api/admin';
import { apiErrorMessage } from '../../api/client';
import { Spinner } from '../../components/ui/Spinner';
import { ErrorState } from '../../components/ui/ErrorState';
import { EmptyState } from '../../components/ui/EmptyState';
import { Pagination } from '../../components/ui/Pagination';
import type { Paginated } from '../../types';

export function AdminWebsitesPage() {
  const [websites, setWebsites] = useState<Paginated<{ id: number; primary_url: string; domain: string; name: string | null; audits_count: number }> | null>(null);
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
      <h1 className="mb-6 text-2xl font-bold text-ink">Websites</h1>
      {websites.data.length === 0 ? (
        <EmptyState title="No websites yet" />
      ) : (
        <div className="overflow-x-auto rounded-xl border border-line">
          <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-line">
            <thead className="bg-surface-2">
              <tr>
                <th className="px-4 py-2 text-left font-medium text-muted">Domain</th>
                <th className="px-4 py-2 text-left font-medium text-muted">URL</th>
                <th className="px-4 py-2 text-left font-medium text-muted">Audits</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-line bg-white dark:divide-line dark:bg-slate-900">
              {websites.data.map((website) => (
                <tr key={website.id}>
                  <td className="px-4 py-2 text-muted">{website.domain}</td>
                  <td className="max-w-xs truncate px-4 py-2 text-muted">{website.primary_url}</td>
                  <td className="px-4 py-2 text-muted">{website.audits_count}</td>
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
