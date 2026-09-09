import { useState } from 'react';
import { Card } from '../ui/Card';
import { Badge } from '../ui/Badge';
import type { BacklinkState, SeoDetail, SeoFileStatus, SeoRedirectEntry } from '../../types';

type Tab = 'pages' | 'redirects' | 'social' | 'schema';

const TABS: { key: Tab; label: string }[] = [
  { key: 'pages', label: 'Page by page' },
  { key: 'redirects', label: 'Redirects' },
  { key: 'social', label: 'Share preview' },
  { key: 'schema', label: 'Structured data' },
];

/**
 * The SEO service's own view (§44): the four dedicated reports, plus the
 * site-level files and the backlink state.
 *
 * Tabbed rather than stacked — these are four different questions, and a
 * single scroll through all of them buries the one the reader came for.
 */
export function SeoReportView({
  detail,
  backlinks,
}: {
  detail: SeoDetail;
  backlinks?: BacklinkState | null;
}) {
  const [tab, setTab] = useState<Tab>('pages');

  return (
    <div className="flex flex-col gap-6">
      <Card>
        <h3 className="font-semibold text-ink">Site files and links</h3>

        <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
          <FileStat label="robots.txt" status={detail.site.robots_txt.status} />
          <FileStat
            label="XML sitemap"
            status={detail.site.sitemap.status}
            detail={
              detail.site.sitemap.url_count !== null
                ? `${detail.site.sitemap.url_count} URLs`
                : undefined
            }
          />
          <Stat label="Internal links" value={detail.site.links.internal} />
          <Stat label="Broken links" value={detail.site.links.broken_internal} />
        </div>

        {detail.site.links.orphan_pages > 0 && (
          <p className="mt-3 text-sm text-muted">
            {detail.site.links.orphan_pages} crawled page
            {detail.site.links.orphan_pages === 1 ? '' : 's'} nothing links to:{' '}
            <span className="font-mono text-xs">
              {detail.site.links.orphan_page_urls.join(', ')}
            </span>
          </p>
        )}
      </Card>

      {backlinks && <BacklinksCard state={backlinks} />}

      <Card>
        <div className="flex flex-wrap gap-2 border-b border-line pb-3 dark:border-line">
          {TABS.map((entry) => (
            <button
              key={entry.key}
              type="button"
              onClick={() => setTab(entry.key)}
              className={`rounded-md px-3 py-1.5 text-sm font-medium transition ${
                tab === entry.key
                  ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900'
                  : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'
              }`}
            >
              {entry.label}
            </button>
          ))}
        </div>

        <div className="mt-4">
          {tab === 'pages' && <PageTable detail={detail} />}
          {tab === 'redirects' && <RedirectReport detail={detail} />}
          {tab === 'social' && <SocialPreviews detail={detail} />}
          {tab === 'schema' && <SchemaReport detail={detail} />}
        </div>
      </Card>

      {detail.coverage && <CoverageCard detail={detail} />}
    </div>
  );
}

