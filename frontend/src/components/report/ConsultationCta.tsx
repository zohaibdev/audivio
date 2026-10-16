import { createContext, useCallback, useContext, useMemo, useState } from 'react';
import { submitLead, type LeadSource } from '../../api/leads';
import { SERVICE_NAMES } from '../../types';

/**
 * The consultation request (§47, extended §55).
 *
 * One dialog, several triggers. A report is long — seven services, a page
 * explorer, dozens of findings — and the moment someone decides they want
 * help is not predictable, so the ask appears at the header, beside the
 * scores, next to the critical findings, and in the footer, plus a dock
 * that follows the reader down the page.
 *
 * Each trigger reports its own `source`, so it is possible to see which
 * placement actually earns requests rather than guessing. And a trigger
 * next to a finding carries that finding's code, so the request arrives
 * saying what the visitor was looking at when they asked.
 */

interface ConsultationOptions {
  source: LeadSource;
  issueCodes?: string[];
}

interface ConsultationContextValue {
  open: (options: ConsultationOptions) => void;
}

const ConsultationContext = createContext<ConsultationContextValue | null>(null);

export function useConsultation(): ConsultationContextValue {
  const context = useContext(ConsultationContext);

  // A trigger rendered outside the provider would look like a working
  // button and silently do nothing, which is worse than not being there.
  if (context === null) {
    throw new Error('useConsultation must be used inside <ConsultationProvider>');
  }

  return context;
}

export function ConsultationProvider({
  auditId,
  websiteUrl,
  children,
}: {
  auditId?: number;
  websiteUrl?: string;
  children: React.ReactNode;
}) {
  const [request, setRequest] = useState<ConsultationOptions | null>(null);

  const open = useCallback((options: ConsultationOptions) => setRequest(options), []);
  const value = useMemo(() => ({ open }), [open]);

  return (
    <ConsultationContext.Provider value={value}>
      {children}

      {/* Follows the reader down the page, so the ask is reachable from
          anywhere in a long report rather than only where a trigger sits. */}
      {request === null && (
        <button
          type="button"
          onClick={() => open({ source: 'talk_to_consultant' })}
          className="fixed bottom-4 right-4 z-40 rounded-full bg-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-lg transition hover:bg-indigo-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-900"
        >
          Request consultation
        </button>
      )}

      {request !== null && (
        <ConsultationDialog
          auditId={auditId}
          websiteUrl={websiteUrl}
          source={request.source}
          issueCodes={request.issueCodes}
          onClose={() => setRequest(null)}
        />
      )}
    </ConsultationContext.Provider>
  );
}

/** An inline ask, placed at one of the report's named locations. */
export function ConsultationTrigger({
  source,
  issueCodes,
  label = 'Request consultation',
  tone = 'link',
}: {
  source: LeadSource;
  issueCodes?: string[];
  label?: string;
  tone?: 'link' | 'button' | 'banner';
}) {
  const { open } = useConsultation();

  if (tone === 'banner') {
    return (
      <div className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-indigo-50 px-4 py-3 dark:bg-indigo-950/40">
        <p className="text-sm text-indigo-900 dark:text-indigo-200">
          Want help working through these? We can walk you through the fixes.
        </p>
        <button
          type="button"
          onClick={() => open({ source, issueCodes })}
          className="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-500"
        >
          {label}
        </button>
      </div>
    );
  }

  if (tone === 'button') {
    return (
      <button
        type="button"
        onClick={() => open({ source, issueCodes })}
        className="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-500"
      >
        {label}
      </button>
    );
  }

  return (
    <button
      type="button"
      onClick={() => open({ source, issueCodes })}
      className="text-sm font-medium text-indigo-600 underline-offset-2 hover:underline dark:text-indigo-400"
    >
      {label}
    </button>
  );
}

