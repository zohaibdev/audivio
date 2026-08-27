import { Link } from 'react-router-dom';
import { useAuth } from '../../contexts/AuthContext';
import { Card } from '../../components/ui/Card';
import { Badge } from '../../components/ui/Badge';

export function DashboardSubscriptionPage() {
  const { user } = useAuth();
  const plan = user?.subscription?.plan;

  return (
    <div>
      <h1 className="mb-6 text-2xl font-bold text-slate-900 dark:text-white">Subscription</h1>

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
    </div>
  );
}
