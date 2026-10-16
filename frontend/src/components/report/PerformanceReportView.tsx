import { useState } from 'react';
import { Card } from '../ui/Card';
import { ScreenshotViewer } from './ScreenshotViewer';
import type {
  CoreWebVital,
  PerformanceDetail,
  PerformanceDiagnostic,
  PerformanceFieldBlock,
  PerformanceOpportunity,
  PerformanceStrategyBlock,
  VitalStatus,
} from '../../types';

const STATUS_STYLE: Record<VitalStatus, { dot: string; text: string; label: string }> = {
  good: { dot: 'bg-emerald-500', text: 'text-emerald-700 dark:text-emerald-400', label: 'Good' },
  needs_improvement: { dot: 'bg-amber-500', text: 'text-amber-700 dark:text-amber-400', label: 'Needs improvement' },
  poor: { dot: 'bg-red-500', text: 'text-red-700 dark:text-red-400', label: 'Poor' },
  unavailable: { dot: 'bg-slate-300', text: 'text-muted', label: 'Not measured' },
};

/** CSS `capitalize` renders these as "Js"/"Css", which reads as a typo. */
const RESOURCE_LABEL: Record<string, string> = {
  js: 'JavaScript',
  css: 'CSS',
  image: 'Images',
  font: 'Fonts',
  document: 'HTML',
  media: 'Video / audio',
  other: 'Other',
};

function formatBytes(bytes: number): string {
  if (bytes >= 1024 * 1024) return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
  return `${Math.round(bytes / 1024)} KB`;
}

function formatVital(vital: CoreWebVital): string {
  if (vital.value === null) return '—';
  if (vital.unit === 'ms') {
    return vital.value >= 1000 ? `${(vital.value / 1000).toFixed(2)} s` : `${Math.round(vital.value)} ms`;
  }
  return vital.value.toFixed(3);
}

function VitalCard({ vital }: { vital: CoreWebVital }) {
  const style = STATUS_STYLE[vital.status];

  return (
    <div className="rounded-lg border border-line p-4 dark:border-line">
      <div className="flex items-center gap-2">
        <span className={`h-2.5 w-2.5 rounded-full ${style.dot}`} />
        <p className="text-xs font-medium uppercase tracking-wide text-muted">{vital.label}</p>
      </div>
      <p className="mt-2 text-2xl font-bold text-ink">{formatVital(vital)}</p>
      <p className={`text-xs font-medium ${style.text}`}>{style.label}</p>
      <p className="mt-2 text-xs text-muted">{vital.about}</p>
      {vital.good_below !== null && (
        <p className="mt-1 text-xs text-faint">
          Good is under {vital.unit === 'ms' ? `${(vital.good_below / 1000).toFixed(1)}s` : vital.good_below}
          {vital.source && ` · measured by ${vital.source === 'pagespeed' ? 'Lighthouse' : 'our crawler'}`}
        </p>
      )}
    </div>
  );
}

function StrategyPanel({ block, label, isPrimary }: { block: PerformanceStrategyBlock; label: string; isPrimary?: boolean }) {
  return (
    <Card>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h3 className="font-semibold text-ink">
            {label}
            {isPrimary && (
              <span className="ml-2 rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">
                Primary
              </span>
            )}
          </h3>
          {isPrimary && (
            <p className="mt-1 text-xs text-muted">
              Most visitors arrive on a phone, so mobile is what we grade first.
            </p>
          )}
        </div>
        {block.score !== null && (
          <div className="text-right">
            <p className="text-3xl font-bold text-ink">{block.score}</p>
            <p className="text-xs uppercase tracking-wide text-faint">out of 100</p>
          </div>
        )}
      </div>

      {!block.available ? (
        <p className="mt-3 rounded-md bg-slate-50 p-3 text-sm text-slate-600 dark:bg-slate-800/50 dark:text-slate-300">
          {block.reason}
        </p>
      ) : (
        <>
          <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {block.vitals.map((vital) => (
              <VitalCard key={vital.key} vital={vital} />
            ))}
          </div>

          {block.potential_savings?.ms != null && (
            <p className="mt-4 rounded-md bg-emerald-50 p-3 text-sm text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200">
              Fixing everything below could save about{' '}
              <strong>{(block.potential_savings.ms / 1000).toFixed(1)}s</strong>
              {block.potential_savings.bytes ? ` and ${formatBytes(block.potential_savings.bytes)}` : ''}{' '}
              on this page. These are Lighthouse's own measured estimates.
            </p>
          )}

          {block.field && <FieldPanel field={block.field} />}

          <FindingsList
            opportunities={block.opportunities ?? []}
            diagnostics={block.diagnostics ?? []}
            hiddenOpportunities={block.opportunity_count ?? 0}
            hiddenDiagnostics={block.diagnostic_count ?? 0}
          />
        </>
      )}
    </Card>
  );
}

