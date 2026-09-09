import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import * as adminApi from '../../api/admin';
import { apiErrorMessage } from '../../api/client';
import { Spinner } from '../../components/ui/Spinner';
import { ErrorState } from '../../components/ui/ErrorState';
import { EmptyState } from '../../components/ui/EmptyState';
import { Pagination } from '../../components/ui/Pagination';
import { StatusBadge } from '../../components/ui/Badge';
import type { Paginated } from '../../types';

interface AdminAuditRow {
  id: number;
  status: string;
  website?: { url: string; domain: string };
  created_at: string;
}

export function AdminAuditsPage() {
  const [audits, setAudits] = useState<Paginated<AdminAuditRow> | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);

  function load() {
    setError(null);
    adminApi
      .fetchAudits(page)
      .then((result) => setAudits(result as unknown as Paginated<AdminAuditRow>))
      .catch((err) => setError(apiErrorMessage(err)));
  }

  useEffect(load, [page]);

  if (error) return <ErrorState message={error} onRetry={load} />;
  if (!audits) return <Spinner />;

  return (
    <div>
      <h1 className="mb-6 text-2xl font-bold text-ink">Audits</h1>
      {audits.data.length === 0 ? (
        <EmptyState title="No audits yet" />
      ) : (
        <div className="overflow-x-auto rounded-xl border border-line">
          <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-line">
            <thead className="bg-surface-2">
              <tr>
                <th className="px-4 py-2 text-left font-medium text-muted">Website</th>
                <th className="px-4 py-2 text-left font-medium text-muted">Status</th>
                <th className="px-4 py-2 text-left font-medium text-muted">Created</th>
                <th className="px-4 py-2" />
              </tr>
            </thead>
            <tbody className="divide-y divide-line bg-white dark:divide-line dark:bg-slate-900">
              {audits.data.map((audit) => (
                <tr key={audit.id}>
                  <td className="px-4 py-2 text-muted">{audit.website?.domain ?? '—'}</td>
                  <td className="px-4 py-2"><StatusBadge status={audit.status} /></td>
                  <td className="px-4 py-2 text-muted">{new Date(audit.created_at).toLocaleString()}</td>
                  <td className="px-4 py-2 text-right">
                    <Link to={`/audits/${audit.id}`} className="font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                      View →
                    </Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <Pagination currentPage={audits.current_page} lastPage={audits.last_page} total={audits.total} onChange={setPage} />
        </div>
      )}
    </div>
  );
}
