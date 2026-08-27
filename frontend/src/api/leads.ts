import { apiClient, unwrap } from './client';

export interface CreateLeadPayload {
  name: string;
  email: string;
  phone?: string;
  website_url?: string;
  message?: string;
  audit_id?: number;
  source: 'talk_to_consultant' | 'request_audit_review';
}

export function submitLead(payload: CreateLeadPayload) {
  return unwrap<{ lead_id: number }>(apiClient.post('/leads', payload));
}
