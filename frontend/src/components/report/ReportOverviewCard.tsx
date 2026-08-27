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
    <div className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
      <div className="flex flex-col items-center gap-4 border-b border-slate-100 pb-6 dark:border-slate-800 sm:flex-row sm:justify-between sm:text-left">
        <div>
          <p className="text-sm text-slate-500 dark:text-slate-400">{overview.website_url}</p>
          <p className="text-xs text-slate-400 dark:text-slate-500">
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
