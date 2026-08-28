import { useState } from 'react';
import { Card } from '../ui/Card';
import { ScreenshotViewer } from './ScreenshotViewer';
import type { CoreWebVital, PerformanceDetail, PerformanceStrategyBlock, VitalStatus } from '../../types';

const STATUS_STYLE: Record<VitalStatus, { dot: string; text: string; label: string }> = {
  good: { dot: 'bg-emerald-500', text: 'text-emerald-700 dark:text-emerald-400', label: 'Good' },
  needs_improvement: { dot: 'bg-amber-500', text: 'text-amber-700 dark:text-amber-400', label: 'Needs improvement' },
  poor: { dot: 'bg-red-500', text: 'text-red-700 dark:text-red-400', label: 'Poor' },
  unavailable: { dot: 'bg-slate-300', text: 'text-slate-500 dark:text-slate-400', label: 'Not measured' },
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
    <div className="rounded-lg border border-slate-200 p-4 dark:border-slate-800">
      <div className="flex items-center gap-2">
        <span className={`h-2.5 w-2.5 rounded-full ${style.dot}`} />
        <p className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{vital.label}</p>
      </div>
      <p className="mt-2 text-2xl font-bold text-slate-900 dark:text-white">{formatVital(vital)}</p>
      <p className={`text-xs font-medium ${style.text}`}>{style.label}</p>
      <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">{vital.about}</p>
      {vital.good_below !== null && (
        <p className="mt-1 text-xs text-slate-400">
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
          <h3 className="font-semibold text-slate-900 dark:text-white">
            {label}
            {isPrimary && (
              <span className="ml-2 rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">
                Primary
              </span>
            )}
          </h3>
          {isPrimary && (
            <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
              Most visitors arrive on a phone, so mobile is what we grade first.
            </p>
          )}
        </div>
        {block.score !== null && (
          <div className="text-right">
            <p className="text-3xl font-bold text-slate-900 dark:text-white">{block.score}</p>
            <p className="text-xs uppercase tracking-wide text-slate-400">out of 100</p>
          </div>
        )}
      </div>

      {!block.available ? (
        <p className="mt-3 rounded-md bg-slate-50 p-3 text-sm text-slate-600 dark:bg-slate-800/50 dark:text-slate-300">
          {block.reason}
        </p>
      ) : (
        <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {block.vitals.map((vital) => (
            <VitalCard key={vital.key} vital={vital} />
          ))}
        </div>
      )}
    </Card>
  );
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

      {detail.resources.available && (
        <Card>
          <h3 className="font-semibold text-slate-900 dark:text-white">What's making your pages heavy</h3>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
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
                    <span className="font-medium text-slate-700 dark:text-slate-200">{RESOURCE_LABEL[row.type] ?? row.type}</span>
                    <span className="text-slate-500 dark:text-slate-400">
                      {formatBytes(row.bytes)} · {row.requests} requests
                    </span>
                  </div>
                  <div className="mt-1 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
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
          <h3 className="font-semibold text-slate-900 dark:text-white">Page-by-page performance</h3>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Slowest first. A dash means that measurement wasn't taken for this page.
          </p>

          <div className="mt-4 overflow-x-auto">
            <table className="w-full min-w-[720px] text-left text-sm">
              <thead className="border-b border-slate-200 text-xs uppercase text-slate-500 dark:border-slate-800 dark:text-slate-400">
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
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {detail.pages.map((page) => (
                  <tr key={page.url}>
                    <td className="max-w-xs py-2 pr-3">
                      <p className="truncate font-medium text-slate-900 dark:text-white">{page.title ?? '—'}</p>
                      <p className="truncate font-mono text-xs text-slate-500 dark:text-slate-400">{page.url}</p>
                    </td>
                    <td className="px-2 py-2 font-semibold text-slate-900 dark:text-white">{page.mobile_score ?? '—'}</td>
                    <td className="px-2 py-2 text-slate-600 dark:text-slate-300">{page.desktop_score ?? '—'}</td>
                    <td className="px-2 py-2 text-slate-600 dark:text-slate-300">
                      {page.lcp_ms !== null ? `${(page.lcp_ms / 1000).toFixed(1)}s` : '—'}
                    </td>
                    <td className="px-2 py-2 text-slate-600 dark:text-slate-300">
                      {page.ttfb_ms !== null ? `${page.ttfb_ms}ms` : '—'}
                    </td>
                    <td className="px-2 py-2 text-slate-600 dark:text-slate-300">{page.total_requests ?? '—'}</td>
                    <td className="px-2 py-2 text-slate-600 dark:text-slate-300">
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
              <div key={page.url} className="mt-4 rounded-lg border border-slate-200 p-4 dark:border-slate-800">
                <p className="mb-3 truncate font-mono text-xs text-slate-500 dark:text-slate-400">{page.url}</p>
                <ScreenshotViewer screenshots={page.screenshots} alt={page.title ?? page.url} />
                {page.by_type && (
                  <dl className="mt-4 grid grid-cols-2 gap-2 text-xs sm:grid-cols-4">
                    {Object.entries(page.by_type).map(([type, data]) => (
                      <div key={type} className="rounded-md bg-slate-50 p-2 dark:bg-slate-800/50">
                        <dt className="text-slate-500 dark:text-slate-400">{RESOURCE_LABEL[type] ?? type}</dt>
                        <dd className="font-medium text-slate-900 dark:text-white">
                          {formatBytes(data.bytes)} · {data.requests}
                        </dd>
                      </div>
                    ))}
                  </dl>
                )}
                {page.dom_nodes !== null && (
                  <p className="mt-3 text-xs text-slate-500 dark:text-slate-400">{page.dom_nodes} DOM elements</p>
                )}
              </div>
            ))}
        </Card>
      )}
    </div>
  );
}
