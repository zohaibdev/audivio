import { useState } from 'react';
import { AuthenticatedImage } from '../ui/AuthenticatedImage';
import { Link } from 'react-router-dom';
import {
  updateWebsite,
  type IssueTrendPoint,
  type ScorePoint,
  type WebsiteDetail as WebsiteDetailData,
} from '../../api/websites';
import { apiErrorMessage } from '../../api/client';
import { Card } from '../ui/Card';
import { CompetitorPanel, OnboardingPrompt } from './CompetitorPanel';
import { AioPromptsSection } from './AioPromptsSection';
import { Button } from '../ui/Button';
import { Input } from '../ui/Input';
import { ScoreGauge } from '../ui/ScoreGauge';
import { Badge } from '../ui/Badge';

function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—';
  return new Date(iso).toLocaleString(undefined, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

const CATEGORY_LABELS: Record<string, string> = {
  overall: 'Overall',
  performance: 'Performance',
  accessibility: 'Accessibility',
  seo: 'SEO',
  conversion: 'CRO',
  aio: 'AIO',
  ecommerce: 'Ecommerce',
  landing_page: 'Landing Page',
  product_auditor: 'Product',
};

function label(category: string): string {
  return CATEGORY_LABELS[category] ?? category;
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="mb-8">
      <h2 className="mb-3 text-lg font-semibold text-ink">{title}</h2>
      {children}
    </section>
  );
}

/**
 * A minimal inline sparkline. Deliberately SVG rather than a chart
 * library: a trend line here answers one question ("is this going up or
 * down") and does not need axes, tooltips, or a second dependency.
 */
function Sparkline({ points }: { points: ScorePoint[] }) {
  if (points.length < 2) {
    return <span className="text-xs text-faint">Needs 2+ audits</span>;
  }

  const width = 120;
  const height = 32;
  const scores = points.map((p) => p.score);
  const min = Math.min(...scores, 0);
  const max = Math.max(...scores, 100);
  const span = max - min || 1;

  const path = points
    .map((p, i) => {
      const x = (i / (points.length - 1)) * width;
      const y = height - ((p.score - min) / span) * height;
      return `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`;
    })
    .join(' ');

  const first = scores[0];
  const last = scores[scores.length - 1];
  const improving = last >= first;

  return (
    <div className="flex items-center gap-2">
      <svg width={width} height={height} className="overflow-visible" aria-hidden="true">
        <path
          d={path}
          fill="none"
          strokeWidth="2"
          className={improving ? 'stroke-emerald-500' : 'stroke-red-500'}
        />
      </svg>
      <span className={`text-xs font-medium ${improving ? 'text-emerald-600' : 'text-red-600'}`}>
        {improving ? '+' : ''}
        {last - first}
      </span>
    </div>
  );
}

/**
 * Overall score across every completed audit (§52).
 *
 * Bigger than the sparkline because this one is read for values, not
 * just direction: each point carries its score and the date it was
 * measured. The y-axis is pinned to 0-100 rather than fitted to the
 * data — auto-scaling would turn a 3-point wobble into a cliff.
 */
