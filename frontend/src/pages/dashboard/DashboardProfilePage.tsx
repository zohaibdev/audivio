import { useAuth } from '../../contexts/AuthContext';
import { Card } from '../../components/ui/Card';
import { BrandingSettings } from '../../components/dashboard/BrandingSettings';

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
      <h1 className="mb-6 text-2xl font-bold text-ink">Profile</h1>
      <Card className="max-w-md">
        <dl className="divide-y divide-line">
          <div className="flex justify-between py-3">
            <dt className="text-sm text-muted">Name</dt>
            <dd className="text-sm font-medium text-ink">{user.name}</dd>
          </div>
          <div className="flex justify-between py-3">
            <dt className="text-sm text-muted">Email</dt>
            <dd className="text-sm font-medium text-ink">{user.email}</dd>
          </div>
          <div className="flex justify-between py-3">
            <dt className="text-sm text-muted">Member since</dt>
            <dd className="text-sm font-medium text-ink">{new Date(user.created_at).toLocaleDateString()}</dd>
          </div>
        </dl>
      </Card>

      {/* White-label branding (§48). Shown to everyone: the form states
          whether the plan applies it, rather than hiding and losing the
          settings behind an entitlement check. */}
      <div className="mt-6 max-w-2xl">
        <BrandingSettings />
      </div>
    </div>
  );
}
