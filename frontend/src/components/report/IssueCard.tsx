import { useState } from 'react';
import { Badge, SeverityBadge } from '../ui/Badge';
import type { AccessibilityIssueElement, ServiceIssue } from '../../types';

/**
 * One finding, written for a non-technical reader first: what's wrong,
 * why it matters, which pages, then — only on request — the raw evidence.
 * Progressive disclosure is the point; the technical detail is present but
 * never the first thing in the reader's way.
 */
export function IssueCard({ issue }: { issue: ServiceIssue }) {
  const [showTechnical, setShowTechnical] = useState(false);

  const evidence = (issue.evidence ?? {}) as Record<string, unknown>;

  // Accessibility findings (§43) carry structured fields that deserve real
  // rendering rather than a stringified blob in the technical dump.
  // §47 supplies this for every finding; the accessibility modules also
  // put it in meta, and that one is more specific so it wins.
  const whyItMatters =
    (typeof evidence.why_it_matters === 'string' ? evidence.why_it_matters : null) ??
    issue.why_it_matters ??
    null;
  const wcagLabel = typeof evidence.wcag_label === 'string' ? evidence.wcag_label : null;
  const isBestPractice = evidence.is_best_practice === true;
  const elements = Array.isArray(evidence.elements)
    ? (evidence.elements as AccessibilityIssueElement[])
    : [];

  const evidenceEntries = Object.entries(evidence).filter(
    // `affected_pages`/`affected_page_count` are already rendered above as
    // a first-class list; repeating them as raw evidence is just noise.
    // The accessibility fields below it are rendered in their own right.
    ([key]) =>
      ![
        'affected_pages',
        'affected_page_count',
        'instances',
        'why_it_matters',
        'wcag',
        'wcag_label',
        'is_best_practice',
        'check_label',
        'elements',
      ].includes(key),
  );

  const pages = issue.affected_pages ?? [];

  return (
    <div className="rounded-lg border border-line p-4 dark:border-line">
      <div className="flex flex-wrap items-center gap-2">
        <SeverityBadge severity={issue.severity} />
        <Badge tone="gray">{issue.category}</Badge>
        {issue.detected_by === 'ai' && <Badge tone="purple">AI-assisted</Badge>}
        {issue.is_site_wide && <Badge tone="blue">Site-wide</Badge>}
        {wcagLabel && <Badge tone="blue">{wcagLabel}</Badge>}
        {/* A rule that maps to no success criterion is labelled as what it
            is, rather than borrowing a citation it does not have. */}
        {!wcagLabel && isBestPractice && <Badge tone="gray">Best practice</Badge>}
      </div>

      <h4 className="mt-2 font-semibold text-ink">{issue.title}</h4>
      <p className="mt-1 text-sm text-muted">{issue.message}</p>

      {/* The six parts of a finding, in the order a reader needs them
          (§47): what is wrong, why it matters, which pages, the evidence,
          how to fix it, and only then the technical detail. */}
      {whyItMatters && (
        <div className="mt-3 rounded-md bg-slate-50 p-3 dark:bg-slate-800/50">
          <p className="text-xs font-semibold uppercase tracking-wide text-muted">
            Why this matters
          </p>
          <p className="mt-1 text-sm text-ink">{whyItMatters}</p>
        </div>
      )}

      {issue.recommendation && (
        <div className="mt-3 rounded-md bg-indigo-50 p-3 dark:bg-indigo-950/40">
          <p className="text-xs font-semibold uppercase tracking-wide text-indigo-700 dark:text-indigo-300">How to fix it</p>
          <p className="mt-1 text-sm text-ink">{issue.recommendation}</p>
        </div>
      )}

      <p className="mt-3 text-xs text-muted">
        {issue.affected_page_count === 0
          ? 'Affects the site as a whole'
          : `Affects ${issue.affected_page_count} page${issue.affected_page_count === 1 ? '' : 's'}`}
      </p>

      {pages.length > 0 && (
        <ul className="mt-2 space-y-1">
          {pages.slice(0, 5).map((url) => (
            <li key={url} className="truncate font-mono text-xs text-muted">
              {url}
            </li>
          ))}
          {pages.length < issue.affected_page_count && (
            <li className="text-xs italic text-faint">
              …and {issue.affected_page_count - pages.length} more
            </li>
          )}
        </ul>
      )}

      {(evidenceEntries.length > 0 || elements.length > 0) && (
        <div className="mt-3">
          <button
            type="button"
            onClick={() => setShowTechnical((open) => !open)}
            className="text-xs font-medium text-muted hover:text-slate-800 dark:text-faint dark:hover:text-slate-200"
          >
            {showTechnical ? '▾ Hide technical details' : '▸ Technical details'}
          </button>
          {showTechnical && (
            <dl className="mt-2 space-y-1 rounded-md bg-slate-50 p-3 dark:bg-slate-800/50">
              <div className="flex gap-2 text-xs">
                <dt className="font-medium text-muted">Rule</dt>
                <dd className="font-mono text-ink">{issue.code}</dd>
              </div>
              {elements.length > 0 && (
                <div className="text-xs">
                  <dt className="font-medium text-muted">
                    Failing elements
                  </dt>
                  <dd className="mt-1 space-y-2">
                    {elements.map((element, index) => (
                      <div key={`${element.page_url}-${element.selector}-${index}`}>
                        <p className="break-all font-mono text-ink">
                          {element.selector}
                        </p>
                        {element.html && (
                          <p className="break-all font-mono text-muted">
                            {element.html}
                          </p>
                        )}
                        {element.evidence && (
                          <p className="text-muted">{element.evidence}</p>
                        )}
                        <p className="truncate text-faint">{element.page_url}</p>
                      </div>
                    ))}
                  </dd>
                </div>
              )}
              {evidenceEntries.map(([key, value]) => (
                <div key={key} className="flex gap-2 text-xs">
                  <dt className="font-medium text-muted">{key}</dt>
                  <dd className="break-all font-mono text-ink">
                    {typeof value === 'object' ? JSON.stringify(value) : String(value)}
                  </dd>
                </div>
              ))}
            </dl>
          )}
        </div>
      )}
    </div>
  );
}
