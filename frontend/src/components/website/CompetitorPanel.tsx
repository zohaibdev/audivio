import type { WebsiteCompetitor } from '../../api/websites';
import { Badge } from '../ui/Badge';
import { Card } from '../ui/Card';

const FIELD_LABELS: Record<string, string> = {
  category: 'Business category',
  description: 'Business description',
  products_or_services: 'Products or services',
  country: 'Country',
  aio_goal: 'AI search goal',
  competitors: 'Competitors',
};

function scoreTone(score: number | null): string {
  if (score === null) return 'text-faint';
  if (score >= 90) return 'text-emerald-600 dark:text-emerald-400';
  if (score >= 70) return 'text-amber-600 dark:text-amber-400';
  return 'text-red-600 dark:text-red-400';
}

/**
 * Competitor comparison.
 *
 * An unanalyzed competitor shows a dash, never a zero. Rendering "0" for a
 * site we could not reach would be a false statement about a real company,
 * and the one place a reader would never think to question it.
 */
export function CompetitorPanel({
  competitors,
  ownScore,
}: {
  competitors: WebsiteCompetitor[];
  ownScore: number | null;
}) {
  if (competitors.length === 0) {
    return (
      <Card>
        <p className="text-sm text-muted">
          No competitors yet. Add them in your website profile below — or, on a plan that includes
          competitor discovery, we will suggest likely ones on the next audit.
        </p>
      </Card>
    );
  }

  return (
    <Card>
      <div className="overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead className="text-xs uppercase tracking-wide text-faint">
            <tr>
              <th className="py-2 pr-4">Competitor</th>
              <th className="py-2 pr-4">AIO score</th>
              <th className="py-2 pr-4">Pages</th>
              <th className="py-2">Topics they cover</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-line">
            {ownScore !== null && (
              <tr className="bg-indigo-50/50 dark:bg-indigo-950/30">
                <td className="py-2 pr-4 font-semibold text-ink">
                  Your site
                </td>
                <td className={`py-2 pr-4 font-semibold ${scoreTone(ownScore)}`}>{ownScore}</td>
                <td className="py-2 pr-4 text-muted">—</td>
                <td className="py-2 text-muted">—</td>
              </tr>
            )}
            {competitors.map((competitor) => (
              <tr key={competitor.id}>
                <td className="py-2 pr-4">
                  <span className="font-medium text-ink">{competitor.name}</span>
                  <span className="ml-2 align-middle">
                    <Badge tone={competitor.source === 'user' ? 'blue' : 'gray'}>
                      {competitor.source_label}
                    </Badge>
                  </span>
                  <p className="truncate text-xs text-faint">{competitor.domain}</p>
                  {competitor.reason && (
                    <p className="mt-1 text-xs text-muted">{competitor.reason}</p>
                  )}
                  {competitor.analysis_error && (
                    <p className="mt-1 text-xs text-amber-600 dark:text-amber-400">
                      Could not analyse: {competitor.analysis_error}
                    </p>
                  )}
                </td>
                <td className={`py-2 pr-4 font-semibold ${scoreTone(competitor.aio_score)}`}>
                  {/* A dash, not a zero — see the component note above. */}
                  {competitor.aio_score ?? '—'}
                </td>
                <td className="py-2 pr-4 text-muted">
                  {competitor.pages_analyzed ?? '—'}
                </td>
                <td className="py-2 text-muted">
                  {competitor.topics.length > 0 ? (
                    <span className="flex flex-wrap gap-1">
                      {competitor.topics.slice(0, 4).map((topic) => (
                        <Badge key={topic}>{topic}</Badge>
                      ))}
                    </span>
                  ) : (
                    <span className="text-faint">—</span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <p className="mt-4 text-xs text-faint">
        AIO scores measure how legible a site's content is to a machine reader. They do not predict
        ranking or citation in any AI search product.
      </p>
    </Card>
  );
}

/**
 * Prompts for the onboarding fields that are still empty, saying what each
 * one unlocks rather than just that it is missing.
 */
export function OnboardingPrompt({
  missingFields,
  unlocksContextPrompts,
}: {
  missingFields: string[];
  unlocksContextPrompts: boolean;
}) {
  if (missingFields.length === 0) return null;

  return (
    <Card className="border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/30">
      <h3 className="text-sm font-semibold text-amber-900 dark:text-amber-200">
        Complete your business profile
      </h3>
      <p className="mt-1 text-sm text-amber-800 dark:text-amber-300">
        {unlocksContextPrompts
          ? 'These fields sharpen the AI analysis of your site.'
          : 'Three of the ten AI search checks are being skipped until you set an AI search goal — they have nothing to measure your content against.'}
      </p>
      <ul className="mt-2 flex flex-wrap gap-2">
        {missingFields.map((field) => (
          <li key={field}>
            <Badge tone="yellow">{FIELD_LABELS[field] ?? field}</Badge>
          </li>
        ))}
      </ul>
    </Card>
  );
}
