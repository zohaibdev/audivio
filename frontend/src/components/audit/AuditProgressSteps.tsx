import type { AuditStatus } from '../../types';

/**
 * The lifecycle as a user sees it. `validating` is folded into "Queued"
 * rather than given its own step — it is real (the backend re-runs the
 * SSRF checks before touching the network) but lasts milliseconds, and a
 * step that flashes past is noise rather than information.
 */
const steps: { label: string; statuses: AuditStatus[] }[] = [
  { label: 'Queued', statuses: ['queued', 'validating'] },
  { label: 'Crawling site', statuses: ['crawling'] },
  { label: 'Running audits & scoring', statuses: ['analyzing'] },
  { label: 'Generating report', statuses: ['generating_report'] },
];

function currentStepIndex(status: AuditStatus): number {
  const index = steps.findIndex((step) => step.statuses.includes(status));

  // `completed` is past the last step; anything unrecognised is treated as
  // "not started" rather than crashing the progress display.
  if (index !== -1) return index;
  return status === 'completed' ? steps.length : 0;
}

export function AuditProgressSteps({ status }: { status: AuditStatus }) {
  const currentIndex = currentStepIndex(status);
  const isFinished = status === 'completed';

  return (
    <ol className="mx-auto flex max-w-2xl flex-col gap-4">
      {steps.map((step, index) => {
        const isDone = isFinished || index < currentIndex;
        const isCurrent = !isFinished && index === currentIndex;

        return (
          <li key={step.label} className="flex items-center gap-3">
            <span
              className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold ${
                isDone
                  ? 'bg-emerald-500 text-white'
                  : isCurrent
                    ? 'animate-pulse bg-indigo-600 text-white'
                    : 'bg-slate-200 text-muted dark:bg-slate-700'
              }`}
              aria-hidden="true"
            >
              {isDone ? '✓' : index + 1}
            </span>
            <span
              className={
                isCurrent
                  ? 'font-medium text-ink'
                  : 'text-muted'
              }
            >
              {step.label}
            </span>
          </li>
        );
      })}
    </ol>
  );
}