function PageTable({ detail }: { detail: SeoDetail }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-left text-sm">
        <thead className="text-xs uppercase tracking-wide text-muted">
          <tr>
            <th className="pb-2 pr-4">URL</th>
            <th className="pb-2 pr-4">Status</th>
            <th className="pb-2 pr-4">Title</th>
            <th className="pb-2 pr-4">Desc</th>
            <th className="pb-2 pr-4">H1</th>
            <th className="pb-2 pr-4">Links in/out</th>
            <th className="pb-2">Indexable</th>
          </tr>
        </thead>
        <tbody>
          {detail.pages.map((page) => (
            <tr key={page.url} className="border-t border-line">
              <td className="max-w-xs truncate py-2 pr-4 font-mono text-xs">{page.url}</td>
              <td className="py-2 pr-4">{page.http_status ?? '—'}</td>
              <td className="py-2 pr-4">{page.title_length ?? '—'}</td>
              <td className="py-2 pr-4">{page.meta_description_length ?? '—'}</td>
              <td className="py-2 pr-4">{page.h1_count}</td>
              <td className="py-2 pr-4">
                {page.internal_links_in} / {page.internal_links_out}
              </td>
              <td className="py-2">
                {page.is_indexable ? (
                  <Badge tone="green">Yes</Badge>
                ) : (
                  <span title={page.indexability_reasons.join(' ')}>
                    <Badge tone="red">No</Badge>
                  </span>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function RedirectReport({ detail }: { detail: SeoDetail }) {
  const { redirects } = detail;

  if (redirects.total === 0) {
    return (
      <p className="text-sm text-muted">
        No redirects were encountered while crawling these pages.
      </p>
    );
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Stat label="Redirected URLs" value={redirects.total} />
        <Stat label="Chains" value={redirects.chains} />
        <Stat label="Loops" value={redirects.loops} />
        <Stat label="Temporary" value={redirects.temporary} />
      </div>

      <ul className="space-y-3">
        {redirects.entries.map((entry) => (
          <RedirectRow key={entry.final_url} entry={entry} />
        ))}
      </ul>

      <p className="text-xs text-muted">{redirects.scope_note}</p>
    </div>
  );
}

function RedirectRow({ entry }: { entry: SeoRedirectEntry }) {
  return (
    <li className="rounded-md border border-line p-3 dark:border-line">
      <div className="flex flex-wrap items-center gap-2">
        {entry.is_loop && <Badge tone="red">Loop</Badge>}
        {entry.is_chain && !entry.is_loop && <Badge tone="yellow">Chain</Badge>}
        {entry.has_temporary_redirect && <Badge tone="yellow">Temporary</Badge>}
        <span className="text-xs text-muted">
          {entry.hop_count} hop{entry.hop_count === 1 ? '' : 's'}
        </span>
      </div>

      <ol className="mt-2 space-y-1">
        {entry.hops.map((hop, index) => (
          <li
            key={`${hop.url}-${index}`}
            className="truncate font-mono text-xs text-muted"
          >
            {/* A hop with no recorded status shows "?" rather than a
                guessed code — 301 and 302 mean opposite things. */}
            {hop.status ?? '?'} → {hop.url}
          </li>
        ))}
        <li className="truncate font-mono text-xs text-ink">
          {entry.final_status ?? '?'} → {entry.final_url}
        </li>
      </ol>
    </li>
  );
}

function SocialPreviews({ detail }: { detail: SeoDetail }) {
  const { social } = detail;

  if (social.analyzed_page_count === null) {
    return (
      <p className="text-sm text-muted">
        This audit was crawled before social card tags were captured. Re-run the audit to see share
        previews.
      </p>
    );
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Stat label="Pages analyzed" value={social.analyzed_page_count} />
        <Stat label="With Open Graph" value={social.pages_with_open_graph} />
        <Stat label="With an image" value={social.pages_with_og_image} />
        <Stat label="With a card type" value={social.pages_with_twitter_card} />
      </div>

      <ul className="grid gap-4 sm:grid-cols-2">
        {social.previews.map((page) => (
          <li
            key={page.url}
            className="overflow-hidden rounded-lg border border-line"
          >
            {page.preview.image.value ? (
              // The audited site's own og:image, loaded from wherever it
              // is hosted — a plain <img> is right here. `no-referrer`
              // keeps the reader's report URL out of that host's logs,
              // and a broken or mixed-content image (an http: og:image on
              // an https page is common) hides rather than showing a
              // broken-image icon.
              <img
                src={page.preview.image.value}
                alt=""
                loading="lazy"
                referrerPolicy="no-referrer"
                onError={(event) => {
                  event.currentTarget.style.display = 'none';
                }}
                className="h-40 w-full bg-slate-100 object-cover dark:bg-slate-800"
              />
            ) : (
              <div className="flex h-40 w-full items-center justify-center bg-slate-100 text-xs text-faint dark:bg-slate-800">
                No image — this link will share without one
              </div>
            )}

            <div className="p-3">
              <p className="truncate text-xs uppercase tracking-wide text-faint">
                {page.preview.site_name.value ?? new URL(page.url).hostname}
              </p>
              <p className="mt-1 font-medium text-ink">
                {page.preview.title.value ?? 'No title'}
              </p>
              <p className="mt-1 line-clamp-2 text-sm text-muted">
                {page.preview.description.value ?? 'No description'}
              </p>

              <dl className="mt-3 space-y-1 text-xs text-muted">
                <SourceLine label="Title" field={page.preview.title} />
                <SourceLine label="Description" field={page.preview.description} />
              </dl>
            </div>
          </li>
        ))}
      </ul>

      <p className="text-xs text-muted">{social.preview_note}</p>
    </div>
  );
}

function SourceLine({
  label,
  field,
}: {
  label: string;
  field: { source: string | null; is_fallback: boolean };
}) {
  if (field.source === null) {
    return (
      <div className="flex gap-2">
        <dt>{label}</dt>
        <dd className="text-amber-600 dark:text-amber-400">not set</dd>
      </div>
    );
  }

  return (
    <div className="flex gap-2">
      <dt>{label}</dt>
      <dd className={field.is_fallback ? 'text-amber-600 dark:text-amber-400' : ''}>
        {/* Says where the value came from, so an owner can see a title
            they never wrote for social being borrowed. */}
        from {field.source}
        {field.is_fallback ? ' (fallback)' : ''}
      </dd>
    </div>
  );
}

function SchemaReport({ detail }: { detail: SeoDetail }) {
  return (
    <div className="flex flex-col gap-4">
      <ul className="grid gap-3 sm:grid-cols-2">
        {detail.schema.types.map((type) => (
          <li
            key={type.type}
            className="rounded-md border border-line p-3 dark:border-line"
          >
            <div className="flex items-center justify-between gap-2">
              <p className="font-medium text-ink">{type.label}</p>
              {type.present ? (
                <Badge tone="green">{`${type.page_count} page${type.page_count === 1 ? '' : 's'}`}</Badge>
              ) : (
                <Badge tone="gray">Not found</Badge>
              )}
            </div>
            <p className="mt-1 text-sm text-muted">{type.why}</p>
          </li>
        ))}
      </ul>

      {detail.schema.pages.some((page) => page.problems.length > 0) && (
        <div>
          <h4 className="font-medium text-ink">Incomplete markup</h4>
          <ul className="mt-2 space-y-2">
            {detail.schema.pages
              .filter((page) => page.problems.length > 0)
              .map((page) => (
                <li
                  key={page.url}
                  className="rounded-md border border-line p-3 dark:border-line"
                >
                  <p className="truncate font-mono text-xs text-muted">
                    {page.url}
                  </p>
                  {page.problems.map((problem) => (
                    <p
                      key={`${page.url}-${problem.type}`}
                      className="mt-1 text-sm text-ink"
                    >
                      <span className="font-medium">{problem.label}</span>
                      {problem.missing_required.length > 0 &&
                        ` — missing required: ${problem.missing_required.join(', ')}`}
                      {problem.missing_recommended.length > 0 &&
                        ` — missing recommended: ${problem.missing_recommended.join(', ')}`}
                    </p>
                  ))}
                </li>
              ))}
          </ul>
        </div>
      )}

      <p className="text-xs text-muted">{detail.schema.note}</p>
    </div>
  );
}

function CoverageCard({ detail }: { detail: SeoDetail }) {
  const notRun = detail.coverage?.checks.filter((check) => !check.ran) ?? [];

  if (notRun.length === 0) {
    return null;
  }

  return (
    <Card>
      <h3 className="font-semibold text-ink">Checks that did not run</h3>
      {/* A check that never ran produces no findings, so without this it
          looks exactly like a check that passed. */}
      <ul className="mt-3 space-y-2">
        {notRun.map((check) => (
          <li key={check.check} className="text-sm text-muted">
            <span className="font-medium text-ink">{check.label}</span> —{' '}
            {check.not_run_reason}
          </li>
        ))}
      </ul>
    </Card>
  );
}

function BacklinksCard({ state }: { state: BacklinkState }) {
  return (
    <Card>
      <h3 className="font-semibold text-ink">Backlinks</h3>

      {state.status === 'available' && state.data ? (
        <>
          <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
            <Stat label="Total backlinks" value={state.data.total_backlinks} />
            <Stat label="Referring domains" value={state.data.referring_domains} />
            <Stat label="Domain authority" value={state.data.domain_authority} />
          </div>
          {/* Attributed and dated: backlink indexes disagree substantially,
              and an unattributed figure reads as a fact about the web. */}
          <p className="mt-3 text-xs text-muted">
            Source: {state.data.provider}, retrieved{' '}
            {new Date(state.data.fetched_at).toLocaleDateString()}.
          </p>
        </>
      ) : (
        <p className="mt-2 text-sm text-muted">{state.message}</p>
      )}
    </Card>
  );
}

function FileStat({
  label,
  status,
  detail,
}: {
  label: string;
  status: SeoFileStatus;
  detail?: string;
}) {
  const tone = status === 'found' ? 'green' : status === 'not_found' ? 'red' : 'gray';
  // "Unknown" is its own state: the crawl could not tell, which is not the
  // same as the file being absent.
  const text = status === 'found' ? 'Found' : status === 'not_found' ? 'Missing' : 'Not checked';

  return (
    <div className="rounded-md bg-slate-50 p-3 dark:bg-slate-800/50">
      <Badge tone={tone}>{text}</Badge>
      <p className="mt-1 text-xs text-muted">{label}</p>
      {detail && <p className="text-xs text-faint">{detail}</p>}
    </div>
  );
}

function Stat({ label, value }: { label: string; value: number | null }) {
  return (
    <div className="rounded-md bg-slate-50 p-3 dark:bg-slate-800/50">
      <p className="text-2xl font-bold text-ink">{value ?? '—'}</p>
      <p className="text-xs text-muted">{label}</p>
    </div>
  );
}
