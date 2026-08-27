import { useEffect, useState } from 'react';
import * as adminApi from '../../api/admin';
import { apiErrorMessage } from '../../api/client';
import { StatCard } from '../../components/ui/StatCard';
import { Spinner } from '../../components/ui/Spinner';
import { ErrorState } from '../../components/ui/ErrorState';
import { BarChartCard } from '../../components/charts/BarChartCard';
import type { AdminDashboardSummary, UsageDailyRow } from '../../types';

export function AdminDashboardHomePage() {
  const [summary, setSummary] = useState<AdminDashboardSummary | null>(null);
  const [daily, setDaily] = useState<UsageDailyRow[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  function load() {
    setError(null);
    Promise.all([adminApi.fetchDashboardSummary(), adminApi.fetchUsageDaily()])
      .then(([summaryData, dailyData]) => {
        setSummary(summaryData);
        setDaily(dailyData);
      })
      .catch((err) => setError(apiErrorMessage(err)));
  }

  useEffect(load, []);

  if (error) return <ErrorState message={error} onRetry={load} />;
  if (!summary || !daily) return <Spinner label="Loading dashboard…" />;

  const byProviderData = Object.entries(summary.provider_usage_today.requests_by_provider).map(([provider, requests]) => ({
    provider,
    requests,
  }));

  const auditsByStatus = Object.entries(summary.audits.by_status).map(([status, count]) => ({ status, count }));

  return (
    <div className="flex flex-col gap-8">
      <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Dashboard</h1>

      <div className="grid grid-cols-2 gap-4 md:grid-cols-4 lg:grid-cols-7">
        <StatCard label="Total Users" value={summary.users.total} hint={`${summary.users.new_today} new today`} />
        <StatCard label="Total Websites" value={summary.websites.total} />
        <StatCard label="Audits Today" value={summary.audits.today} />
        <StatCard label="Audits This Month" value={summary.audits.this_month} />
        <StatCard label="Failed Jobs" value={summary.failed_jobs.total} />
        <StatCard label="Consultant Leads" value={summary.leads.total} />
        <StatCard label="Conversions" value={summary.leads.by_status.converted ?? 0} />
      </div>

      <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
        <StatCard label="AI Requests Today" value={summary.provider_usage_today.ai_requests} />
        <StatCard label="AI Tokens Today" value={summary.provider_usage_today.ai_tokens.toLocaleString()} />
        <StatCard label="Estimated AI Cost Today" value={`$${summary.provider_usage_today.estimated_ai_cost.toFixed(4)}`} />
        <StatCard label="PageSpeed Requests Today" value={summary.provider_usage_today.pagespeed_requests} />
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <BarChartCard
          title="Daily Provider Requests (AI + PageSpeed)"
          data={daily as unknown as Record<string, unknown>[]}
          xKey="date"
          bars={[{ key: 'requests', color: '#6366f1', name: 'Requests' }]}
        />
        <BarChartCard
          title="AI/API Requests By Provider (today)"
          data={byProviderData}
          xKey="provider"
          bars={[{ key: 'requests', color: '#0ea5e9', name: 'Requests' }]}
        />
        <BarChartCard
          title="Audits By Status"
          data={auditsByStatus}
          xKey="status"
          bars={[{ key: 'count', color: '#10b981', name: 'Audits' }]}
        />
        <BarChartCard
          title="Daily Failures"
          data={daily as unknown as Record<string, unknown>[]}
          xKey="date"
          bars={[{ key: 'failures', color: '#ef4444', name: 'Failures' }]}
        />
      </div>
    </div>
  );
}
