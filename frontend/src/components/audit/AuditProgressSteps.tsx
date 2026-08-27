import type { AuditStatus } from '../../types';

const steps: { key: AuditStatus | 'report'; label: string }[] = [
  { key: 'pending', label: 'Queued' },
  { key: 'crawling', label: 'Crawling site' },
  { key: 'analyzing', label: 'Running audits & scoring' },
  { key: 'report', label: 'Generating report' },
];

const order: Record<string, number> = { pending: 0, crawling: 1, analyzing: 2, report: 3, completed: 4 };

export function AuditProgressSteps({ status, waitingOnReport }: { status: AuditStatus; waitingOnReport: boolean }) {
  const currentIndex = status === 'completed' && waitingOnReport ? order.report : order[status];

  return (
    <ol className="mx-auto flex max-w-2xl flex-col gap-4">
      {steps.map((step, index) => {
        const isDone = index < currentIndex || (status === 'completed' && !waitingOnReport);
        const isCurrent = index === currentIndex && !(status === 'completed' && !waitingOnReport);
        return (
          <li key={step.key} className="flex items-center gap-3">
            <span
              className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold ${
                isDone
                  ? 'bg-emerald-500 text-white'
                  : isCurrent
                    ? 'animate-pulse bg-indigo-600 text-white'
                    : 'bg-slate-200 text-slate-500 dark:bg-slate-700'
              }`}
              aria-hidden="true"
            >
              {isDone ? '✓' : index + 1}
            </span>
            <span className={isCurrent ? 'font-medium text-slate-900 dark:text-white' : 'text-slate-500 dark:text-slate-400'}>
              {step.label}
            </span>
          </li>
        );
      })}
    </ol>
  );
}
