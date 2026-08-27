import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../contexts/AuthContext';
import { fetchMyAiModels, fetchMyFeatures } from '../../api/me';
import { Card } from '../../components/ui/Card';
import { Badge } from '../../components/ui/Badge';
import type { MyAiModel, MyFeatureAccess } from '../../types';

export function DashboardSubscriptionPage() {
  const { user } = useAuth();
  const plan = user?.subscription?.plan;
  const [features, setFeatures] = useState<MyFeatureAccess[] | null>(null);
  const [aiModels, setAiModels] = useState<MyAiModel[] | null>(null);

  useEffect(() => {
    fetchMyFeatures().then(setFeatures).catch(() => setFeatures([]));
    fetchMyAiModels().then(setAiModels).catch(() => setAiModels([]));
  }, []);

  return (
    <div className="flex flex-col gap-6">
      <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Subscription</h1>

      {!plan ? (
        <Card className="max-w-md">
          <p className="text-sm text-slate-600 dark:text-slate-300">You don't have an active plan.</p>
        </Card>
      ) : (
        <Card className="max-w-md">
          <div className="flex items-center justify-between">
            <h2 className="text-lg font-semibold text-slate-900 dark:text-white">{plan.name}</h2>
            <Badge tone="green">{user?.subscription?.status ?? 'active'}</Badge>
          </div>
          <ul className="mt-4 space-y-2 text-sm text-slate-600 dark:text-slate-300">
            <li>Up to {plan.max_pages} pages per audit</li>
            <li>{plan.daily_audit_limit} audit(s) per day</li>
            <li>{plan.full_recrawls} full recrawl credit(s)</li>
            {plan.features?.pdf_export && <li>PDF export included</li>}
            {plan.features?.scheduled_audits && <li>Scheduled recurring audits included</li>}
            {plan.features?.audit_history && <li>Full audit history included</li>}
          </ul>
          <Link
            to="/pricing"
            className="mt-6 inline-block rounded-md border border-indigo-600 px-4 py-2 text-sm font-medium text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-950"
          >
            Compare plans
          </Link>
        </Card>
      )}

      <Card className="max-w-md">
        <h2 className="mb-3 text-sm font-semibold text-slate-700 dark:text-slate-200">Audit types on your plan</h2>
        {features === null && <p className="text-sm text-slate-400">Loading…</p>}
        {features !== null && (
          <ul className="flex flex-col gap-2 text-sm">
            {features.map((feature) => (
              <li key={feature.key} className="flex items-center justify-between">
                <span className="text-slate-700 dark:text-slate-300">{feature.name}</span>
                {feature.enabled ? (
                  <Badge tone="green">Included</Badge>
                ) : feature.plans_with_access.length > 0 ? (
                  <Link to="/pricing" className="text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                    Upgrade to unlock
                  </Link>
                ) : (
                  <Badge tone="gray">Unavailable</Badge>
                )}
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card className="max-w-md">
        <h2 className="mb-3 text-sm font-semibold text-slate-700 dark:text-slate-200">AI models available to you</h2>
        {aiModels === null && <p className="text-sm text-slate-400">Loading…</p>}
        {aiModels !== null && aiModels.length === 0 && (
          <p className="text-sm text-slate-500 dark:text-slate-400">No AI models are currently available on your plan.</p>
        )}
        {aiModels !== null && aiModels.length > 0 && (
          <ul className="flex flex-col gap-2 text-sm">
            {aiModels.map((model) => (
              <li key={model.id} className="flex items-center justify-between">
                <span className="text-slate-700 dark:text-slate-300">{model.display_name}</span>
                <Badge tone="blue">{model.provider_name}</Badge>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}