/**
 * Real-user data, and — when there is none — why. INP lives only here:
 * it is measured from real interactions, so a lab run cannot produce one,
 * and dropping it silently would read as "no INP problem".
 */
function FieldPanel({ field }: { field: PerformanceFieldBlock }) {
  if (!field.available) {
    return (
      <div className="mt-4 rounded-md border border-line p-3 dark:border-line">
        <p className="text-xs font-semibold uppercase tracking-wide text-muted">
          Real-user data (including INP)
        </p>
        <p className="mt-1 text-sm text-muted">{field.reason}</p>
      </div>
    );
  }

  return (
    <div className="mt-4 rounded-md border border-line p-3 dark:border-line">
      <p className="text-xs font-semibold uppercase tracking-wide text-muted">
        Real-user data{field.overall_category ? ` — ${field.overall_category.toLowerCase()}` : ''}
      </p>
      <dl className="mt-2 flex flex-wrap gap-4">
        {Object.entries(field.metrics).map(([key, metric]) => (
          <div key={key}>
            <dt className="text-xs text-muted">{FIELD_LABELS[key] ?? key}</dt>
            <dd className="text-lg font-semibold text-ink">
              {key === 'cls' ? metric.value.toFixed(2) : `${metric.value}ms`}
            </dd>
          </div>
        ))}
      </dl>
      {field.note && <p className="mt-2 text-xs text-muted">{field.note}</p>}
    </div>
  );
}

const FIELD_LABELS: Record<string, string> = {
  inp_ms: 'INP',
  lcp_ms: 'LCP',
  cls: 'CLS',
  fcp_ms: 'FCP',
  ttfb_ms: 'TTFB',
};

/**
 * Opportunities and diagnostics, kept apart because Lighthouse keeps them
 * apart: an opportunity has a measured saving, a diagnostic is a real
 * problem with no number attached. Showing a saving on the latter would
 * invent the figure a reader acts on.
 */
