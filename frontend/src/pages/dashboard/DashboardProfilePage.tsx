import { useAuth } from '../../contexts/AuthContext';
import { Card } from '../../components/ui/Card';

/**
 * Read-only — there's no profile-update endpoint on the backend yet.
 * Showing an editable form here would either silently do nothing or
 * call an endpoint that doesn't exist, so this stays informational
 * until that capability is actually built.
 */
export function DashboardProfilePage() {
  const { user } = useAuth();

  if (!user) return null;

  return (
    <div>
      <h1 className="mb-6 text-2xl font-bold text-slate-900 dark:text-white">Profile</h1>
      <Card className="max-w-md">
        <dl className="divide-y divide-slate-100 dark:divide-slate-800">
          <div className="flex justify-between py-3">
            <dt className="text-sm text-slate-500 dark:text-slate-400">Name</dt>
            <dd className="text-sm font-medium text-slate-900 dark:text-white">{user.name}</dd>
          </div>
          <div className="flex justify-between py-3">
            <dt className="text-sm text-slate-500 dark:text-slate-400">Email</dt>
            <dd className="text-sm font-medium text-slate-900 dark:text-white">{user.email}</dd>
          </div>
          <div className="flex justify-between py-3">
            <dt className="text-sm text-slate-500 dark:text-slate-400">Member since</dt>
            <dd className="text-sm font-medium text-slate-900 dark:text-white">{new Date(user.created_at).toLocaleDateString()}</dd>
          </div>
        </dl>
      </Card>
    </div>
  );
}
