import { useEffect, useState } from 'react';
import { fetchComparison, type AuditComparison, type ComparisonIssue } from '../../api/comparison';
import { apiErrorMessage } from '../../api/client';
import { Card } from '../ui/Card';

/**
 * What changed since the previous audit (§52).
 *
 * The backend refuses to subtract two numbers that do not mean the same
 * thing, and this view's whole job is to keep that refusal visible: a
 * service that stopped applying shows its reason where its delta would
 * be, findings on pages nobody re-crawled get their own list instead of
 * being counted as fixed, and a coverage caveat sits above the insights
 * rather than under them.
 */
export function AuditComparisonView({ auditId }: { auditId: number }) {
  const [comparison, setComparison] = useState<AuditComparison | null>(null);
  const [unavailable, setUnavailable] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;

    fetchComparison(auditId)
      .then((response) => {
        if (!active) return;
        setComparison(response.comparison);
        setUnavailable(response.comparison ? null : response.reason);
      })
      .catch((err) => active && setError(apiErrorMessage(err)));

    return () => {
      active = false;
    };
  }, [auditId]);

  // A first audit has nothing to compare against, and a comparison the
  // viewer is not entitled to read is not an error worth shouting about.
  if (error) return null;

  if (!comparison) {
    return unavailable ? (
      <Card>
        <h3 className="font-semibold text-ink">Compared with your last audit</h3>
        <p className="mt-2 text-sm text-muted">{unavailable}</p>
      </Card>
    ) : null;
  }

  const { coverage, insights, issues, scores } = comparison;

  return (
    <Card>
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h3 className="font-semibold text-ink">Compared with your last audit</h3>
        <p className="text-xs text-muted">
          {formatDate(comparison.previous.date)} → {formatDate(comparison.current.date)}
        </p>
      </div>

      {insights.length === 0 ? (
        <p className="mt-3 text-sm text-muted">
          No service score changed between these two audits.
        </p>
      ) : (
        <ul className="mt-4 flex flex-col gap-2">
          {insights.map((insight, index) => (
            <li
              key={`${insight.category ?? 'caveat'}-${index}`}
              className={`rounded-md px-3 py-2 text-sm ${insightClass(insight.type)}`}
            >
              {insight.text}
            </li>
          ))}
        </ul>
      )}

      <div className="mt-5 grid gap-3 sm:grid-cols-4">
        <Stat label="Pages re-crawled" value={coverage.shared_pages} />
        <Stat label="Pages not re-crawled" value={coverage.pages_not_recrawled} />
        <Stat label="New pages" value={coverage.pages_added} />
        <Stat label="Issues still open" value={issues.persisting} />
      </div>

      <ScoreTable scores={scores} />

      <div className="mt-6 grid gap-4 lg:grid-cols-3">
        <IssueColumn
          title="Resolved"
          tone="positive"
          issues={issues.resolved}
          empty="Nothing from the previous audit was fixed."
        />
        <IssueColumn
          title="New"
          tone="negative"
          issues={issues.new}
          empty="No new findings."
        />
        {/* Its own column, never folded into "Resolved". These findings
            disappeared from the report because the page they were on was
            not crawled again — nobody looked. */}
        <IssueColumn
          title="Not re-checked"
          tone="neutral"
          issues={issues.not_rechecked}
          empty="Every previous finding was looked for again."
        />
      </div>

      <SeverityDeltas deltas={issues.severity_deltas} />
    </Card>
  );
}