function FindingsList({
  opportunities,
  diagnostics,
  hiddenOpportunities,
  hiddenDiagnostics,
}: {
  opportunities: PerformanceOpportunity[];
  diagnostics: PerformanceDiagnostic[];
  hiddenOpportunities: number;
  hiddenDiagnostics: number;
}) {
  const [tab, setTab] = useState<'opportunities' | 'diagnostics'>('opportunities');

  const visible = tab === 'opportunities' ? opportunities : diagnostics;
  const hidden = tab === 'opportunities' ? hiddenOpportunities : hiddenDiagnostics;

  if (opportunities.length === 0 && diagnostics.length === 0 && hidden === 0) {
    return null;
  }

  return (
    <div className="mt-4">
      <div className="flex gap-2 border-b border-line pb-2 dark:border-line">
        <button
          type="button"
          onClick={() => setTab('opportunities')}
          className={tabClass(tab === 'opportunities')}
        >
          Opportunities ({opportunities.length || hiddenOpportunities})
        </button>
        <button
          type="button"
          onClick={() => setTab('diagnostics')}
          className={tabClass(tab === 'diagnostics')}
        >
          Diagnostics ({diagnostics.length || hiddenDiagnostics})
        </button>
      </div>

      {visible.length === 0 ? (
        <p className="mt-3 text-sm text-muted">
          {hidden > 0
            ? `${hidden} finding${hidden === 1 ? '' : 's'} in this section. Sign in as the site owner to see them.`
            : 'Nothing found in this section.'}
        </p>
      ) : (
        <ul className="mt-3 space-y-2">
          {visible.map((finding) => {
            // Only opportunities carry a saving; a diagnostic has none by
            // construction, and inventing one is the thing this split
            // exists to prevent.
            const savingsMs =
              'savings_ms' in finding && typeof finding.savings_ms === 'number'
                ? finding.savings_ms
                : null;

            return (
            <li
              key={finding.id}
              className="rounded-md border border-line p-3 dark:border-line"
            >
              <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-medium text-ink">{finding.title}</p>
                {savingsMs !== null && (
                  <span className="text-xs font-medium text-emerald-700 dark:text-emerald-400">
                    saves ~{(savingsMs / 1000).toFixed(1)}s
                  </span>
                )}
              </div>

              {finding.display_value && (
                <p className="mt-1 text-sm text-muted">
                  {finding.display_value}
                </p>
              )}

              {finding.resources.length > 0 && (
                <ul className="mt-2 space-y-1">
                  {finding.resources.slice(0, 5).map((resource) => (
                    <li
                      key={resource.url}
                      className="truncate font-mono text-xs text-muted"
                    >
                      {resource.url}
                      {resource.wasted_bytes ? ` — ${formatBytes(resource.wasted_bytes)} wasted` : ''}
                    </li>
                  ))}
                </ul>
              )}
            </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}

function tabClass(active: boolean): string {
  return active
    ? 'rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white dark:bg-white dark:text-slate-900'
    : 'rounded-md px-3 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800';
}

/**
 * The deep performance report: Core Web Vitals per strategy, a measured
 * resource breakdown, and per-page metrics. Mobile leads throughout —
 * it is the primary audit priority, so it is rendered first and labelled
 * as such, with desktop kept visibly separate rather than blended in.
 */
export function PerformanceReportView({ detail }: { detail: PerformanceDetail }) {
  const [openPage, setOpenPage] = useState<string | null>(null);

  return (
    <div className="flex flex-col gap-6">
      <StrategyPanel block={detail.mobile} label="Mobile" isPrimary />
      <StrategyPanel block={detail.desktop} label="Desktop" />

      {detail.categories.some((category) => category.finding_count > 0) && (
        <Card>
          <h3 className="font-semibold text-ink">Where the time goes</h3>
          <p className="mt-1 text-sm text-muted">
            Findings grouped by area, from the{' '}
            {detail.categories.find((category) => category.strategy)?.strategy ?? 'mobile'} run.
          </p>
          <ul className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            {detail.categories
              .filter((category) => category.finding_count > 0)
              .map((category) => (
                <li
                  key={category.category}
                  className="flex items-baseline justify-between gap-2 rounded-md bg-slate-50 p-3 dark:bg-slate-800/50"
                >
                  <span className="text-sm text-ink">{category.label}</span>
                  <span className="text-xs text-muted">
                    {category.finding_count}
                    {category.savings_ms ? ` · ~${(category.savings_ms / 1000).toFixed(1)}s` : ''}
                  </span>
                </li>
              ))}
          </ul>
        </Card>
      )}

      {detail.resources.available && (
        <Card>
          <h3 className="font-semibold text-ink">What's making your pages heavy</h3>
          <p className="mt-1 text-sm text-muted">
            Measured across {detail.resources.measured_pages} page
            {detail.resources.measured_pages === 1 ? '' : 's'} — {formatBytes(detail.resources.totals.transfer_bytes ?? 0)} downloaded
            in {detail.resources.totals.requests ?? 0} requests, averaging{' '}
            {formatBytes(detail.resources.totals.avg_transfer_bytes_per_page ?? 0)} per page.
          </p>

          <div className="mt-4 space-y-2">
            {detail.resources.by_type.map((row) => {
              const total = detail.resources.totals.transfer_bytes || 1;
              const pct = Math.round((row.bytes / total) * 100);
              return (
                <div key={row.type}>
                  <div className="flex justify-between text-sm">
                    <span className="font-medium text-ink">{RESOURCE_LABEL[row.type] ?? row.type}</span>
                    <span className="text-muted">
                      {formatBytes(row.bytes)} · {row.requests} requests
                    </span>
                  </div>
                  <div className="mt-1 h-2 overflow-hidden rounded-full bg-surface-2">
                    <div className="h-full rounded-full bg-indigo-500" style={{ width: `${pct}%` }} />
                  </div>
                </div>
              );
            })}
          </div>

          {(detail.resources.totals.third_party_bytes ?? 0) > 0 && (
            <p className="mt-4 rounded-md bg-slate-50 p-3 text-sm text-slate-600 dark:bg-slate-800/50 dark:text-slate-300">
              {formatBytes(detail.resources.totals.third_party_bytes)} of that comes from third-party services
              ({detail.resources.totals.third_party_requests} requests) — things like analytics, fonts, chat widgets and embeds.
            </p>
          )}
        </Card>
      )}

      {detail.pages.length > 0 && (
        <Card>
          <h3 className="font-semibold text-ink">Page-by-page performance</h3>
          <p className="mt-1 text-sm text-muted">
            Slowest first. A dash means that measurement wasn't taken for this page.
          </p>

          <div className="mt-4 overflow-x-auto">
            <table className="w-full min-w-[720px] text-left text-sm">
              <thead className="border-b border-line text-xs uppercase text-muted dark:border-line dark:text-faint">
                <tr>
                  <th className="py-2 pr-3">Page</th>
                  <th className="px-2 py-2">Mobile</th>
                  <th className="px-2 py-2">Desktop</th>
                  <th className="px-2 py-2">LCP</th>
                  <th className="px-2 py-2">TTFB</th>
                  <th className="px-2 py-2">Requests</th>
                  <th className="px-2 py-2">Weight</th>
                  <th className="px-2 py-2" />
                </tr>
              </thead>
              <tbody className="divide-y divide-line">
                {detail.pages.map((page) => (
                  <tr key={page.url}>
                    <td className="max-w-xs py-2 pr-3">
                      <p className="truncate font-medium text-ink">{page.title ?? '—'}</p>
                      <p className="truncate font-mono text-xs text-muted">{page.url}</p>
                    </td>
                    <td className="px-2 py-2 font-semibold text-ink">{page.mobile_score ?? '—'}</td>
                    <td className="px-2 py-2 text-muted">{page.desktop_score ?? '—'}</td>
                    <td className="px-2 py-2 text-muted">
                      {page.lcp_ms !== null ? `${(page.lcp_ms / 1000).toFixed(1)}s` : '—'}
                    </td>
                    <td className="px-2 py-2 text-muted">
                      {page.ttfb_ms !== null ? `${page.ttfb_ms}ms` : '—'}
                    </td>
                    <td className="px-2 py-2 text-muted">{page.total_requests ?? '—'}</td>
                    <td className="px-2 py-2 text-muted">
                      {page.transfer_bytes !== null ? formatBytes(page.transfer_bytes) : '—'}
                    </td>
                    <td className="px-2 py-2">
                      <button
                        type="button"
                        onClick={() => setOpenPage(openPage === page.url ? null : page.url)}
                        className="text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400"
                      >
                        {openPage === page.url ? 'Hide' : 'View'}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {detail.pages
            .filter((page) => page.url === openPage)
            .map((page) => (
              <div key={page.url} className="mt-4 rounded-lg border border-line p-4 dark:border-line">
                <p className="mb-3 truncate font-mono text-xs text-muted">{page.url}</p>
                <ScreenshotViewer screenshots={page.screenshots} alt={page.title ?? page.url} />
                {page.by_type && (
                  <dl className="mt-4 grid grid-cols-2 gap-2 text-xs sm:grid-cols-4">
                    {Object.entries(page.by_type).map(([type, data]) => (
                      <div key={type} className="rounded-md bg-slate-50 p-2 dark:bg-slate-800/50">
                        <dt className="text-muted">{RESOURCE_LABEL[type] ?? type}</dt>
                        <dd className="font-medium text-ink">
                          {formatBytes(data.bytes)} · {data.requests}
                        </dd>
                      </div>
                    ))}
                  </dl>
                )}
                {page.dom_nodes !== null && (
                  <p className="mt-3 text-xs text-muted">{page.dom_nodes} DOM elements</p>
                )}
              </div>
            ))}
        </Card>
      )}
    </div>
  );
}