function ScoreHistoryChart({ points }: { points: ScorePoint[] }) {
  if (points.length === 0) {
    return <p className="text-sm text-muted">Score history appears after your first completed audit.</p>;
  }

  if (points.length === 1) {
    return (
      <div className="flex items-baseline gap-3">
        <span className="text-3xl font-bold text-ink">{points[0].score}</span>
        <span className="text-sm text-muted">
          {formatDate(points[0].date)} — run another audit to see how this moves.
        </span>
      </div>
    );
  }

  const width = 640;
  const height = 160;
  const padding = { top: 12, right: 12, bottom: 24, left: 28 };
  const plotWidth = width - padding.left - padding.right;
  const plotHeight = height - padding.top - padding.bottom;

  const x = (index: number) => padding.left + (index / (points.length - 1)) * plotWidth;
  const y = (score: number) => padding.top + (1 - score / 100) * plotHeight;

  const path = points.map((p, i) => `${i === 0 ? 'M' : 'L'}${x(i).toFixed(1)},${y(p.score).toFixed(1)}`).join(' ');
  const change = points[points.length - 1].score - points[0].score;

  return (
    <div>
      <div className="flex flex-wrap items-baseline gap-3">
        <span className="text-3xl font-bold text-ink">
          {points[points.length - 1].score}
        </span>
        <span className={change >= 0 ? 'text-sm font-medium text-emerald-600' : 'text-sm font-medium text-red-600'}>
          {change > 0 ? '+' : ''}
          {change} since {formatDate(points[0].date)}
        </span>
      </div>

      <svg
        viewBox={`0 0 ${width} ${height}`}
        className="mt-3 w-full"
        role="img"
        aria-label={`Overall score history: ${points.map((p) => p.score).join(', ')}`}
      >
        {[0, 50, 100].map((tick) => (
          <g key={tick}>
            <line
              x1={padding.left}
              x2={width - padding.right}
              y1={y(tick)}
              y2={y(tick)}
              className="stroke-slate-200 dark:stroke-slate-800"
              strokeWidth="1"
            />
            <text x={0} y={y(tick) + 4} className="fill-slate-400 text-[10px]">
              {tick}
            </text>
          </g>
        ))}

        <path d={path} fill="none" strokeWidth="2" className="stroke-indigo-500" />

        {points.map((point, index) => (
          <circle key={point.audit_id} cx={x(index)} cy={y(point.score)} r="3.5" className="fill-indigo-500" />
        ))}

        {/* Only the endpoints are labelled: a date under every point
            collides as soon as a site has more than a handful of runs. */}
        <text x={padding.left} y={height - 6} className="fill-slate-400 text-[10px]">
          {formatDate(points[0].date)}
        </text>
        <text x={width - padding.right} y={height - 6} textAnchor="end" className="fill-slate-400 text-[10px]">
          {formatDate(points[points.length - 1].date)}
        </text>
      </svg>
    </div>
  );
}

