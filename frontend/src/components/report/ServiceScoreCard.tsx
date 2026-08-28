import { Link } from 'react-router-dom';
import type { ServiceReport, ServiceStatus } from '../../types';

const STATUS_STYLES: Record<ServiceStatus, { ring: string; text: string; label: string }> = {
  good: { ring: 'text-emerald-500', text: 'text-emerald-700 dark:text-emerald-400', label: 'Good' },
  needs_improvement: { ring: 'text-amber-500', text: 'text-amber-700 dark:text-amber-400', label: 'Needs work' },
  poor: { ring: 'text-red-500', text: 'text-red-700 dark:text-red-400', label: 'Poor' },
  // Deliberately not green: a partial score means most of the assessment
  // did not run, so it must never look like a pass at a glance.
  partial: { ring: 'text-slate-400', text: 'text-slate-600 dark:text-slate-300', label: 'Incomplete' },
  not_applicable: { ring: 'text-slate-300', text: 'text-slate-500 dark:text-slate-400', label: 'Not applicable' },
};

function ScoreRing({ score, status }: { score: number | null; status: ServiceStatus }) {
  const style = STATUS_STYLES[status];
  const pct = score ?? 0;
  const circumference = 2 * Math.PI * 26;

  return (
    <div className="relative h-20 w-20 shrink-0">
      <svg viewBox="0 0 64 64" className="h-full w-full -rotate-90">
        <circle cx="32" cy="32" r="26" fill="none" strokeWidth="6" className="stroke-slate-200 dark:stroke-slate-700" />
        {score !== null && (
          <circle
            cx="32"
            cy="32"
            r="26"
            fill="none"
            strokeWidth="6"
            strokeLinecap="round"
            className={`${style.ring} stroke-current`}
            strokeDasharray={circumference}
            strokeDashoffset={circumference - (pct / 100) * circumference}
          />
        )}
      </svg>
      <span className={`absolute inset-0 flex items-center justify-center text-lg font-bold ${style.text}`}>
        {score ?? 'N/A'}
      </span>
    </div>
  );
}

export function ServiceScoreCard({ report, auditId }: { report: ServiceReport; auditId: number }) {
  const style = STATUS_STYLES[report.status];
  const counts = report.severity_summary;
  const criticalish = (counts.critical ?? 0) + (counts.high ?? 0);

  return (
    <Link
      to={`/audits/${auditId}/report/${report.service}`}
      className="flex gap-4 rounded-xl border border-slate-200 bg-white p-5 transition hover:border-indigo-300 hover:shadow-sm dark:border-slate-800 dark:bg-slate-900 dark:hover:border-indigo-700"
    >
      <ScoreRing score={report.score} status={report.status} />
      <div className="min-w-0 flex-1">
        <h3 className="font-semibold text-slate-900 dark:text-white">{report.name}</h3>
        <p className={`text-sm font-medium ${style.text}`}>{style.label}</p>

        {report.is_applicable ? (
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {counts.total === 0
              ? 'No issues found'
              : `${counts.total} issue${counts.total === 1 ? '' : 's'}${criticalish > 0 ? ` · ${criticalish} need attention` : ''}`}
          </p>
        ) : (
          <p className="mt-1 line-clamp-2 text-sm text-slate-500 dark:text-slate-400">{report.not_applicable_reason}</p>
        )}

        {report.confidence === 'partial' && (
          <p className="mt-1 text-xs font-medium text-amber-700 dark:text-amber-400">Incomplete — AI checks did not run</p>
        )}

        <span className="mt-2 inline-block text-sm font-medium text-indigo-600 dark:text-indigo-400">View report →</span>
      </div>
    </Link>
  );
}
