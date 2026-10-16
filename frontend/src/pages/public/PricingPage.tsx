import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { fetchPublicPlans } from '../../api/plans';
import { apiErrorMessage } from '../../api/client';
import { Spinner } from '../../components/ui/Spinner';
import { ErrorState } from '../../components/ui/ErrorState';
import type { Plan } from '../../types';

function formatPrice(cents: number): string {
  if (cents === 0) return 'Free';
  return `$${(cents / 100).toFixed(0)}/mo`;
}

export function PricingPage() {
  const [plans, setPlans] = useState<Plan[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  function load() {
    setError(null);
    setPlans(null);
    fetchPublicPlans()
      .then(setPlans)
      .catch((err) => setError(apiErrorMessage(err)));
  }

  useEffect(load, []);

  // The comparison table's row list is the union of features enabled on at
  // least one plan — a feature disabled on every plan simply wouldn't have
  // anything to compare, so it's not shown here (it would still show as
  // locked on /api/me/features for a specific viewer's plan).
  const featureRows = plans
    ? [...new Map(plans.flatMap((p) => p.audit_features ?? []).map((f) => [f.key, f])).values()]
    : [];

  return (
    <div className="mx-auto max-w-5xl px-4 py-16">
      <h1 className="text-center text-3xl font-bold text-ink">Pricing</h1>
      <p className="mx-auto mt-2 max-w-xl text-center text-muted">
        Start free. Upgrade when you need higher page limits, more audit types, or scheduled audits.
      </p>

      {plans === null && !error && <Spinner label="Loading plans…" />}
      {error && <ErrorState message={error} onRetry={load} />}

      {plans && plans.length === 0 && (
        <p className="mt-10 text-center text-sm text-muted">No plans are configured yet.</p>
      )}

      {plans && plans.length > 0 && (
        <>
          <div className="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            {plans.map((plan) => (
              <div
                key={plan.id}
                className={`rounded-xl border p-6 ${
                  plan.is_default
                    ? 'border-indigo-300 bg-indigo-50 dark:border-indigo-800 dark:bg-indigo-950/40'
                    : 'border-line bg-white dark:border-line dark:bg-slate-900'
                }`}
              >
                <h2 className="font-semibold text-ink">{plan.name}</h2>
                <p className="mt-2 text-2xl font-bold text-ink">{formatPrice(plan.price_cents)}</p>
                <ul className="mt-4 space-y-2 text-sm text-muted">
                  <li>Up to {plan.max_pages} pages per audit</li>
                  <li>{plan.daily_audit_limit} audit(s) per day</li>
                  {plan.full_recrawls > 0 && <li>{plan.full_recrawls} full recrawl credit(s)</li>}
                  {(plan.audit_features ?? []).map((f) => (
                    <li key={f.key}>{f.name}</li>
                  ))}
                  {plan.features?.pdf_export && <li>PDF export</li>}
                  {plan.features?.scheduled_audits && <li>Scheduled recurring audits</li>}
                  {plan.features?.audit_history && <li>Full audit history</li>}
                </ul>
                <Link
                  to="/signup"
                  className="mt-6 block rounded-md bg-indigo-600 px-4 py-2 text-center text-sm font-medium text-white hover:bg-indigo-700"
                >
                  Get started
                </Link>
              </div>
            ))}
          </div>

          {featureRows.length > 0 && (
            <div className="mt-14">
              <h2 className="mb-4 text-center text-xl font-semibold text-ink">Compare audit features</h2>
              <div className="overflow-x-auto rounded-xl border border-line">
                <table className="w-full min-w-[500px] text-left text-sm">
                  <thead className="bg-surface-2">
                    <tr>
                      <th className="px-4 py-3 font-medium text-muted">Audit type</th>
                      {plans.map((plan) => (
                        <th key={plan.id} className="px-4 py-3 text-center font-medium text-muted">
                          {plan.name}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-line bg-white dark:divide-line dark:bg-slate-900">
                    {featureRows.map((feature) => (
                      <tr key={feature.key}>
                        <td className="px-4 py-3 text-muted">{feature.name}</td>
                        {plans.map((plan) => {
                          const included = (plan.audit_features ?? []).some((f) => f.key === feature.key);
                          return (
                            <td key={plan.id} className="px-4 py-3 text-center">
                              {included ? (
                                <span className="text-emerald-600 dark:text-emerald-400">✓</span>
                              ) : (
                                <span className="text-slate-300 dark:text-slate-600">—</span>
                              )}
                            </td>
                          );
                        })}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  );
}
