import { useEffect, useState } from 'react';
import * as adminApi from '../../api/admin';
import { apiErrorMessage } from '../../api/client';
import { Card } from '../../components/ui/Card';
import { Badge } from '../../components/ui/Badge';
import { Spinner } from '../../components/ui/Spinner';
import { ErrorState } from '../../components/ui/ErrorState';
import type { ApiProviderSummary, AdminDashboardSummary } from '../../types';

function StatusRow({ label, status, message }: { label: string; status: 'ok' | 'error' | 'unknown'; message?: string | null }) {
  const tone = status === 'ok' ? 'green' : status === 'error' ? 'red' : 'gray';
  return (
    <div className="flex items-center justify-between border-b border-slate-100 py-3 last:border-0 dark:border-slate-800">
      <div>
        <p className="text-sm font-medium text-slate-800 dark:text-slate-200">{label}</p>
        {message && <p className="text-xs text-red-500">{message}</p>}
      </div>
      <Badge tone={tone}>{status}</Badge>
    </div>
  );
}

export function AdminSystemHealthPage() {
  const [health, setHealth] = useState<adminApi.SystemHealth | null>(null);
  const [providers, setProviders] = useState<ApiProviderSummary[] | null>(null);
  const [summary, setSummary] = useState<AdminDashboardSummary | null>(null);
  const [error, setError] = useState<string | null>(null);

  function load() {
    setError(null);
    Promise.all([adminApi.fetchSystemHealth(), adminApi.fetchProviders(), adminApi.fetchDashboardSummary()])
      .then(([h, p, s]) => {
        setHealth(h);
        setProviders(p);
        setSummary(s);
      })
      .catch((err) => setError(apiErrorMessage(err)));
  }

  useEffect(load, []);

  if (error) return <ErrorState message={error} onRetry={load} />;
  if (!health || !providers || !summary) return <Spinner />;

  return (
    <div className="flex flex-col gap-6">
      <h1 className="text-2xl font-bold text-slate-900 dark:text-white">System Health</h1>

      <Card>
        <h2 className="mb-2 text-sm font-semibold text-slate-700 dark:text-slate-200">Core infrastructure</h2>
        <StatusRow label="Database" status={health.database.status} message={health.database.message} />
        <StatusRow label="Cache" status={health.cache.status} message={health.cache.message} />
        <div className="flex items-center justify-between border-b border-slate-100 py-3 last:border-0 dark:border-slate-800">
          <p className="text-sm font-medium text-slate-800 dark:text-slate-200">Queue connection (configured)</p>
          <Badge tone="blue">{health.queue_connection}</Badge>
        </div>
        <p className="mt-2 text-xs text-slate-400">
          Live queue/worker/Horizon status isn't checkable from a single request — this shows the configured connection
          name only, not a real-time heartbeat.
        </p>
      </Card>

      <Card>
        <h2 className="mb-2 text-sm font-semibold text-slate-700 dark:text-slate-200">Provider health</h2>
        {providers.map((provider) => (
          <StatusRow
            key={provider.slug}
            label={provider.name}
            status={!provider.is_enabled ? 'unknown' : provider.last_failure_at && (!provider.last_success_at || provider.last_failure_at > provider.last_success_at) ? 'error' : 'ok'}
            message={provider.last_error}
          />
        ))}
      </Card>

      <Card>
        <h2 className="mb-2 text-sm font-semibold text-slate-700 dark:text-slate-200">Recent failed jobs</h2>
        <p className="text-2xl font-semibold text-slate-900 dark:text-white">{summary.failed_jobs.total}</p>
        <p className="text-xs text-slate-400">See the Failed Jobs page for details.</p>
      </Card>
    </div>
  );
}
