import { Badge } from '../ui/Badge';
import { EmptyState } from '../ui/EmptyState';
import type { ReportRecommendation } from '../../types';

const priorityTone = { high: 'red', medium: 'yellow', low: 'blue' } as const;

export function RecommendationList({ recommendations }: { recommendations: ReportRecommendation[] }) {
  if (recommendations.length === 0) {
    return <EmptyState title="No recommendations yet" />;
  }

  return (
    <ol className="space-y-3">
      {recommendations.map((rec, index) => (
        <li key={index} className="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
          <div className="flex items-center gap-2">
            {rec.priority && (
              <Badge tone={priorityTone[rec.priority as keyof typeof priorityTone] ?? 'gray'}>{`${rec.priority} effort impact`}</Badge>
            )}
            <span className="text-xs uppercase tracking-wide text-slate-400">{rec.category.replace('_', ' ')}</span>
          </div>
          <p className="mt-2 text-sm text-slate-700 dark:text-slate-200">{rec.recommendation}</p>
        </li>
      ))}
    </ol>
  );
}
