import { useState } from 'react';
import { Card } from '../ui/Card';
import type { AccessibilityDetail } from '../../types';

/**
 * The accessibility service's own view (§43): what was scanned, which of
 * the sixteen check areas turned up problems, and which WCAG success
 * criteria the findings map to.
 *
 * The disclaimer is rendered by {@link AccessibilityDisclaimerNotice},
 * which is deliberately a separate export: it has to appear next to the
 * score even when this detail block does not, and it is never gated on
 * plan access.
 */
export function AccessibilityReportView({ detail }: { detail: AccessibilityDetail }) {
  const [showPassed, setShowPassed] = useState(false);

  const failing = detail.checks.filter((check) => !check.passed);
  const passing = detail.checks.filter((check) => check.passed);

  return (
    <div className="flex flex-col gap-6">
      <Card>
        <h3 className="font-semibold text-ink">What was checked</h3>
        <p className="mt-1 text-sm text-muted">
          {detail.coverage.pages_scanned} of {detail.coverage.pages_crawled} crawled{' '}
          {detail.coverage.pages_crawled === 1 ? 'page was' : 'pages were'} scanned with automated
          accessibility checks.
        </p>

        {detail.coverage.is_partial && (
          <div className="mt-3 rounded-md border border-amber-300 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950/40">
            <p className="text-sm text-amber-900 dark:text-amber-200">
              Some crawled pages produced no accessibility result, so this covers part of the site
              rather than all of it.
            </p>
          </div>
        )}

        <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
          <Stat label="Passed checks" value={detail.summary.passed_checks} />
          <Stat label="Affected pages" value={detail.summary.affected_pages} />
          <Stat label="Affected elements" value={detail.summary.affected_elements} />
          <Stat label="Need manual review" value={detail.coverage.needs_review_count} />
        </div>
      </Card>

      <Card>
        <h3 className="font-semibold text-ink">Check areas</h3>

        {failing.length === 0 ? (
          <p className="mt-2 text-sm text-muted">
            No automated check found a problem in any area.
          </p>
        ) : (
          <ul className="mt-3 space-y-3">
            {failing.map((check) => (
              <li
                key={check.check}
                className="rounded-md border border-line p-3 dark:border-line"
              >
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                  <p className="text-sm font-medium text-ink">{check.label}</p>
                  <p className="text-xs text-muted">
                    {check.issue_count} issue{check.issue_count === 1 ? '' : 's'} ·{' '}
                    {check.element_count} element{check.element_count === 1 ? '' : 's'}
                  </p>
                </div>
                {check.why_it_matters && (
                  <p className="mt-1 text-sm text-muted">
                    {check.why_it_matters}
                  </p>
                )}
              </li>
            ))}
          </ul>
        )}

        {passing.length > 0 && (
          <div className="mt-4">
            <button
              type="button"
              onClick={() => setShowPassed((open) => !open)}
              className="text-sm font-medium text-blue-600 hover:underline dark:text-blue-400"
            >
              {showPassed ? 'Hide' : 'Show'} {passing.length} area
              {passing.length === 1 ? '' : 's'} with no automated issues
            </button>

            {showPassed && (
              <ul className="mt-3 flex flex-wrap gap-2">
                {passing.map((check) => (
                  <li
                    key={check.check}
                    className="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300"
                  >
                    {check.label}
                  </li>
                ))}
              </ul>
            )}
            {/* Phrased as "no automated issues", never "accessible" — the
                scan cannot support the stronger claim. */}
          </div>
        )}
      </Card>

      {detail.wcag_criteria.length > 0 && (
        <Card>
          <h3 className="font-semibold text-ink">
            WCAG success criteria with failures
          </h3>
          <p className="mt-1 text-sm text-muted">
            Criteria the automated findings above map to. Findings that map to no criterion are
            listed as best practices instead.
          </p>
          <ul className="mt-3 flex flex-wrap gap-2">
            {detail.wcag_criteria.map((criterion) => (
              <li
                key={criterion.criterion}
                className="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-700 dark:bg-slate-800 dark:text-slate-300"
              >
                {criterion.criterion}
                {criterion.level ? ` (Level ${criterion.level})` : ''}
              </li>
            ))}
          </ul>
        </Card>
      )}
    </div>
  );
}

/**
 * Shown wherever an accessibility score is shown, at every plan tier.
 *
 * A number labelled "accessibility" is the thing most likely to be
 * screenshotted as proof of ADA compliance, so the statement that it is
 * not that sits next to it rather than in a footer.
 */
export function AccessibilityDisclaimerNotice({ detail }: { detail: AccessibilityDetail }) {
  const [expanded, setExpanded] = useState(false);

  return (
    <div className="mt-4 rounded-md border border-slate-300 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800/50">
      <p className="text-xs font-semibold uppercase tracking-wide text-muted">
        {detail.disclaimer.title}
      </p>
      <p className="mt-1 text-sm text-ink">{detail.disclaimer.summary}</p>

      {expanded ? (
        <p className="mt-2 text-sm text-muted">{detail.disclaimer.detail}</p>
      ) : (
        <button
          type="button"
          onClick={() => setExpanded(true)}
          className="mt-1 text-sm font-medium text-blue-600 hover:underline dark:text-blue-400"
        >
          What this assessment can and cannot tell you
        </button>
      )}
    </div>
  );
}

function Stat({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-md bg-slate-50 p-3 dark:bg-slate-800/50">
      <p className="text-2xl font-bold text-ink">{value}</p>
      <p className="text-xs text-muted">{label}</p>
    </div>
  );
}
