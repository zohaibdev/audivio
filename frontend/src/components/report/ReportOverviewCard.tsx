import { ScoreGauge } from '../ui/ScoreGauge';
import type { ReportOverview } from '../../types';

const categoryLabels: Record<string, string> = {
  performance: 'Performance',
  accessibility: 'Accessibility',
  seo: 'SEO',
  ecommerce: 'Ecommerce',
  landing_page: 'Landing Page',
  conversion: 'Conversion',
  aio: 'AI Search Readiness',
};

export function ReportOverviewCard({ overview }: { overview: ReportOverview }) {
  return (
    <div className="rounded-xl border border-line bg-white p-6 dark:border-line dark:bg-slate-900">
      <div className="flex flex-col items-center gap-4 border-b border-slate-100 pb-6 dark:border-line sm:flex-row sm:justify-between sm:text-left">
        <div>
          <p className="text-sm text-muted">{overview.website_url}</p>

          {/* Stated up front: the site type decides which services were
              reported at all, so leaving it implicit in a set of missing
              scores would leave a reader guessing why. */}
          {overview.site_type_label && (
            <p className="mt-1 text-sm text-muted">
              <span className="font-medium">{overview.site_type_label}</span>
              {overview.site_type_description ? ` — ${overview.site_type_description}` : ''}
              {typeof overview.site_type_confidence === 'number' && (
                <span className="text-faint"> ({overview.site_type_confidence}% confidence)</span>
              )}
            </p>
          )}
          <p className="text-xs text-faint">
            {overview.pages_crawled} page(s) crawled
            {overview.audit_date && ` · ${new Date(overview.audit_date).toLocaleDateString()}`}
          </p>
        </div>
        <ScoreGauge score={overview.overall_score} label="Overall Health Score" size="lg" />
      </div>
      <div className="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
        {Object.entries(overview.category_scores).map(([category, score]) => (
          <ScoreGauge key={category} score={score} label={categoryLabels[category] ?? category} size="sm" />
        ))}
      </div>
    </div>
  );
}