function IssueTrendTable({ points }: { points: IssueTrendPoint[] }) {
  if (points.length === 0) {
    return <p className="text-sm text-muted">No completed audits yet.</p>;
  }

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-left text-sm">
        <thead className="text-xs uppercase tracking-wide text-faint">
          <tr>
            <th className="py-2 pr-4">Audit</th>
            <th className="py-2 pr-4">Pages</th>
            <th className="py-2 pr-4">Critical</th>
            <th className="py-2 pr-4">High</th>
            <th className="py-2 pr-4">Medium</th>
            <th className="py-2">Low</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-line">
          {points.map((point) => (
            <tr key={point.audit_id}>
              <td className="py-2 pr-4 text-muted">{formatDate(point.date)}</td>
              {/* Beside the counts on purpose: 40 issues found across 25
                  pages and 40 across 5 are not the same result (§52). */}
              <td className="py-2 pr-4 text-muted">{point.pages_analyzed}</td>
              <td className="py-2 pr-4 font-medium text-red-600 dark:text-red-400">{point.critical}</td>
              <td className="py-2 pr-4 font-medium text-amber-600 dark:text-amber-400">{point.high}</td>
              <td className="py-2 pr-4 text-muted">{point.medium}</td>
              <td className="py-2 text-muted">{point.low}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function ProfileForm({
  detail,
  onSaved,
}: {
  detail: WebsiteDetailData;
  onSaved: (updated: WebsiteDetailData) => void;
}) {
  const { overview } = detail;
  const [form, setForm] = useState({
    name: overview.name ?? '',
    category: overview.category ?? '',
    description: overview.description ?? '',
    products_or_services: overview.products_or_services ?? '',
    aio_goal: overview.aio_goal ?? '',
    country: overview.country ?? '',
    budget: overview.budget ?? '',
    average_product_cost: overview.average_product_cost ?? '',
    competitors: (overview.competitors ?? []).join('\n'),
  });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setSaving(true);
    setError(null);
    setSaved(false);

    try {
      const updated = await updateWebsite(overview.id, {
        name: form.name || null,
        category: form.category || null,
        description: form.description || null,
        products_or_services: form.products_or_services || null,
        aio_goal: form.aio_goal || null,
        country: form.country || null,
        budget: form.budget === '' ? null : Number(form.budget),
        average_product_cost:
          form.average_product_cost === '' ? null : Number(form.average_product_cost),
        competitors: form.competitors
          .split('\n')
          .map((line) => line.trim())
          .filter(Boolean),
      });
      onSaved(updated);
      setSaved(true);
    } catch (err) {
      setError(apiErrorMessage(err));
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
      <Input label="Name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
      <Input
        label="Category"
        value={form.category}
        placeholder="e.g. ecommerce, saas"
        onChange={(e) => setForm({ ...form, category: e.target.value })}
      />
      <Input
        label="Country (2-letter code)"
        value={form.country}
        maxLength={2}
        placeholder="GB"
        onChange={(e) => setForm({ ...form, country: e.target.value })}
      />
      <Input
        label="Marketing budget"
        type="number"
        min="0"
        step="0.01"
        value={form.budget}
        onChange={(e) => setForm({ ...form, budget: e.target.value })}
      />
      <Input
        label="Average product cost"
        type="number"
        min="0"
        step="0.01"
        value={form.average_product_cost}
        onChange={(e) => setForm({ ...form, average_product_cost: e.target.value })}
      />
      <label className="text-sm sm:col-span-2">
        <span className="mb-1 block font-medium text-muted">Description</span>
        <textarea
          value={form.description}
          rows={3}
          onChange={(e) => setForm({ ...form, description: e.target.value })}
          className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white"
        />
      </label>
      <label className="text-sm sm:col-span-2">
        <span className="mb-1 block font-medium text-muted">
          Products or services
        </span>
        <textarea
          value={form.products_or_services}
          rows={3}
          onChange={(e) => setForm({ ...form, products_or_services: e.target.value })}
          className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white"
        />
      </label>
      <label className="text-sm sm:col-span-2">
        <span className="mb-1 block font-medium text-muted">
          What do you want to be found for in AI search?
        </span>
        <textarea
          value={form.aio_goal}
          rows={2}
          placeholder="e.g. handmade British ceramics for independent retailers"
          onChange={(e) => setForm({ ...form, aio_goal: e.target.value })}
          className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white"
        />
        <span className="mt-1 block text-xs text-faint">
          Three of the ten AI search checks measure your content against this. Without it they are
          skipped rather than guessed at.
        </span>
      </label>

      <label className="text-sm sm:col-span-2">
        <span className="mb-1 block font-medium text-muted">
          Competitors (one per line)
        </span>
        <textarea
          value={form.competitors}
          rows={3}
          onChange={(e) => setForm({ ...form, competitors: e.target.value })}
          className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white"
        />
      </label>

      <div className="flex items-center gap-3 sm:col-span-2">
        <Button type="submit" isLoading={saving}>
          Save profile
        </Button>
        {saved && <span className="text-sm text-emerald-600 dark:text-emerald-400">Saved.</span>}
        {error && (
          <span className="text-sm text-red-600 dark:text-red-400" role="alert">
            {error}
          </span>
        )}
      </div>
    </form>
  );
}

export function WebsiteDetail({
  detail,
  onUpdated,
}: {
  detail: WebsiteDetailData;
  onUpdated: (updated: WebsiteDetailData) => void;
}) {
  const { overview } = detail;
  // Overall has its own chart above, so the per-service list does not
  // repeat it (§52).
  const trendCategories = Object.keys(detail.score_trends).filter((category) => category !== 'overall');

  return (
    <div>
      <div className="mb-8">
        <OnboardingPrompt
          missingFields={detail.onboarding.missing_fields}
          unlocksContextPrompts={detail.onboarding.unlocks_context_prompts}
        />
      </div>

      <Section title="Website Overview">
        <Card>
          <div className="flex flex-wrap items-start gap-4">
            {overview.screenshot_url && (
              <AuthenticatedImage
                src={overview.screenshot_url}
                alt=""
                className="h-24 w-36 rounded border border-line object-cover object-top dark:border-slate-700"
              />
            )}
            <div className="min-w-0 flex-1">
              <h3 className="text-xl font-bold text-ink">{overview.name}</h3>
              <a
                href={overview.primary_url}
                target="_blank"
                rel="noreferrer noopener"
                className="text-sm text-indigo-600 hover:underline dark:text-indigo-400"
              >
                {overview.primary_url}
              </a>
              <p className="mt-1 flex flex-wrap gap-2 text-xs text-faint">
                {overview.platform && <Badge>{overview.platform}</Badge>}
                {overview.category && <Badge>{overview.category}</Badge>}
                {overview.country && <Badge>{overview.country}</Badge>}
                <span>{overview.audits_count} audits</span>
              </p>
              {overview.description && (
                <p className="mt-2 text-sm text-muted">{overview.description}</p>
              )}
            </div>
            <ScoreGauge score={overview.overall_score} label="Overall" size="lg" />
          </div>
        </Card>
      </Section>

      <Section title="Latest Audit">
        {detail.latest_audit ? (
          <Card>
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div>
                <p className="text-sm text-muted">
                  Audit #{detail.latest_audit.id} · {String(detail.latest_audit.status)}
                </p>
                <p className="text-xs text-faint">
                  Completed {formatDate(detail.latest_audit.completed_at as string | null)} ·{' '}
                  {String(detail.latest_audit.pages_crawled ?? '—')} pages
                </p>
              </div>
              <Link
                to={`/audits/${detail.latest_audit.id}/report`}
                className="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400"
              >
                Open report →
              </Link>
            </div>
          </Card>
        ) : (
          <p className="text-sm text-muted">No audits have finished for this website yet.</p>
        )}
      </Section>

      <Section title="Service Scores">
        {detail.service_scores.length > 0 ? (
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
            {detail.service_scores.map((service) => (
              <Card key={service.category} className="text-center">
                <ScoreGauge
                  score={service.is_applicable ? service.score : null}
                  label={label(service.category)}
                  size="sm"
                />
                {!service.is_applicable && (
                  <p className="mt-1 text-[10px] uppercase tracking-wide text-faint">N/A</p>
                )}
              </Card>
            ))}
          </div>
        ) : (
          <p className="text-sm text-muted">Scores appear after the first audit completes.</p>
        )}
      </Section>

      <Section title="Score History">
        <Card>
          <ScoreHistoryChart points={detail.score_trends.overall ?? []} />
        </Card>
      </Section>

      <Section title="Service Score Trends">
        {trendCategories.length > 0 ? (
          <Card>
            <ul className="divide-y divide-line">
              {trendCategories.map((category) => (
                <li key={category} className="flex items-center justify-between gap-4 py-2">
                  <span className="text-sm font-medium text-muted">
                    {label(category)}
                  </span>
                  <Sparkline points={detail.score_trends[category]} />
                </li>
              ))}
            </ul>
          </Card>
        ) : (
          <p className="text-sm text-muted">Trends appear once this website has a completed audit.</p>
        )}
      </Section>

      <Section title="Issue Trends">
        <Card>
          <IssueTrendTable points={detail.issue_trends} />
        </Card>
      </Section>

      <Section title="Audit History">
        <Card>
          {detail.audit_history.length === 0 ? (
            <p className="text-sm text-muted">No audits yet.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead className="text-xs uppercase tracking-wide text-faint">
                  <tr>
                    <th className="py-2 pr-4">Date</th>
                    <th className="py-2 pr-4">Status</th>
                    <th className="py-2 pr-4">Score</th>
                    <th className="py-2 pr-4">Pages</th>
                    <th className="py-2 pr-4">Critical</th>
                    <th className="py-2 pr-4">High</th>
                    <th className="py-2" />
                  </tr>
                </thead>
                <tbody className="divide-y divide-line">
                  {detail.audit_history.map((audit) => (
                    <tr key={audit.id}>
                      {/* When it finished, falling back to when it was
                          queued — a failed or in-flight run has no
                          completion time but still belongs in the list. */}
                      <td className="py-2 pr-4 text-muted">
                        {formatDate(audit.completed_at ?? audit.created_at)}
                      </td>
                      <td className="py-2 pr-4">
                        <Badge>{audit.status}</Badge>
                      </td>
                      <td className="py-2 pr-4 font-medium text-ink">
                        {audit.overall_score ?? '—'}
                      </td>
                      <td className="py-2 pr-4 text-muted">{audit.pages_crawled}</td>
                      <td className="py-2 pr-4 text-red-600 dark:text-red-400">{audit.critical_issues}</td>
                      <td className="py-2 pr-4 text-amber-600 dark:text-amber-400">{audit.high_issues}</td>
                      <td className="py-2">
                        {audit.status === 'completed' && (
                          <Link
                            to={`/audits/${audit.id}/report`}
                            className="text-indigo-600 hover:underline dark:text-indigo-400"
                          >
                            Report
                          </Link>
                        )}
                        {audit.error_message && (
                          <span className="text-xs text-faint">{audit.error_message}</span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      </Section>

      <Section title="Pages">
        <Card>
          {detail.pages.length === 0 ? (
            <p className="text-sm text-muted">Pages appear after the first audit completes.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead className="text-xs uppercase tracking-wide text-faint">
                  <tr>
                    <th className="py-2 pr-4">Page</th>
                    <th className="py-2 pr-4">Status</th>
                    <th className="py-2 pr-4">Load</th>
                    <th className="py-2">Issues</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-line">
                  {detail.pages.map((page) => (
                    <tr key={page.url}>
                      <td className="max-w-xs truncate py-2 pr-4 text-muted">
                        {page.title || page.url}
                      </td>
                      <td className="py-2 pr-4 text-muted">{page.http_status ?? '—'}</td>
                      <td className="py-2 pr-4 text-muted">
                        {page.load_time_ms ? `${page.load_time_ms} ms` : '—'}
                      </td>
                      <td className="py-2 text-muted">{page.issue_count}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      </Section>

      <Section title="Recommendations">
        <Card>
          {detail.recommendations.length === 0 ? (
            <p className="text-sm text-muted">
              No recommendations yet — they appear once an audit finds something actionable.
            </p>
          ) : (
            <ol className="space-y-3">
              {detail.recommendations.map((rec, index) => (
                <li key={`${rec.code}-${index}`} className="border-l-2 border-indigo-400 pl-3">
                  <p className="flex items-center gap-2 text-xs uppercase tracking-wide text-faint">
                    <Badge>{rec.severity}</Badge>
                    {label(rec.category)}
                  </p>
                  <p className="text-sm font-medium text-ink">{rec.message}</p>
                  <p className="text-sm text-muted">{rec.recommendation}</p>
                </li>
              ))}
            </ol>
          )}
        </Card>
      </Section>

      <Section title="Competitors">
        <CompetitorPanel
          competitors={detail.competitors}
          ownScore={
            detail.service_scores.find((s) => s.category === 'aio')?.score ?? null
          }
        />
      </Section>

      <Section title="AI Search Questions">
        <AioPromptsSection websiteId={overview.id} />
      </Section>

      <Section title="Website Profile">
        <Card>
          <ProfileForm detail={detail} onSaved={onUpdated} />
        </Card>
      </Section>

      <Section title="Request Consultation">
        <Card>
          <p className="mb-3 text-sm text-muted">
            Want a specialist to walk through these findings with you?
          </p>
          <Link
            to={`/contact?website=${encodeURIComponent(overview.normalized_domain)}${
              detail.consultation.audit_id ? `&audit=${detail.consultation.audit_id}` : ''
            }`}
          >
            <Button type="button">Request a consultation</Button>
          </Link>
        </Card>
      </Section>
    </div>
  );
}
