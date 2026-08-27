import { apiClient, unwrap } from './client';
import type { AuditSchedule, ScheduleFrequency } from '../types';

export function fetchSchedules() {
  return unwrap<AuditSchedule[]>(apiClient.get('/audit-schedules'));
}

export function createSchedule(payload: { website_id: number; frequency: ScheduleFrequency }) {
  return unwrap<AuditSchedule>(apiClient.post('/audit-schedules', payload));
}

export function updateSchedule(id: number, payload: Partial<{ frequency: ScheduleFrequency; is_active: boolean }>) {
  return unwrap<AuditSchedule>(apiClient.put(`/audit-schedules/${id}`, payload));
}

export function deleteSchedule(id: number) {
  return unwrap<null>(apiClient.delete(`/audit-schedules/${id}`));
}
