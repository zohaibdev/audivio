import { Link } from 'react-router-dom';
import { AuthenticatedImage } from '../ui/AuthenticatedImage';
import type { MyWebsite } from '../../api/websites';
import { Card } from '../ui/Card';
import { ScoreGauge } from '../ui/ScoreGauge';

const SERVICE_LABELS: { key: keyof MyWebsite['scores']; label: string }[] = [
  { key: 'performance', label: 'Perf' },
  { key: 'accessibility', label: 'A11y' },
  { key: 'seo', label: 'SEO' },
  { key: 'conversion', label: 'CRO' },
  { key: 'aio', label: 'AIO' },
];

/** Runs that ended without a result — stated as an outcome, not progress. */
const TERMINAL_NOTICES: Record<string, string> = {
  failed: 'Last run failed',
  cancelled: 'Last run was cancelled',
};

const STAGE_LABELS: Record<string, string> = {
  queued: 'Queued',
  validating: 'Validating',
  crawling: 'Crawling',
  analyzing: 'Analyzing',
  generating_report: 'Generating report',
};

function formatDate(iso: string | null): string {
  if (!iso) return 'Never audited';
  return new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}

function scoreTone(score: number | null): string {
  if (score === null) return 'text-faint';
  if (score >= 90) return 'text-emerald-600 dark:text-emerald-400';
  if (score >= 70) return 'text-amber-600 dark:text-amber-400';
  return 'text-red-600 dark:text-red-400';
}

export function WebsiteCard({ website }: { website: MyWebsite }) {
  // The detail view is addressed by domain so the URL stays readable and
  // shareable. It is not what authorizes the request — the backend
  // resolves it against the caller's own websites.
  const detailPath = `/dashboard/websites?website=${encodeURIComponent(website.normalized_domain)}`;

  return (
    <Card className="flex flex-col gap-4">
      <Link to={detailPath} className="group flex gap-3">
        {website.screenshot_url ? (
          // Signed and authenticated since §48 — a thumbnail is still
          // a capture of someone's site, and an <img> cannot send the
          // auth header.
          <AuthenticatedImage
            src={website.screenshot_url}
            alt=""
            className="h-16 w-24 shrink-0 rounded border border-line object-cover object-top dark:border-slate-700"
          />
        ) : (
          <div className="flex h-16 w-24 shrink-0 items-center justify-center rounded border border-dashed border-slate-300 text-[10px] text-faint dark:border-slate-700">
            No preview
          </div>
        )}
        <div className="min-w-0">
          <p className="truncate font-semibold text-slate-900 group-hover:text-indigo-600 dark:text-white dark:group-hover:text-indigo-400">
            {website.name}
          </p>
          <p className="truncate text-xs text-muted">{website.domain}</p>
          <p className="mt-1 text-xs text-faint">
            {formatDate(website.last_audit_at)} · {website.audits_count} audit
            {website.audits_count === 1 ? '' : 's'}
          </p>
        </div>
      </Link>

      {website.latest_audit && (
        <p
          className={`rounded px-2 py-1 text-xs ${
            TERMINAL_NOTICES[website.latest_audit.status]
              ? 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'
              : 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300'
          }`}
        >
          {/* `latest_audit` is any newer run that has not completed — which
              includes finished-but-unsuccessful ones. Reporting a cancelled
              run as "Audit cancelled — 0/25 pages" read as progress. */}
          {TERMINAL_NOTICES[website.latest_audit.status] ??
            `${STAGE_LABELS[website.latest_audit.status] ?? 'Audit running'} — ${website.latest_audit.pages_crawled}/${website.latest_audit.max_pages} pages`}
        </p>
      )}

      <div className="flex items-center gap-4">
        <ScoreGauge score={website.overall_score} label="Overall" size="md" />
        <div className="grid flex-1 grid-cols-5 gap-1">
          {SERVICE_LABELS.map(({ key, label }) => (
            <div key={key} className="text-center">
              <p className={`text-sm font-semibold ${scoreTone(website.scores[key])}`}>
                {website.scores[key] ?? '—'}
              </p>
              <p className="text-[10px] uppercase tracking-wide text-faint">{label}</p>
            </div>
          ))}
        </div>
      </div>

      <dl className="grid grid-cols-3 gap-2 border-t border-slate-100 pt-3 text-center dark:border-line">
        <div>
          <dt className="text-[10px] uppercase tracking-wide text-faint">Pages</dt>
          <dd className="text-sm font-semibold text-ink">
            {website.pages_analyzed ?? '—'}
          </dd>
        </div>
        <div>
          <dt className="text-[10px] uppercase tracking-wide text-faint">Critical</dt>
          <dd className="text-sm font-semibold text-red-600 dark:text-red-400">{website.critical_issues}</dd>
        </div>
        <div>
          <dt className="text-[10px] uppercase tracking-wide text-faint">High</dt>
          <dd className="text-sm font-semibold text-amber-600 dark:text-amber-400">{website.high_issues}</dd>
        </div>
      </dl>

      <div className="flex gap-3 text-sm font-medium">
        <Link to={detailPath} className="text-indigo-600 hover:underline dark:text-indigo-400">
          Details →
        </Link>
        {website.last_audit_id && (
          <Link
            to={`/audits/${website.last_audit_id}/report`}
            className="text-muted hover:underline dark:text-faint"
          >
            Latest report
          </Link>
        )}
      </div>
    </Card>
  );
}
