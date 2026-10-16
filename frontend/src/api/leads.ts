import { apiClient, unwrap } from './client';

/**
 * Where in the report the visitor asked from (§55). Recorded so it is
 * possible to see which placement earns requests rather than guessing.
 */
export type LeadSource =
  | 'talk_to_consultant'
  | 'request_audit_review'
  | 'audit_header'
  | 'score_summary'
  | 'critical_issues'
  | 'report_footer';

export interface CreateLeadPayload {
  name: string;
  email: string;
  phone?: string;
  website_url?: string;
  message?: string;
  audit_id?: number;
  /**
   * The findings the visitor was looking at. Sent as a claim and filtered
   * server-side against the audit — codes that are not really on it are
   * dropped rather than stored.
   */
  issue_codes?: string[];
  requested_services?: string[];
  source: LeadSource;
}

export function submitLead(payload: CreateLeadPayload) {
  return unwrap<{ lead_id: number }>(apiClient.post('/leads', payload));
}
