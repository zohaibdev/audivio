import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { fetchMyWebsites } from '../../api/websites';
import type { MyWebsite } from '../../api/websites';
import { apiErrorMessage } from '../../api/client';
import { Card } from '../../components/ui/Card';
import { ScoreGauge } from '../../components/ui/ScoreGauge';
import { Spinner } from '../../components/ui/Spinner';
import { ErrorState } from '../../components/ui/ErrorState';
import { EmptyState } from '../../components/ui/EmptyState';
import { AuditUrlForm } from '../../components/audit/AuditUrlForm';

export function DashboardWebsitesPage() {
  const [websites, setWebsites] = useState<MyWebsite[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  function load() {
    setError(null);
    setWebsites(null);
    fetchMyWebsites()
      .then((page) => setWebsites(page.data))
      .catch((err) => setError(apiErrorMessage(err)));
  }

  useEffect(load, []);

  return (
    <div>
      <h1 className="mb-6 text-2xl font-bold text-slate-900 dark:text-white">My Websites</h1>
      {error && <ErrorState message={error} onRetry={load} />}
      {!error && !websites && <Spinner />}
      {websites && websites.length === 0 && (
        <EmptyState
          title="No websites yet"
          description="Run your first audit to add a website."
          action={
            <div className="mt-4 w-full max-w-sm">
              <AuditUrlForm compact />
            </div>
          }
        />
      )}
      {websites && websites.length > 0 && (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {websites.map((website) => {
            const overall = website.latestAudit?.scores.find((s) => s.category === 'overall')?.score ?? null;
            return (
              <Card key={website.id}>
                <p className="truncate font-medium text-slate-900 dark:text-white">{website.domain}</p>
                <p className="truncate text-xs text-slate-400">{website.url}</p>
                <p className="mt-1 text-xs text-slate-400">{website.audits_count} audit(s) run</p>
                <div className="mt-3 flex items-center justify-between">
                  <ScoreGauge score={overall} label="Latest score" size="sm" />
                  {website.latestAudit && (
                    <Link
                      to={`/audits/${website.latestAudit.id}`}
                      className="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400"
                    >
                      View report →
                    </Link>
                  )}
                </div>
              </Card>
            );
          })}
        </div>
      )}
    </div>
  );
}
