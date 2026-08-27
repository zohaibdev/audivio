import { apiClient, unwrap } from './client';
import type { MyAiModel, MyFeatureAccess } from '../types';

// Optional-auth endpoints (backend: MeController) — work for both an
// anonymous visitor (resolved to the default plan) and a logged-in user
// (resolved to their subscribed plan), same pattern as audit submission.

export function fetchMyFeatures() {
  return unwrap<MyFeatureAccess[]>(apiClient.get('/me/features'));
}

export function fetchMyAiModels() {
  return unwrap<MyAiModel[]>(apiClient.get('/me/ai-models'));
}
