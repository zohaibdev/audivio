import { Card } from '../ui/Card';
import type { AuditProgress } from '../../types';

/**
 * What the audit is doing, while it does it (§51).
 *
 * The skeleton is the point: a reader waiting several minutes should see
 * the *shape* of the report they are going to get, not a spinner. Every
 * number shown is measured, and where nothing has been measured the panel
 * says so instead of filling the gap — a bar creeping toward 90% because
 * someone is waiting is a lie that gets believed.
 */
export function AuditProgressPanel({ progress }: { progress: AuditProgress }) {
  const { estimate } = progress;

  return (
    <div className="flex flex-col gap-6">
      <Card>
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <p className="text-xs font-semibold uppercase tracking-wide text-muted">
              Current stage
            </p>
            <p className="mt-1 text-xl font-bold text-ink">
              {progress.stage_label ?? 'Starting'}
            </p>
            {progress.services_running.length > 0 && (
              <p className="mt-1 text-sm text-muted">
                Running: {progress.services_running.join(', ')}
              </p>
            )}
          </div>

          <div className="text-right">
            {estimate.is_available && estimate.remaining_seconds !== null ? (
              <>
                <p className="text-xl font-bold text-ink">
                  ~{formatDuration(estimate.remaining_seconds)}
                </p>
                <p className="text-xs uppercase tracking-wide text-faint">remaining</p>
              </>
            ) : (
              // No rate measured yet, so no number. The reason is shown
              // rather than a placeholder that would read as a real
              // estimate.
              <p className="max-w-xs text-xs text-muted">{estimate.reason}</p>
            )}
          </div>
        </div>

        <div className="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
          <Stat label="Pages discovered" value={progress.pages_discovered} />
          <Stat label="Pages analyzed" value={progress.pages_analyzed} />
          <Stat label="Screenshots" value={progress.screenshots_captured} />
          <Stat
            label="Elapsed"
            text={estimate.elapsed_seconds !== null ? formatDuration(estimate.elapsed_seconds) : '—'}
          />
        </div>

        {estimate.basis && (
          <p className="mt-3 text-xs text-faint">{estimate.basis}</p>
        )}

        {progress.scale_note && (
          <p className="mt-3 rounded-md bg-slate-50 p-3 text-sm text-slate-600 dark:bg-slate-800/50 dark:text-slate-300">
            {progress.scale_note}
          </p>
        )}
      </Card>

      <Card>
        <h3 className="font-semibold text-ink">Stages</h3>
        <ol className="mt-3 grid gap-2 sm:grid-cols-2">
          {progress.stages.map((step) => (
            <li key={step.stage} className="flex items-center gap-2 text-sm">
              <span aria-hidden="true" className={markerClass(step.state)}>
                {step.state === 'done' ? '✓' : step.state === 'current' ? '●' : '○'}
              </span>
              <span className={labelClass(step.state)}>{step.label}</span>
            </li>
          ))}
        </ol>
      </Card>

      {progress.services.length === 0 ? (
        // Said out loud rather than hidden. Since §56 an audit with no
        // enabled services *fails* rather than completing with a
        // fabricated overall score of 0, so this warns about what is
        // actually going to happen (§51, §56).
        <Card>
          <h3 className="font-semibold text-ink">Services</h3>
          <p className="mt-2 text-sm text-amber-700 dark:text-amber-400">
            No audit services are enabled for this plan, so there is nothing to assess and this audit
            will stop rather than produce an empty report. Enable at least one service on the plan,
            or contact support if that is unexpected.
          </p>
        </Card>
      ) : (
        <Card>
          <h3 className="font-semibold text-ink">Services</h3>
          <ul className="mt-3 grid gap-2 sm:grid-cols-2">
            {progress.services.map((service) => (
              <li
                key={service.category}
                className="flex items-center justify-between gap-2 rounded-md bg-slate-50 px-3 py-2 text-sm dark:bg-slate-800/50"
              >
                <span className="text-ink">{service.label}</span>
                <span className={serviceStateClass(service.state)}>
                  {service.state === 'done' ? 'Done' : service.state === 'running' ? 'Running' : 'Waiting'}
                </span>
              </li>
            ))}
          </ul>
        </Card>
      )}

      <ReportSkeleton />
    </div>
  );
}

/**
 * The shape of the report that is coming.
 *
 * Deliberately inert grey blocks, never numbers or placeholder scores: a
 * skeleton showing "87" while the audit runs would be read as a result.
 */
function ReportSkeleton() {
  return (
    <Card>
      <h3 className="font-semibold text-ink">Your report</h3>
      <p className="mt-1 text-sm text-muted">
        This is the layout your finished report will use.
      </p>

      <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3" aria-hidden="true">
        {Array.from({ length: 6 }).map((_, index) => (
          <div
            key={index}
            className="rounded-xl border border-line p-4 dark:border-line"
          >
            <div className="flex items-start justify-between gap-3">
              <div className="h-3 w-24 animate-pulse rounded bg-slate-200 dark:bg-slate-800" />
              <div className="h-7 w-10 animate-pulse rounded bg-slate-200 dark:bg-slate-800" />
            </div>
            <div className="mt-3 h-2 w-full animate-pulse rounded bg-surface-2/70" />
            <div className="mt-2 h-2 w-2/3 animate-pulse rounded bg-surface-2/70" />
          </div>
        ))}
      </div>
    </Card>
  );
}

function Stat({ label, value, text }: { label: string; value?: number; text?: string }) {
  return (
    <div className="rounded-md bg-slate-50 p-3 dark:bg-slate-800/50">
      <p className="text-2xl font-bold text-ink">{text ?? value ?? 0}</p>
      <p className="text-xs text-muted">{label}</p>
    </div>
  );
}

function markerClass(state: 'done' | 'current' | 'pending'): string {
  if (state === 'done') return 'text-emerald-600 dark:text-emerald-400';
  if (state === 'current') return 'animate-pulse text-brand';
  return 'text-slate-300 dark:text-slate-700';
}

function labelClass(state: 'done' | 'current' | 'pending'): string {
  if (state === 'current') return 'font-medium text-ink';
  if (state === 'done') return 'text-muted';
  return 'text-faint';
}

function serviceStateClass(state: 'done' | 'running' | 'pending'): string {
  if (state === 'done') return 'text-xs font-medium text-emerald-600 dark:text-emerald-400';
  if (state === 'running') return 'text-xs font-medium text-brand';
  return 'text-xs text-faint';
}

/**
 * Rounded to something a person would say out loud. A remaining time
 * quoted to the second implies a precision the projection does not have.
 */
function formatDuration(seconds: number): string {
  if (seconds < 60) return `${Math.max(1, Math.round(seconds / 5) * 5)}s`;

  const minutes = Math.round(seconds / 60);

  if (minutes < 60) return `${minutes} min`;

  const hours = Math.floor(minutes / 60);

  return `${hours}h ${minutes % 60}m`;
}
