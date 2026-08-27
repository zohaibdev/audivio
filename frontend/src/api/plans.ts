import { apiClient, unwrap } from './client';
import type { Plan } from '../types';

export function fetchPublicPlans() {
  return unwrap<Plan[]>(apiClient.get('/plans'));
}
