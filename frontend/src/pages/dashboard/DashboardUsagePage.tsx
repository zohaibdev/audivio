import { useEffect, useState } from 'react';
import { fetchMyAudits } from '../../api/websites';
import type { MyAudit } from '../../api/websites';
import { fetchMyEntitlements } from '../../api/me';
import type { MyEntitlements, PlanFeatureKey } from '../../types';
import { apiErrorMessage } from '../../api/client';
import { StatCard } from '../../components/ui/StatCard';
import { Spinner } from '../../components/ui/Spinner';
import { ErrorState } from '../../components/ui/ErrorState';
import { Badge } from '../../components/ui/Badge';

/** null is unlimited throughout the entitlement payload, never "unset". */
function limitLabel(value: number | null): string {
  return value === null ? 'Unlimited' : String(value);
}

function startOfMonth(): Date {
  const now = new Date();
  return new Date(now.getFullYear(), now.getMonth(), 1);
}

export function DashboardUsagePage() {
  const [audits, setAudits] = useState<MyAudit[] | null>(null);
  const [entitlements, setEntitlements] = useState<MyEntitlements | null>(null);
  const [error, setError] = useState<string | null>(null);

  function load() {
    setError(null);
    setAudits(null);
    setEntitlements(null);
    Promise.all([fetchMyAudits(), fetchMyEntitlements()])
      .then(([auditPage, plan]) => {
        setAudits(auditPage.data);
        setEntitlements(plan);
      })
      .catch((err) => setError(apiErrorMessage(err)));
  }

  useEffect(load, []);

  if (error) return <ErrorState message={error} onRetry={load} />;
  if (!audits || !entitlements) return <Spinner />;

  const { limits, features, plan } = entitlements;

  const today = new Date().toDateString();
  const auditsToday = audits.filter((a) => new Date(a.created_at).toDateString() === today).length;
  const auditsThisMonth = audits.filter((a) => new Date(a.created_at) >= startOfMonth()).length;

  const remainingToday = Math.max(0, limits.daily_audit_limit - auditsToday);
  const remainingThisMonth =
    limits.monthly_audit_limit === null ? null : Math.max(0, limits.monthly_audit_limit - auditsThisMonth);

  // Only shows what exists. A capability that is configurable but not built
  // yet (`available: false`) would otherwise read as a plan shortcoming
  // rather than something nobody has.
  const buildableFeatures = (Object.entries(features) as [PlanFeatureKey, (typeof features)[PlanFeatureKey]][])
    .filter(([, state]) => state.available);

  return (
    <div>
      <h1 className="mb-1 text-2xl font-bold text-ink">Usage &amp; plan</h1>
      <p className="mb-6 text-sm text-muted">
        {plan ? `You're on the ${plan.name} plan.` : 'No active plan found.'}
        {entitlements.is_downgraded && (
          <span className="ml-2 text-amber-600 dark:text-amber-400">
            Your subscription has lapsed, so these are the free plan's limits.
          </span>
        )}
      </p>

      <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
        <StatCard label="Audits today" value={`${auditsToday} / ${limits.daily_audit_limit}`} />
        <StatCard label="Remaining today" value={remainingToday} />
        <StatCard
          label="Audits this month"
          value={`${auditsThisMonth} / ${limitLabel(limits.monthly_audit_limit)}`}
        />
        <StatCard label="Remaining this month" value={remainingThisMonth ?? '∞'} />
      </div>

      <div className="mt-4 grid grid-cols-2 gap-4 md:grid-cols-4">
        <StatCard label="Websites allowed" value={limits.max_websites} />
        <StatCard label="Pages per audit" value={limits.max_pages} />
        <StatCard label="Audits at once" value={limits.concurrent_audit_limit} />
        <StatCard label="Total audits run" value={audits.length} />
      </div>

      <div className="mt-8 grid gap-4 sm:grid-cols-2">
        <div className="rounded-xl border border-line bg-white p-5 dark:border-line dark:bg-slate-900">
          <h2 className="mb-3 text-sm font-semibold text-ink">Included in your plan</h2>
          <ul className="space-y-2 text-sm">
            {buildableFeatures.map(([key, state]) => (
              <li key={key} className="flex items-center justify-between gap-3">
                <span className="text-muted">{state.label}</span>
                <Badge tone={state.enabled ? 'green' : 'gray'}>{state.enabled ? 'Included' : 'Not included'}</Badge>
              </li>
            ))}
          </ul>
        </div>

        <div className="rounded-xl border border-line bg-white p-5 dark:border-line dark:bg-slate-900">
          <h2 className="mb-3 text-sm font-semibold text-ink">Reports &amp; scheduling</h2>
          <dl className="space-y-2 text-sm">
            <div className="flex items-center justify-between gap-3">
              <dt className="text-muted">Report detail shown</dt>
              <dd className="font-medium text-ink">{limits.report_visibility_percent}%</dd>
            </div>
            <div className="flex items-center justify-between gap-3">
              <dt className="text-muted">Issues shown per service</dt>
              <dd className="font-medium text-ink">
                {limitLabel(limits.issues_per_service)}
              </dd>
            </div>
            <div className="flex items-center justify-between gap-3">
              <dt className="text-muted">Most frequent schedule</dt>
              <dd className="font-medium capitalize text-ink">
                {limits.max_audit_frequency}
              </dd>
            </div>
            <div className="flex items-center justify-between gap-3">
              <dt className="text-muted">AI analyses per audit</dt>
              <dd className="font-medium text-ink">
                {limitLabel(limits.ai_analyses_per_audit)}
              </dd>
            </div>
          </dl>
        </div>
      </div>
    </div>
  );
}
