import { apiClient, unwrap } from './client';

/**
 * Audit-to-audit comparison (§52).
 *
 * Every figure here travels with whether it is comparable and why not,
 * because the backend refuses to subtract two numbers that do not mean
 * the same thing. The UI's job is to keep those caveats attached — a
 * delta rendered without its reason is the lie the endpoint went out of
 * its way to avoid.
 */

export interface ComparisonSide {
  audit_id: number;
  date: string | null;
  pages_analyzed: number;
  critical_issues: number;
  high_issues: number;
  total_issues: number;
}

export interface ComparisonCoverage {
  previous_pages: number;
  current_pages: number;
  shared_pages: number;
  pages_not_recrawled: number;
  pages_added: number;
  /** False when the two runs did not reach the same set of pages. */
  is_like_for_like: boolean;
  note: string | null;
}

export interface ComparisonScore {
  category: string;
  label: string;
  /** Null when the service did not apply, which is never a zero. */
  previous_score: number | null;
  current_score: number | null;
  previous_applicable: boolean | null;
  current_applicable: boolean | null;
  delta: number | null;
  direction: 'improved' | 'declined' | 'unchanged';
  is_comparable: boolean;
  /** Present exactly when `is_comparable` is false. */
  reason: string | null;
}

export interface ComparisonIssue {
  code: string;
  severity: string;
  category: string;
  message: string;
  /** Only on `not_rechecked` entries. */
  reason?: string;
}

export interface ComparisonIssues {
  new: ComparisonIssue[];
  resolved: ComparisonIssue[];
  /** Gone from the report only because nobody looked again. */
  not_rechecked: ComparisonIssue[];
  persisting: number;
  severity_deltas: Record<string, { previous: number; current: number; delta: number }>;
}

export interface ComparisonInsight {
  type: 'improved' | 'declined' | 'caveat';
  category: string | null;
  text: string;
  magnitude: number;
}

export interface AuditComparison {
  previous: ComparisonSide;
  current: ComparisonSide;
  coverage: ComparisonCoverage;
  scores: ComparisonScore[];
  issues: ComparisonIssues;
  insights: ComparisonInsight[];
}

export interface ComparisonResponse {
  is_available: boolean;
  reason: string | null;
  comparison: AuditComparison | null;
}

/**
 * Owner-only: a comparison names which pages were and were not crawled.
 *
 * `previous` is optional and defaults to the previous *completed* run of
 * the same website. Naming an audit from another website, or one that did
 * not complete, is a 404 rather than a silent substitution.
 */
export function fetchComparison(auditId: number, previousAuditId?: number) {
  return unwrap<ComparisonResponse>(
    apiClient.get(`/audits/${auditId}/comparison`, {
      params: previousAuditId ? { previous: previousAuditId } : undefined,
    }),
  );
}