function ConsultationDialog({
  auditId,
  websiteUrl,
  source,
  issueCodes,
  onClose,
}: {
  auditId?: number;
  websiteUrl?: string;
  source: LeadSource;
  issueCodes?: string[];
  onClose: () => void;
}) {
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [message, setMessage] = useState('');
  const [services, setServices] = useState<string[]>([]);
  const [status, setStatus] = useState<'idle' | 'sending' | 'sent' | 'error'>('idle');
  const [error, setError] = useState<string | null>(null);

  function toggleService(key: string) {
    setServices((current) =>
      current.includes(key) ? current.filter((s) => s !== key) : [...current, key],
    );
  }

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setStatus('sending');
    setError(null);

    try {
      await submitLead({
        name,
        email,
        message: message || undefined,
        website_url: websiteUrl,
        audit_id: auditId,
        // Sent as the visitor's claim, checked against the audit on the
        // server — see ConsultationRequestService (§55).
        issue_codes: issueCodes?.length ? issueCodes : undefined,
        requested_services: services.length ? services : undefined,
        source,
      });

      setStatus('sent');
    } catch (caught) {
      setStatus('error');
      // The real reason where the API gave one — "your email looks
      // wrong" is actionable, "something went wrong" is not.
      setError(
        (caught as { response?: { data?: { message?: string } } })?.response?.data?.message ??
          'We could not send that just now. Please try again.',
      );
    }
  }

  return (
    <div
      role="dialog"
      aria-label="Request a consultation"
      className="fixed bottom-4 right-4 z-40 max-h-[calc(100vh-2rem)] w-[min(24rem,calc(100vw-2rem))] overflow-y-auto rounded-xl border border-line bg-white p-5 shadow-xl dark:border-slate-700 dark:bg-slate-900"
    >
      <div className="flex items-start justify-between gap-3">
        <h3 className="font-semibold text-ink">Request a consultation</h3>
        <button
          type="button"
          onClick={onClose}
          aria-label="Close"
          className="text-faint hover:text-slate-700 dark:hover:text-slate-200"
        >
          ✕
        </button>
      </div>

      {status === 'sent' ? (
        <p className="mt-3 text-sm text-muted">
          Thanks — we have your details and this report, and we will be in touch.
        </p>
      ) : (
        <form onSubmit={submit} className="mt-3 flex flex-col gap-3">
          <p className="text-sm text-muted">
            Tell us where to reach you and we will walk you through what this report found.
          </p>

          {issueCodes && issueCodes.length > 0 && (
            <p className="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
              We'll include the {issueCodes.length} finding{issueCodes.length === 1 ? '' : 's'} you
              were looking at.
            </p>
          )}

          <label className="text-sm">
            <span className="text-muted">Name</span>
            <input
              required
              value={name}
              onChange={(event) => setName(event.target.value)}
              className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
            />
          </label>

          <label className="text-sm">
            <span className="text-muted">Email</span>
            <input
              required
              type="email"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
            />
          </label>

          <fieldset>
            <legend className="text-sm text-muted">
              What do you want help with? <span className="text-faint">(optional)</span>
            </legend>
            <div className="mt-2 flex flex-wrap gap-2">
              {Object.entries(SERVICE_NAMES).map(([key, label]) => (
                <button
                  key={key}
                  type="button"
                  aria-pressed={services.includes(key)}
                  onClick={() => toggleService(key)}
                  className={
                    services.includes(key)
                      ? 'rounded-full bg-indigo-600 px-3 py-1 text-xs font-medium text-white'
                      : 'rounded-full border border-slate-300 px-3 py-1 text-xs text-slate-600 dark:border-slate-700 dark:text-slate-300'
                  }
                >
                  {label}
                </button>
              ))}
            </div>
          </fieldset>

          <label className="text-sm">
            <span className="text-muted">
              Anything specific? <span className="text-faint">(optional)</span>
            </span>
            <textarea
              rows={3}
              value={message}
              onChange={(event) => setMessage(event.target.value)}
              className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
            />
          </label>

          {error && <p className="text-sm text-red-600 dark:text-red-400">{error}</p>}

          <button
            type="submit"
            disabled={status === 'sending'}
            className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60"
          >
            {status === 'sending' ? 'Sending…' : 'Request consultation'}
          </button>
        </form>
      )}
    </div>
  );
}
