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
        <li key={index} className="rounded-lg border border-line bg-white p-4 dark:border-line dark:bg-slate-900">
          <div className="flex items-center gap-2">
            {rec.priority && (
              <Badge tone={priorityTone[rec.priority as keyof typeof priorityTone] ?? 'gray'}>{`${rec.priority} effort impact`}</Badge>
            )}
            <span className="text-xs uppercase tracking-wide text-faint">{rec.category.replace('_', ' ')}</span>
          </div>
          <p className="mt-2 text-sm text-ink">{rec.recommendation}</p>
        </li>
      ))}
    </ol>
  );
}
