import { apiClient, unwrap } from './client';

export interface ShareStatus {
  id: number;
  is_active: boolean;
  inactive_reason: string | null;
  allow_pdf: boolean;
  allow_screenshots: boolean;
  allow_recommendations: boolean;
  expires_at: string | null;
  revoked_at: string | null;
  last_viewed_at: string | null;
  view_count: number;
  created_at: string | null;
  /** The count an owner reacts to: a run of these means the link travelled. */
  failed_attempts: number;
}

export interface ShareEvent {
  event: string;
  label: string;
  is_recipient_action: boolean;
  ip_address: string | null;
  created_at: string | null;
}

export interface ShareCreated {
  /** Returned once, at creation. Nothing can reconstruct it later. */
  url: string;
  share: ShareStatus;
}

export function fetchShares(auditId: number) {
  return unwrap<{ is_available_on_plan: boolean; shares: ShareStatus[] }>(
    apiClient.get(`/audits/${auditId}/shares`),
  );
}

export function createShare(
  auditId: number,
  payload: {
    password: string;
    allow_pdf?: boolean;
    allow_screenshots?: boolean;
    allow_recommendations?: boolean;
    expires_at?: string | null;
  },
) {
  return unwrap<ShareCreated>(apiClient.post(`/audits/${auditId}/shares`, payload));
}

export function updateShare(
  auditId: number,
  shareId: number,
  payload: Partial<{
    password: string;
    allow_pdf: boolean;
    allow_screenshots: boolean;
    allow_recommendations: boolean;
  }>,
) {
  return unwrap<{ share: ShareStatus }>(apiClient.put(`/audits/${auditId}/shares/${shareId}`, payload));
}

export function revokeShare(auditId: number, shareId: number) {
  return unwrap<{ share: ShareStatus }>(apiClient.delete(`/audits/${auditId}/shares/${shareId}`));
}

export function regenerateShare(auditId: number, shareId: number) {
  return unwrap<ShareCreated>(apiClient.post(`/audits/${auditId}/shares/${shareId}/regenerate`));
}

export function fetchShareEvents(auditId: number, shareId: number) {
  return unwrap<{ events: ShareEvent[] }>(apiClient.get(`/audits/${auditId}/shares/${shareId}/events`));
}
