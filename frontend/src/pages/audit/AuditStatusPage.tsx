import { useEffect, useRef, useState } from 'react';
import { useParams } from 'react-router-dom';
import { cancelAudit, downloadReportPdf, fetchAuditStatus, fetchReport } from '../../api/audits';
import { apiErrorMessage, ApiRequestError } from '../../api/client';
import { useAuth } from '../../contexts/AuthContext';
import { AuditProgressSteps } from '../../components/audit/AuditProgressSteps';
import { AuditProgressPanel } from '../../components/audit/AuditProgressPanel';
import { ReportView } from '../../components/report/ReportView';
import { Spinner } from '../../components/ui/Spinner';
import { ErrorState } from '../../components/ui/ErrorState';
import { Button } from '../../components/ui/Button';
import type { AuditSummary, Report } from '../../types';

// The status/report endpoints share one throttle:10,1 (10 req/min) group
// on the backend. Polling faster than 6s/request risks a 429 on any
// audit that takes more than ~30s — this interval keeps normal polling
// safely under that ceiling, and RETRY_AFTER_429_MS below still backs
// off further on the rare case a burst gets close to the limit anyway.
const POLL_INTERVAL_MS = 7000;
const RETRY_AFTER_429_MS = 15000;

export function AuditStatusPage() {
  const { auditId } = useParams<{ auditId: string }>();
  const id = Number(auditId);
  const { user } = useAuth();

  const [audit, setAudit] = useState<AuditSummary | null>(null);
  const [report, setReport] = useState<Report | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [pdfError, setPdfError] = useState<string | null>(null);
  const [isDownloading, setIsDownloading] = useState(false);
  const [isCancelling, setIsCancelling] = useState(false);
  const [cancelError, setCancelError] = useState<string | null>(null);
  const reportAttempts = useRef(0);

  useEffect(() => {
    if (!id || Number.isNaN(id)) {
      setError('Invalid audit ID.');
      return;
    }

    let cancelled = false;
    let timeoutId: ReturnType<typeof setTimeout>;

    async function poll() {
      try {
        const status = await fetchAuditStatus(id);
        if (cancelled) return;
        setAudit(status);

        // Any terminal state ends the poll. Previously only `failed`
        // did, so a cancelled audit would be polled forever.
        if (status.status === 'failed' || status.status === 'cancelled') {
          return;
        }

        if (status.status === 'completed') {
          try {
            const fetchedReport = await fetchReport(id);
            if (!cancelled) setReport(fetchedReport);
          } catch (err) {
            // GenerateReportJob can lag a moment behind the audit flipping
            // to completed — retry a few times before giving up. A 429
            // here doesn't count against that retry budget; it just means
            // "wait longer," not "something is actually wrong."
            const isRateLimited = err instanceof ApiRequestError && err.status === 429;
            reportAttempts.current += isRateLimited ? 0 : 1;
            if (reportAttempts.current < 10 && !cancelled) {
              timeoutId = setTimeout(poll, isRateLimited ? RETRY_AFTER_429_MS : POLL_INTERVAL_MS);
            }
          }
          return;
        }

        timeoutId = setTimeout(poll, POLL_INTERVAL_MS);
      } catch (err) {
        if (cancelled) return;
        if (err instanceof ApiRequestError && err.status === 429) {
          timeoutId = setTimeout(poll, RETRY_AFTER_429_MS);
          return;
        }
        setError(apiErrorMessage(err));
      }
    }

    poll();

    return () => {
      cancelled = true;
      clearTimeout(timeoutId);
    };
  }, [id]);

  async function handleCancel() {
    setCancelError(null);
    setIsCancelling(true);
    try {
      await cancelAudit(id);
      // Re-read rather than assuming: the audit may have finished in the
      // moment between the click and the request arriving, in which case
      // the backend refuses and the real status is the honest thing to
      // show.
      setAudit(await fetchAuditStatus(id));
    } catch (err) {
      setCancelError(apiErrorMessage(err));
    } finally {
      setIsCancelling(false);
    }
  }

  async function handleDownload() {
    setPdfError(null);
    setIsDownloading(true);
    try {
      await downloadReportPdf(id);
    } catch (err) {
      setPdfError(apiErrorMessage(err));
    } finally {
      setIsDownloading(false);
    }
  }

  if (error) {
    return (
      <div className="mx-auto max-w-2xl px-4 py-16">
        <ErrorState message={error} />
      </div>
    );
  }

  if (!audit) {
    return <Spinner label="Loading audit…" />;
  }

  if (audit.status === 'failed') {
    return (
      <div className="mx-auto max-w-2xl px-4 py-16">
        <ErrorState
          message={audit.error_message ?? 'This audit failed to complete.'}
        />
        {audit.failed_stage && (
          <p className="mt-3 text-center text-sm text-muted">
            It stopped during the {audit.failed_stage.replace(/_/g, ' ')} stage.
          </p>
        )}
      </div>
    );
  }

  if (audit.status === 'cancelled') {
    // Not an ErrorState: the user asked for this, so it is an outcome
    // rather than a fault.
    return (
      <div className="mx-auto max-w-2xl px-4 py-16 text-center">
        <h1 className="text-2xl font-bold text-ink">Audit cancelled</h1>
        <p className="mt-2 text-sm text-muted">
          You stopped this audit before it finished, so there is no report for it.
        </p>
      </div>
    );
  }

  if (!report) {
    return (
      <div className="mx-auto max-w-3xl px-4 py-12">
        <h1 className="mb-8 text-center text-2xl font-bold text-ink">Auditing your website…</h1>

        {/* The detailed panel when the backend supplies progress (§51),
            falling back to the coarse step list for anything fetched
            before it existed. */}
        {audit.progress ? (
          <AuditProgressPanel progress={audit.progress} />
        ) : (
          <>
            <AuditProgressSteps status={audit.status} />
            <p className="mt-8 text-center text-sm text-muted">
              {audit.pages_crawled} of up to {audit.max_pages} pages crawled so far.
            </p>
          </>
        )}

        {audit.is_cancellable && (
          <div className="mt-8 text-center">
            <Button type="button" variant="secondary" isLoading={isCancelling} onClick={handleCancel}>
              Cancel this audit
            </Button>
            <p className="mt-2 text-xs text-faint">
              Cancelling frees the slot so you can start another audit right away.
            </p>
            {cancelError && (
              <p className="mt-2 text-sm text-red-600 dark:text-red-400" role="alert">
                {cancelError}
              </p>
            )}
          </div>
        )}
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-4xl px-4 py-12">
      {pdfError && <p className="mb-4 text-sm text-red-600 dark:text-red-400">{pdfError}</p>}
      <ReportView
        report={report}
        auditId={id}
        onDownloadPdf={handleDownload}
        canDownloadPdf={Boolean(user) && !isDownloading}
      />
    </div>
  );
}
