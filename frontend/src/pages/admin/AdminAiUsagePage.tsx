import { useEffect, useState } from 'react';
import * as adminApi from '../../api/admin';
import { apiErrorMessage } from '../../api/client';
import { BarChartCard } from '../../components/charts/BarChartCard';
import { Spinner } from '../../components/ui/Spinner';
import { ErrorState } from '../../components/ui/ErrorState';
import { EmptyState } from '../../components/ui/EmptyState';

interface AiUsageData {
  by_provider: { provider: string; calls: number; prompt_tokens: number; completion_tokens: number }[];
  by_category: { category: string; calls: number; failures: number }[];
  recent: { id: number; provider: string; category: string; error: string | null; created_at: string; audit?: { id: number } }[];
}

/**
 * ai_analyses-based (per-audit AI results) — distinct from the API
 * Usage page, which reports on api_usage_logs (cross-provider request
 * telemetry, including PageSpeed). See CONTEXT.md §23.
 */
export function AdminAiUsagePage() {
  const [data, setData] = useState<AiUsageData | null>(null);
  const [error, setError] = useState<string | null>(null);

  function load() {
    setError(null);
    adminApi
      .fetchAiUsage()
      .then((result) => setData(result as unknown as AiUsageData))
      .catch((err) => setError(apiErrorMessage(err)));
  }

  useEffect(load, []);

  if (error) return <ErrorState message={error} onRetry={load} />;
  if (!data) return <Spinner />;

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-bold text-ink">AI Usage</h1>
        <p className="mt-1 text-sm text-muted">Per-audit AI analysis results, by provider and category.</p>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <BarChartCard
          title="AI Calls By Provider"
          data={data.by_provider as unknown as Record<string, unknown>[]}
          xKey="provider"
          bars={[{ key: 'calls', color: '#8b5cf6', name: 'Calls' }]}
        />
        <BarChartCard
          title="Audit Category Usage"
          data={data.by_category as unknown as Record<string, unknown>[]}
          xKey="category"
          bars={[
            { key: 'calls', color: '#0ea5e9', name: 'Calls' },
            { key: 'failures', color: '#ef4444', name: 'Failures' },
          ]}
        />
      </div>

      <div>
        <h2 className="mb-3 text-lg font-semibold text-ink">Recent analyses</h2>
        {data.recent.length === 0 ? (
          <EmptyState title="No AI analyses recorded yet" />
        ) : (
          <div className="overflow-x-auto rounded-xl border border-line">
            <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-line">
              <thead className="bg-surface-2">
                <tr>
                  <th className="px-4 py-2 text-left font-medium text-muted">Date</th>
                  <th className="px-4 py-2 text-left font-medium text-muted">Provider</th>
                  <th className="px-4 py-2 text-left font-medium text-muted">Category</th>
                  <th className="px-4 py-2 text-left font-medium text-muted">Audit</th>
                  <th className="px-4 py-2 text-left font-medium text-muted">Error</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-line bg-white dark:divide-line dark:bg-slate-900">
                {data.recent.map((row) => (
                  <tr key={row.id}>
                    <td className="px-4 py-2 text-muted">{new Date(row.created_at).toLocaleString()}</td>
                    <td className="px-4 py-2 text-muted">{row.provider}</td>
                    <td className="px-4 py-2 text-muted">{row.category}</td>
                    <td className="px-4 py-2 text-muted">{row.audit?.id ?? '—'}</td>
                    <td className="px-4 py-2 text-red-500">{row.error ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
