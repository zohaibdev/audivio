import { useEffect, useState } from 'react';
import { NavLink, useParams } from 'react-router-dom';
import * as auditsApi from '../../api/audits';
import { apiErrorMessage } from '../../api/client';
import { Card } from '../../components/ui/Card';
import { Spinner } from '../../components/ui/Spinner';
import { ErrorState } from '../../components/ui/ErrorState';
import { EmptyState } from '../../components/ui/EmptyState';
import { ServiceScoreCard } from '../../components/report/ServiceScoreCard';
import { ServiceReportView } from '../../components/report/ServiceReportView';
import { PageExplorer } from '../../components/report/PageExplorer';
import {
  ConsultationProvider,
  ConsultationTrigger,
} from '../../components/report/ConsultationCta';
import { ShareReportDialog } from '../../components/report/ShareReportDialog';
import { AuditComparisonView } from '../../components/report/AuditComparisonView';
import type { ExplorerPage, ServiceKey, ServiceReportsResponse } from '../../types';

/**
 * The audit report shell (§35): an overview of every service that ran,
 * with independent navigation into each service's own report and a
 * cross-service page explorer. `service` comes from the route, so each
 * service report is directly linkable and independently viewable.
 */
export function AuditReportPage() {
  const params = useParams<{ auditId: string; service?: string }>();
  const auditId = Number(params.auditId);
  const activeService = params.service as ServiceKey | undefined;

  const [data, setData] = useState<ServiceReportsResponse | null>(null);
  const [pages, setPages] = useState<ExplorerPage[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [isSharing, setIsSharing] = useState(false);
  const [isDownloading, setIsDownloading] = useState(false);
  const [downloadError, setDownloadError] = useState<string | null>(null);

  async function handleDownloadPdf(service: string) {
    setIsDownloading(true);
    setDownloadError(null);
    try {
      await auditsApi.downloadReportPdf(auditId, service);
    } catch (err) {
      // Surfaces the backend's real message (e.g. a plan without
      // pdf_export) rather than a generic failure.
      setDownloadError(apiErrorMessage(err));
    } finally {
      setIsDownloading(false);
    }
  }

  function load() {
    setError(null);
    auditsApi
      .fetchServiceReports(auditId)
      .then(setData)
      .catch((err) => setError(apiErrorMessage(err)));
  }

  useEffect(load, [auditId]);

  // The page explorer is full-access only; a 403 for an anonymous viewer
  // is expected, not an error worth surfacing.
  useEffect(() => {
    if (!data?.is_full_access) return;
    auditsApi
      .fetchAuditPages(auditId)
      .then(setPages)
      .catch(() => setPages(null));
  }, [auditId, data?.is_full_access]);

  if (error) return <ErrorState message={error} onRetry={load} />;
  if (!data) return <Spinner label="Loading report…" />;

  const services = Object.values(data.services);
  const current = activeService ? data.services[activeService] : undefined;

  const totals = services.reduce(
    (acc, service) => ({
      critical: acc.critical + (service.severity_summary.critical ?? 0),
      high: acc.high + (service.severity_summary.high ?? 0),
    }),
    { critical: 0, high: 0 },
  );

  const navLinkClass = ({ isActive }: { isActive: boolean }) =>
    `whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium transition ${
      isActive
        ? 'bg-indigo-600 text-white'
        : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'
    }`;

  return (
    // Provides the single dialog every trigger opens, plus the dock that
    // follows the reader down the page (§47, §55).
    <ConsultationProvider auditId={auditId} websiteUrl={data?.overview.website_url}>
      <div className="mx-auto max-w-5xl px-4 py-8">
      {/* Audit header */}
      <Card>
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="min-w-0">
            <h1 className="truncate text-xl font-bold text-ink">{data.overview.website_url}</h1>
            <p className="mt-1 text-sm text-muted">
              {data.overview.audit_date ? new Date(data.overview.audit_date).toLocaleString() : 'Audit in progress'}
              {' · '}
              {data.overview.pages_crawled} page{data.overview.pages_crawled === 1 ? '' : 's'} analyzed
            </p>
          </div>
          <div className="flex items-start gap-4">
            {/* Location 1 of 4: the audit header (§55). */}
            <ConsultationTrigger source="audit_header" tone="button" label="Get help" />
            <button
              type="button"
              onClick={() => setIsSharing(true)}
              className="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
            >
              Share report
            </button>
            <div className="text-right">
              <p className="text-3xl font-bold text-ink">{data.overview.overall_score ?? 'N/A'}</p>
              <p className="text-xs uppercase tracking-wide text-faint">Overall</p>
            </div>
          </div>
        </div>

        {/* The site classification (§46). It decides which services were
            reported at all, so it belongs on the page that lists them —
            it had only ever been added to ReportOverviewCard, which this
            page does not use, so it never reached a reader. */}
        {data.overview.site_type_label && (
          <p className="mt-3 text-sm text-muted">
            <span className="font-medium">{data.overview.site_type_label}</span>
            {data.overview.site_type_description ? ` — ${data.overview.site_type_description}` : ''}
            {typeof data.overview.site_type_confidence === 'number' && (
              <span className="text-faint"> ({data.overview.site_type_confidence}% confidence)</span>
            )}
          </p>
        )}

        <div className="mt-4 flex flex-wrap gap-3 text-sm">
          <span className="rounded-md bg-red-50 px-3 py-1.5 font-medium text-red-700 dark:bg-red-950/50 dark:text-red-300">
            {totals.critical} critical
          </span>
          <span className="rounded-md bg-amber-50 px-3 py-1.5 font-medium text-amber-700 dark:bg-amber-950/50 dark:text-amber-300">
            {totals.high} high priority
          </span>
          <span className="rounded-md bg-slate-100 px-3 py-1.5 font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">
            Mobile-first assessment
          </span>
        </div>

        {/* Location 2 of 4: beside the critical findings, where the reason
            to ask for help is most concrete (§55). */}
        {totals.critical > 0 && <ConsultationTrigger source="critical_issues" tone="banner" />}
      </Card>

      {/* Service navigation — only services that actually ran */}
      <nav className="mt-6 flex gap-1 overflow-x-auto border-b border-line pb-2 dark:border-line" aria-label="Report sections">
        <NavLink to={`/audits/${auditId}/report`} end className={navLinkClass}>
          Overview
        </NavLink>
        {services.map((service) => (
          <NavLink key={service.service} to={`/audits/${auditId}/report/${service.service}`} className={navLinkClass}>
            {service.name}
          </NavLink>
        ))}
      </nav>

      {downloadError && (
        <p className="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300">
          {downloadError}
        </p>
      )}

      <div className="mt-6">
        {current ? (
          <ServiceReportView
            report={current}
            canDownloadPdf={data.is_full_access && !isDownloading}
            onDownloadPdf={() => void handleDownloadPdf(current.service)}
          />
        ) : (
          <div className="flex flex-col gap-6">
            {services.length === 0 ? (
              <EmptyState title="No audit services ran for this audit" />
            ) : (
              <>
                <div className="grid gap-4 sm:grid-cols-2">
                  {services.map((service) => (
                    <ServiceScoreCard key={service.service} report={service} auditId={auditId} />
                  ))}
                </div>

                {/* Location 3 of 4: the score summary (§55). */}
                <div className="flex justify-end">
                  <ConsultationTrigger
                    source="score_summary"
                    label="Talk to us about these scores"
                  />
                </div>
              </>
            )}

            {/* Owner-only, and it fetches its own data: a comparison
                names which pages were and were not crawled, which the
                preview view withholds (§52). */}
            {data.is_full_access && <AuditComparisonView auditId={auditId} />}

            {pages && pages.length > 0 && <PageExplorer pages={pages} />}

            {!data.is_full_access && (
              <Card>
                <p className="text-sm text-muted">
                  You're viewing a preview of this report. Sign in as the owner of this audit to see every issue,
                  page-by-page findings, and screenshots.
                </p>
              </Card>
            )}
          </div>
        )}
      </div>

      {/* Location 4 of 4: the report footer (§55). */}
      <div className="mt-10 border-t border-line pt-6 text-center dark:border-line">
        <p className="text-sm text-muted">
          Want someone to work through this report with you?
        </p>
        <div className="mt-2 flex justify-center">
          <ConsultationTrigger source="report_footer" tone="button" label="Request a consultation" />
        </div>
      </div>

        {isSharing && <ShareReportDialog auditId={auditId} onClose={() => setIsSharing(false)} />}
      </div>
    </ConsultationProvider>
  );
}