function ScoreTable({ scores }: { scores: AuditComparison['scores'] }) {
  if (scores.length === 0) return null;

  return (
    <div className="mt-5 overflow-x-auto">
      <table className="w-full text-left text-sm">
        <thead className="text-xs uppercase tracking-wide text-faint">
          <tr>
            <th className="py-2 pr-4">Service</th>
            <th className="py-2 pr-4">Previous</th>
            <th className="py-2 pr-4">Current</th>
            <th className="py-2">Change</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-line">
          {scores.map((score) => (
            <tr key={score.category}>
              <td className="py-2 pr-4 font-medium text-ink">{score.label}</td>
              <td className="py-2 pr-4 text-muted">{score.previous_score ?? 'N/A'}</td>
              <td className="py-2 pr-4 text-muted">{score.current_score ?? 'N/A'}</td>
              <td className="py-2">
                {score.is_comparable && score.delta !== null ? (
                  <span className={deltaClass(score.direction)}>
                    {score.delta > 0 ? '+' : ''}
                    {score.delta}
                  </span>
                ) : (
                  // The reason stands where the number would be. A dash
                  // alone would read as "no change" — the one thing this
                  // row does not mean.
                  <span className="text-xs text-muted">{score.reason}</span>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function IssueColumn({
  title,
  issues,
  empty,
  tone,
}: {
  title: string;
  issues: ComparisonIssue[];
  empty: string;
  tone: 'positive' | 'negative' | 'neutral';
}) {
  return (
    <div className="rounded-lg border border-line p-4 dark:border-line">
      <div className="flex items-baseline justify-between gap-2">
        <h4 className="text-sm font-semibold text-ink">{title}</h4>
        <span className={`text-lg font-bold ${countClass(tone)}`}>{issues.length}</span>
      </div>

      {issues.length === 0 ? (
        <p className="mt-2 text-xs text-faint">{empty}</p>
      ) : (
        <ul className="mt-3 flex flex-col gap-2">
          {issues.slice(0, 8).map((issue) => (
            <li key={issue.code} className="text-xs">
              <span className={severityClass(issue.severity)}>{issue.severity}</span>{' '}
              <span className="text-muted">{issue.message}</span>
              {issue.reason && <p className="mt-0.5 text-faint">{issue.reason}</p>}
            </li>
          ))}
          {issues.length > 8 && (
            <li className="text-xs text-faint">+{issues.length - 8} more</li>
          )}
        </ul>
      )}
    </div>
  );
}

function SeverityDeltas({ deltas }: { deltas: AuditComparison['issues']['severity_deltas'] }) {
  const rows = Object.entries(deltas).filter(([, value]) => value.previous > 0 || value.current > 0);

  if (rows.length === 0) return null;

  return (
    <div className="mt-6">
      <h4 className="text-sm font-semibold text-ink">Issue counts by severity</h4>
      <div className="mt-3 grid gap-3 sm:grid-cols-4">
        {rows.map(([severity, value]) => (
          <div key={severity} className="rounded-md bg-slate-50 p-3 dark:bg-slate-800/50">
            <p className="text-xs uppercase tracking-wide text-faint">{severity}</p>
            <p className="mt-1 text-lg font-bold text-ink">
              {value.previous} → {value.current}
            </p>
            {/* Fewer issues is the good direction here, so the colours are
                inverted relative to a score delta. */}
            <p className={value.delta <= 0 ? 'text-xs text-emerald-600' : 'text-xs text-red-600'}>
              {value.delta > 0 ? '+' : ''}
              {value.delta}
            </p>
          </div>
        ))}
      </div>
    </div>
  );
}

function Stat({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-md bg-slate-50 p-3 dark:bg-slate-800/50">
      <p className="text-xl font-bold text-ink">{value}</p>
      <p className="text-xs text-muted">{label}</p>
    </div>
  );
}

function insightClass(type: 'improved' | 'declined' | 'caveat'): string {
  if (type === 'improved') return 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300';
  if (type === 'declined') return 'bg-red-50 text-red-800 dark:bg-red-950/40 dark:text-red-300';
  return 'bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300';
}

function deltaClass(direction: 'improved' | 'declined' | 'unchanged'): string {
  if (direction === 'improved') return 'font-semibold text-emerald-600 dark:text-emerald-400';
  if (direction === 'declined') return 'font-semibold text-red-600 dark:text-red-400';
  return 'text-faint';
}

function countClass(tone: 'positive' | 'negative' | 'neutral'): string {
  if (tone === 'positive') return 'text-emerald-600 dark:text-emerald-400';
  if (tone === 'negative') return 'text-red-600 dark:text-red-400';
  return 'text-muted';
}

function severityClass(severity: string): string {
  if (severity === 'critical') return 'font-semibold uppercase text-red-600 dark:text-red-400';
  if (severity === 'high') return 'font-semibold uppercase text-amber-600 dark:text-amber-400';
  return 'font-semibold uppercase text-faint';
}

function formatDate(iso: string | null): string {
  if (!iso) return '—';
  return new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}
