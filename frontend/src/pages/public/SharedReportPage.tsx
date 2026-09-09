import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { Card } from '../../components/ui/Card';
import { Spinner } from '../../components/ui/Spinner';
import { ServiceReportView } from '../../components/report/ServiceReportView';
import { apiErrorMessage } from '../../api/client';
import {
  fetchShareLock,
  fetchSharedReport,
  forgetAccessToken,
  storedAccessToken,
  unlockShare,
} from '../../api/sharedReport';
import type { SharedReportBranding } from '../../api/sharedReport';
import type { ServiceKey, ServiceReport } from '../../types';

/**
 * A shared report, as its recipient sees it (§50).
 *
 * Three states, and the page never skips one: locked, unlocking, open.
 * Nothing about the report — not the domain, not the score — is fetched
 * until the password has been accepted, because the server will not send
 * it and the page should not pretend otherwise by rendering placeholders
 * for data it has no right to yet.
 */
export function SharedReportPage() {
  const { token = '' } = useParams<{ token: string }>();

  const [branding, setBranding] = useState<SharedReportBranding | null>(null);
  const [access, setAccess] = useState<string | null>(() => storedAccessToken(token));
  const [report, setReport] = useState<{
    overview: { website_url?: string; audit_date?: string | null };
    services: Partial<Record<ServiceKey, ServiceReport>>;
  } | null>(null);

  const [password, setPassword] = useState('');
  const [status, setStatus] = useState<'loading' | 'locked' | 'unlocking' | 'open' | 'gone'>('loading');
  const [error, setError] = useState<string | null>(null);
  const [activeService, setActiveService] = useState<ServiceKey | null>(null);

  // The lock screen only ever asks for branding — see the page docblock.
  useEffect(() => {
    let cancelled = false;

    fetchShareLock(token)
      .then((data) => {
        if (cancelled) return;
        setBranding(data.branding);
        setStatus(access ? 'unlocking' : 'locked');
      })
      .catch(() => {
        if (!cancelled) setStatus('gone');
      });

    return () => {
      cancelled = true;
    };
  }, [token, access]);

  // A stored session from a previous page load. If the share has since
  // been revoked the server rejects it, and the page falls back to the
  // lock screen rather than showing a stale anything.
  useEffect(() => {
    if (status !== 'unlocking' || access === null) return;

    let cancelled = false;

    fetchSharedReport(token, access)
      .then((data) => {
        if (cancelled) return;
        setReport(data);
        setStatus('open');
      })
      .catch(() => {
        if (cancelled) return;
        forgetAccessToken(token);
        setAccess(null);
        setStatus('locked');
      });

    return () => {
      cancelled = true;
    };
  }, [status, access, token]);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    setStatus('unlocking');

    try {
      const unlocked = await unlockShare(token, password);
      setPassword('');
      setAccess(unlocked.access_token);
    } catch (caught) {
      setStatus('locked');
      // The server's own wording, which is deliberately the same for a
      // wrong password as for anything else that went wrong.
      setError(apiErrorMessage(caught));
    }
  }

  if (status === 'loading') {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <Spinner />
      </div>
    );
  }

  if (status === 'gone') {
    return (
      <Centered>
        <h1 className="text-xl font-semibold text-ink">This link is no longer available</h1>
        <p className="mt-2 text-sm text-muted">
          It may have been revoked or expired. Ask whoever sent it for a new one.
        </p>
      </Centered>
    );
  }

  if (status === 'locked' || status === 'unlocking') {
    return (
      <Centered>
        {branding?.logo_data_uri ? (
          <img src={branding.logo_data_uri} alt={branding.brand_name} className="mx-auto max-h-12" />
        ) : (
          <p className="text-center text-lg font-bold text-ink">
            {branding?.brand_name ?? 'Audivio'}
          </p>
        )}

        <h1 className="mt-4 text-center text-xl font-semibold text-ink">
          This report is password protected
        </h1>
        <p className="mt-2 text-center text-sm text-muted">
          Enter the password you were sent to open it.
        </p>

        <form onSubmit={submit} className="mt-6 flex flex-col gap-3">
          <input
            required
            autoFocus
            type="password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            placeholder="Password"
            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
          />

          {error && <p className="text-sm text-red-600 dark:text-red-400">{error}</p>}

          <button
            type="submit"
            disabled={status === 'unlocking'}
            className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60"
          >
            {status === 'unlocking' ? 'Opening…' : 'Open report'}
          </button>
        </form>
      </Centered>
    );
  }

  const services = Object.values(report?.services ?? {}) as ServiceReport[];
  const current = activeService ? report?.services[activeService] : undefined;

  return (
    <div className="min-h-screen bg-slate-50 p-4 dark:bg-slate-950 md:p-8">
      <div className="mx-auto max-w-5xl">
        <Card>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              {branding?.logo_data_uri ? (
                <img src={branding.logo_data_uri} alt={branding.brand_name} className="max-h-10" />
              ) : (
                <p className="font-bold text-ink">{branding?.brand_name}</p>
              )}
              <p className="mt-1 text-sm text-muted">
                {report?.overview.website_url}
              </p>
            </div>
            <span className="rounded-md bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">
              Shared report
            </span>
          </div>
        </Card>

        <nav className="mt-6 flex gap-1 overflow-x-auto border-b border-line pb-2 dark:border-line">
          <button
            type="button"
            onClick={() => setActiveService(null)}
            className={tabClass(activeService === null)}
          >
            Overview
          </button>
          {services.map((service) => (
            <button
              key={service.service}
              type="button"
              onClick={() => setActiveService(service.service)}
              className={tabClass(activeService === service.service)}
            >
              {service.name}
            </button>
          ))}
        </nav>

        <div className="mt-6">
          {current ? (
            <ServiceReportView report={current} />
          ) : (
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {services.map((service) => (
                <SharedScoreCard
                  key={service.service}
                  report={service}
                  onOpen={() => setActiveService(service.service)}
                />
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

/**
 * A score card for the shared view.
 *
 * Deliberately not the owner's `ServiceScoreCard`, which renders a
 * `<Link to={`/audits/${auditId}/report/...`}>` — reusing it would put
 * the audit's database id into the DOM of a page whose entire premise is
 * that the id is not in it (§50). This one navigates within the shared
 * view and knows no id at all.
 */
function SharedScoreCard({ report, onOpen }: { report: ServiceReport; onOpen: () => void }) {
  const counts = report.severity_summary;
  const urgent = (counts.critical ?? 0) + (counts.high ?? 0);

  return (
    <button
      type="button"
      onClick={onOpen}
      className="rounded-xl border border-line bg-white p-5 text-left shadow-sm transition hover:border-slate-300 dark:border-line dark:bg-slate-900 dark:hover:border-slate-700"
    >
      <div className="flex items-start justify-between gap-3">
        <p className="font-medium text-ink">{report.name}</p>
        <p className="text-2xl font-bold text-ink">
          {report.is_applicable ? (report.score ?? '—') : 'N/A'}
        </p>
      </div>

      <p className="mt-2 text-sm text-muted">
        {report.is_applicable
          ? urgent > 0
            ? `${urgent} need${urgent === 1 ? 's' : ''} attention`
            : 'No urgent issues found'
          : report.not_applicable_reason}
      </p>
    </button>
  );
}

function tabClass(active: boolean): string {
  return active
    ? 'whitespace-nowrap rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white dark:bg-white dark:text-slate-900'
    : 'whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800';
}

function Centered({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-50 p-4 dark:bg-slate-950">
      <div className="w-full max-w-sm rounded-xl border border-line bg-white p-6 shadow-sm dark:border-line dark:bg-slate-900">
        {children}
      </div>
    </div>
  );
}
