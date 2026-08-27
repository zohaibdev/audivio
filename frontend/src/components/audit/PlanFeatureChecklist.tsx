import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { fetchMyFeatures } from '../../api/me';
import type { MyFeatureAccess } from '../../types';

/**
 * Shows which audit types will run for the current viewer's plan (or the
 * default plan, anonymously) before they submit a URL — audit creation
 * itself doesn't take a module selection (the backend derives it from the
 * plan automatically, see AuditModuleRegistry::modulesFor()), so this is
 * informational: what to expect, and what to upgrade for if something's
 * locked. Backend enforcement is independent of this display.
 */
export function PlanFeatureChecklist() {
  const [features, setFeatures] = useState<MyFeatureAccess[] | null>(null);

  useEffect(() => {
    fetchMyFeatures()
      .then(setFeatures)
      .catch(() => setFeatures(null));
  }, []);

  if (!features || features.length === 0) return null;

  return (
    <ul className="mt-6 flex flex-col gap-2 text-left text-sm">
      {features.map((feature) => (
        <li key={feature.key} className="flex items-center justify-between rounded-md border border-slate-200 px-3 py-2 dark:border-slate-800">
          <span className="text-slate-700 dark:text-slate-300">{feature.name}</span>
          {feature.enabled ? (
            <span className="text-emerald-600 dark:text-emerald-400">✓ Included</span>
          ) : (
            <span className="flex items-center gap-2 text-slate-400">
              🔒
              {feature.plans_with_access.length > 0 ? (
                <Link to="/pricing" className="font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                  Upgrade to {feature.plans_with_access[0].name}
                </Link>
              ) : (
                'Not available'
              )}
            </span>
          )}
        </li>
      ))}
    </ul>
  );
}
