import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { fetchMyAudits, fetchMyWebsites } from '../../api/websites';
import type { MyAudit, MyWebsite } from '../../api/websites';
import { apiErrorMessage } from '../../api/client';
import { useAuth } from '../../contexts/AuthContext';
import { AuditUrlForm } from '../../components/audit/AuditUrlForm';
import { StatCard } from '../../components/ui/StatCard';
import { Card } from '../../components/ui/Card';
import { ScoreGauge } from '../../components/ui/ScoreGauge';
import { StatusBadge } from '../../components/ui/Badge';
import { Spinner } from '../../components/ui/Spinner';
import { ErrorState } from '../../components/ui/ErrorState';
import { EmptyState } from '../../components/ui/EmptyState';

function scoreFor(audit: MyAudit | null | undefined, category: string): number | null {
  const scores = audit?.scores ?? [];
  return scores.find((s) => s.category === category)?.score ?? null;
}

export function DashboardOverviewPage() {
  const { user } = useAuth();
  const [websites, setWebsites] = useState<MyWebsite[] | null>(null);
  const [audits, setAudits] = useState<MyAudit[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  function load() {
    setError(null);
    Promise.all([fetchMyWebsites(), fetchMyAudits()])
      .then(([websitesPage, auditsPage]) => {
        setWebsites(websitesPage.data);
        setAudits(auditsPage.data);
      })
      .catch((err) => setError(apiErrorMessage(err)));
  }

  useEffect(load, []);

  if (error) return <ErrorState message={error} onRetry={load} />;
  if (!websites || !audits) return <Spinner />;

  const latest = audits[0] ?? null;
  const plan = user?.subscription?.plan;
  const auditsToday = audits.filter((a) => new Date(a.created_at).toDateString() === new Date().toDateString()).length;

  return (
    <div className="flex flex-col gap-8">
      <div>
        <h1 className="text-2xl font-bold text-ink">Welcome back{user ? `, ${user.name.split(' ')[0]}` : ''}</h1>
        <p className="mt-1 text-sm text-muted">Here's how your websites are doing.</p>
      </div>

      <Card>
        <h2 className="mb-3 text-sm font-semibold text-ink">Run a new audit</h2>
        <AuditUrlForm compact />
      </Card>

      <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
        <StatCard label="Websites" value={websites.length} />
        <StatCard label="Audits today" value={auditsToday} hint={plan ? `${plan.daily_audit_limit}/day allowed` : undefined} />
        <StatCard label="Total audits" value={audits.length} />
        <StatCard label="Next available audit" value={plan ? 'Anytime within your daily limit' : '—'} />
      </div>

      {latest && (
        <Card>
          <div className="mb-4 flex items-center justify-between">
            <h2 className="text-sm font-semibold text-ink">Latest audit — {latest.website.domain}</h2>
            <Link to={`/audits/${latest.id}`} className="text-sm text-indigo-600 hover:underline dark:text-indigo-400">
              View report →
            </Link>
          </div>
          <div className="grid grid-cols-3 gap-4 sm:grid-cols-6">
            <ScoreGauge score={scoreFor(latest, 'overall')} label="Overall" size="sm" />
            <ScoreGauge score={scoreFor(latest, 'performance')} label="Performance" size="sm" />
            <ScoreGauge score={scoreFor(latest, 'accessibility')} label="Accessibility" size="sm" />
            <ScoreGauge score={scoreFor(latest, 'seo')} label="SEO" size="sm" />
            <ScoreGauge score={scoreFor(latest, 'conversion')} label="CRO" size="sm" />
            <ScoreGauge score={scoreFor(latest, 'aio')} label="AIO" size="sm" />
          </div>
          <p className="mt-3 text-xs text-faint">
            <StatusBadge status={latest.status} />
          </p>
        </Card>
      )}

      <div>
        <div className="mb-3 flex items-center justify-between">
          <h2 className="text-lg font-semibold text-ink">Your websites</h2>
          <Link to="/dashboard/websites" className="text-sm text-indigo-600 hover:underline dark:text-indigo-400">
            View all →
          </Link>
        </div>
        {websites.length === 0 ? (
          <EmptyState title="No websites yet" description="Run your first audit above to get started." />
        ) : (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {websites.slice(0, 3).map((website) => (
              <Card key={website.id}>
                <p className="truncate font-medium text-ink">{website.name}</p>
                <p className="truncate text-xs text-faint">{website.domain}</p>
                <p className="text-xs text-faint">{website.audits_count} audit(s)</p>
                <div className="mt-3 flex items-center gap-3">
                  {/* The card already carries the last completed audit's
                      overall score, so there is nothing to derive here. */}
                  <ScoreGauge score={website.overall_score} label="Score" size="sm" />
                  {website.last_audit_id && (
                    <Link to={`/audits/${website.last_audit_id}/report`} className="text-sm text-indigo-600 hover:underline dark:text-indigo-400">
                      View report
                    </Link>
                  )}
                </div>
              </Card>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
