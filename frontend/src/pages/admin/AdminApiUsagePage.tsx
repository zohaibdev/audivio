import { useEffect, useState } from 'react';
import * as adminApi from '../../api/admin';
import type { UsageLogFilters } from '../../api/admin';
import { apiErrorMessage } from '../../api/client';
import { StatCard } from '../../components/ui/StatCard';
import { LineChartCard } from '../../components/charts/LineChartCard';
import { BarChartCard } from '../../components/charts/BarChartCard';
import { Spinner } from '../../components/ui/Spinner';
import { ErrorState } from '../../components/ui/ErrorState';
import { Badge } from '../../components/ui/Badge';
import { EmptyState } from '../../components/ui/EmptyState';
import type { ApiUsageLogEntry, Paginated, UsageByProviderRow, UsageDailyRow, UsageSummary } from '../../types';

export function AdminApiUsagePage() {
  const [filters, setFilters] = useState<UsageLogFilters>({});
  const [summary, setSummary] = useState<UsageSummary | null>(null);
  const [daily, setDaily] = useState<UsageDailyRow[] | null>(null);
  const [byProvider, setByProvider] = useState<UsageByProviderRow[] | null>(null);
  const [logs, setLogs] = useState<Paginated<ApiUsageLogEntry> | null>(null);
  const [error, setError] = useState<string | null>(null);

  function load() {
    setError(null);
    Promise.all([
      adminApi.fetchUsageSummary(filters),
      adminApi.fetchUsageDaily(filters),
      adminApi.fetchUsageByProvider(filters),
      adminApi.fetchUsageLogs(filters),
    ])
      .then(([summaryData, dailyData, byProviderData, logsData]) => {
        setSummary(summaryData);
        setDaily(dailyData);
        setByProvider(byProviderData);
        setLogs(logsData);
      })
      .catch((err) => setError(apiErrorMessage(err)));
  }

  useEffect(load, [filters]);

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">API Usage</h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Cross-provider request and cost telemetry.</p>
      </div>

      <div className="flex flex-wrap gap-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <select
          value={filters.provider ?? ''}
          onChange={(e) => setFilters({ ...filters, provider: e.target.value || undefined })}
          className="rounded-md border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
        >
          <option value="">All providers</option>
          <option value="claude">Claude</option>
          <option value="openai">OpenAI</option>
          <option value="gemini">Gemini</option>
          <option value="pagespeed">PageSpeed</option>
        </select>
        <select
          value={filters.success === undefined ? '' : String(filters.success)}
          onChange={(e) => setFilters({ ...filters, success: e.target.value === '' ? undefined : e.target.value === 'true' })}
          className="rounded-md border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
        >
          <option value="">Success or failure</option>
          <option value="true">Successful only</option>
          <option value="false">Failed only</option>
        </select>
        <input
          type="date"
          value={filters.from ?? ''}
          onChange={(e) => setFilters({ ...filters, from: e.target.value || undefined })}
          className="rounded-md border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
          aria-label="From date"
        />
        <input
          type="date"
          value={filters.to ?? ''}
          onChange={(e) => setFilters({ ...filters, to: e.target.value || undefined })}
          className="rounded-md border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
          aria-label="To date"
        />
      </div>

      {error && <ErrorState message={error} onRetry={load} />}
      {!error && (!summary || !daily || !byProvider || !logs) && <Spinner />}

      {summary && daily && byProvider && logs && (
        <>
          <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6">
            <StatCard label="Total Requests" value={summary.total_requests} />
            <StatCard label="Successful" value={summary.successful_requests} />
            <StatCard label="Failed" value={summary.failed_requests} />
            <StatCard label="Total Tokens" value={summary.total_tokens.toLocaleString()} />
            <StatCard label="Estimated Cost" value={`$${summary.estimated_cost.toFixed(4)}`} />
            <StatCard label="Avg Response Time" value={summary.average_response_time_ms ? `${summary.average_response_time_ms}ms` : '—'} />
          </div>

          <div className="grid gap-6 lg:grid-cols-2">
            <LineChartCard
              title="Requests Per Day"
              data={daily as unknown as Record<string, unknown>[]}
              xKey="date"
              lines={[{ key: 'requests', color: '#6366f1', name: 'Requests' }]}
            />
            <BarChartCard
              title="Requests By Provider"
              data={byProvider as unknown as Record<string, unknown>[]}
              xKey="provider"
              bars={[{ key: 'requests', color: '#0ea5e9', name: 'Requests' }]}
            />
            <LineChartCard
              title="Token Usage Per Day"
              data={daily as unknown as Record<string, unknown>[]}
              xKey="date"
              lines={[{ key: 'total_tokens', color: '#8b5cf6', name: 'Tokens' }]}
            />
            <LineChartCard
              title="Estimated Cost Per Day"
              data={daily as unknown as Record<string, unknown>[]}
              xKey="date"
              lines={[{ key: 'estimated_cost', color: '#f59e0b', name: 'Cost ($)' }]}
            />
          </div>

          <div>
            <h2 className="mb-3 text-lg font-semibold text-slate-900 dark:text-white">Recent requests</h2>
            {logs.data.length === 0 ? (
              <EmptyState title="No requests logged yet" />
            ) : (
              <div className="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-800">
                <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                  <thead className="bg-slate-50 dark:bg-slate-800/50">
                    <tr>
                      {['Date/Time', 'Provider', 'Model', 'Service', 'User', 'Status', 'Tokens', 'Response Time', 'Est. Cost'].map((h) => (
                        <th key={h} scope="col" className="px-4 py-2 text-left font-medium text-slate-500 dark:text-slate-400">
                          {h}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100 bg-white dark:divide-slate-800 dark:bg-slate-900">
                    {logs.data.map((log) => (
                      <tr key={log.id}>
                        <td className="px-4 py-2 text-slate-500 dark:text-slate-400">{new Date(log.created_at).toLocaleString()}</td>
                        <td className="px-4 py-2 text-slate-700 dark:text-slate-300">{log.provider}</td>
                        <td className="px-4 py-2 text-slate-500 dark:text-slate-400">{log.model ?? '—'}</td>
                        <td className="px-4 py-2 text-slate-500 dark:text-slate-400">{log.service}</td>
                        <td className="px-4 py-2 text-slate-500 dark:text-slate-400">{log.user?.name ?? '—'}</td>
                        <td className="px-4 py-2">
                          <Badge tone={log.success ? 'green' : 'red'}>{log.success ? 'Success' : 'Failed'}</Badge>
                        </td>
                        <td className="px-4 py-2 text-slate-500 dark:text-slate-400">{log.total_tokens ?? '—'}</td>
                        <td className="px-4 py-2 text-slate-500 dark:text-slate-400">{log.response_time_ms ? `${log.response_time_ms}ms` : '—'}</td>
                        <td className="px-4 py-2 text-slate-500 dark:text-slate-400">{log.estimated_cost ? `$${log.estimated_cost.toFixed(4)}` : '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                <p className="border-t border-slate-100 px-4 py-2 text-xs text-slate-400 dark:border-slate-800">
                  Page {logs.current_page} of {logs.last_page} — {logs.total} total
                </p>
              </div>
            )}
          </div>
        </>
      )}
    </div>
  );
}
