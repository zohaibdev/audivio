import { useEffect, useState } from 'react';
import { fetchMyAudits } from '../../api/websites';
import type { MyAudit } from '../../api/websites';
import { apiErrorMessage } from '../../api/client';
import { useAuth } from '../../contexts/AuthContext';
import { StatCard } from '../../components/ui/StatCard';
import { Spinner } from '../../components/ui/Spinner';
import { ErrorState } from '../../components/ui/ErrorState';

export function DashboardUsagePage() {
  const { user } = useAuth();
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

  if (error) return <ErrorState message={error} onRetry={load} />;
  if (!audits) return <Spinner />;

  const plan = user?.subscription?.plan;
  const today = new Date().toDateString();
  const auditsToday = audits.filter((a) => new Date(a.created_at).toDateString() === today).length;
  const dailyLimit = plan?.daily_audit_limit ?? null;
  const remaining = dailyLimit !== null ? Math.max(0, dailyLimit - auditsToday) : null;

  return (
    <div>
      <h1 className="mb-1 text-2xl font-bold text-slate-900 dark:text-white">Usage</h1>
      <p className="mb-6 text-sm text-slate-500 dark:text-slate-400">
        {plan ? `You're on the ${plan.name} plan.` : 'No active plan found.'}
      </p>

      <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
        <StatCard label="Audits used today" value={auditsToday} />
        <StatCard label="Daily limit" value={dailyLimit ?? '—'} />
        <StatCard label="Remaining today" value={remaining ?? '—'} />
        <StatCard label="Max pages per audit" value={plan?.max_pages ?? '—'} />
      </div>

      <div className="mt-8 grid gap-4 sm:grid-cols-2">
        <div className="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
          <h2 className="text-sm font-semibold text-slate-700 dark:text-slate-200">Full recrawl credits</h2>
          <p className="mt-2 text-2xl font-semibold text-slate-900 dark:text-white">{plan?.full_recrawls ?? 0}</p>
        </div>
        <div className="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
          <h2 className="text-sm font-semibold text-slate-700 dark:text-slate-200">Total audits run</h2>
          <p className="mt-2 text-2xl font-semibold text-slate-900 dark:text-white">{audits.length}</p>
        </div>
      </div>
    </div>
  );
}
