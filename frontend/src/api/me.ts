import { apiClient, unwrap } from './client';
import type { MyAiModel, MyEntitlements, MyFeatureAccess } from '../types';

// Optional-auth endpoints (backend: MeController) — work for both an
// anonymous visitor (resolved to the default plan) and a logged-in user
// (resolved to their subscribed plan), same pattern as audit submission.

export function fetchMyFeatures() {
  return unwrap<MyFeatureAccess[]>(apiClient.get('/me/features'));
}

export function fetchMyAiModels() {
  return unwrap<MyAiModel[]>(apiClient.get('/me/ai-models'));
}

/**
 * The caller's plan limits and on/off entitlements.
 *
 * Descriptive only. Every limit here is enforced again server-side at the
 * point it applies, so hiding a control based on this is a courtesy to the
 * user, never a security boundary.
 */
export function fetchMyEntitlements() {
  return unwrap<MyEntitlements>(apiClient.get('/me/entitlements'));
}
