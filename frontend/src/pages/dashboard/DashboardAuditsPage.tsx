import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { fetchMyAudits } from '../../api/websites';
import type { MyAudit } from '../../api/websites';
import { apiErrorMessage } from '../../api/client';
import { StatusBadge } from '../../components/ui/Badge';
import { Spinner } from '../../components/ui/Spinner';
import { ErrorState } from '../../components/ui/ErrorState';
import { EmptyState } from '../../components/ui/EmptyState';

/**
 * Serves the "Audits", "Audit History", and "Reports" nav concepts —
 * the backend has one audit-history dataset, not three, so this is
 * deliberately one real list rather than three pages showing identical
 * data. Clicking a row goes straight to that audit's report.
 */
export function DashboardAuditsPage() {
  const [audits, setAudits] = useState<MyAudit[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  function load() {
    setError(null);
    setAudits(null);
    fetchMyAudits()
      .then((page) => setAudits(page.data))
      .catch((err) => setError(apiErrorMessage(err)));
  }

  useEffect(load, []);

  return (
    <div>
      <h1 className="mb-1 text-2xl font-bold text-slate-900 dark:text-white">Audits</h1>
      <p className="mb-6 text-sm text-slate-500 dark:text-slate-400">Your full audit history and reports.</p>

      {error && <ErrorState message={error} onRetry={load} />}
      {!error && !audits && <Spinner />}
      {audits && audits.length === 0 && <EmptyState title="No audits yet" description="Run your first audit from the Overview page." />}

      {audits && audits.length > 0 && (
        <div className="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-800">
          <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
            <thead className="bg-slate-50 dark:bg-slate-800/50">
              <tr>
                <th scope="col" className="px-4 py-2 text-left font-medium text-slate-500 dark:text-slate-400">Website</th>
                <th scope="col" className="px-4 py-2 text-left font-medium text-slate-500 dark:text-slate-400">Status</th>
                <th scope="col" className="px-4 py-2 text-left font-medium text-slate-500 dark:text-slate-400">Score</th>
                <th scope="col" className="px-4 py-2 text-left font-medium text-slate-500 dark:text-slate-400">Date</th>
                <th scope="col" className="px-4 py-2 text-left font-medium text-slate-500 dark:text-slate-400" />
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 bg-white dark:divide-slate-800 dark:bg-slate-900">
              {audits.map((audit) => {
                const overall = audit.scores.find((s) => s.category === 'overall')?.score;
                return (
                  <tr key={audit.id}>
                    <td className="px-4 py-2 text-slate-700 dark:text-slate-300">{audit.website.domain}</td>
                    <td className="px-4 py-2"><StatusBadge status={audit.status} /></td>
                    <td className="px-4 py-2 text-slate-500 dark:text-slate-400">{overall ?? '—'}</td>
                    <td className="px-4 py-2 text-slate-500 dark:text-slate-400">{new Date(audit.created_at).toLocaleDateString()}</td>
                    <td className="px-4 py-2 text-right">
                      <Link to={`/audits/${audit.id}`} className="font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                        View report →
                      </Link>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
