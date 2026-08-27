import { AxiosError } from 'axios';
import { apiClient, ApiRequestError, unwrap } from './client';
import type { AuditSummary, Report } from '../types';

export function createAudit(url: string) {
  return unwrap<{ audit_id: number; status: string }>(apiClient.post('/audits', { url }));
}

export function fetchAuditStatus(auditId: number) {
  return unwrap<AuditSummary>(apiClient.get(`/audits/${auditId}`));
}

export function fetchReport(auditId: number) {
  return unwrap<Report>(apiClient.get(`/audits/${auditId}/report`));
}

/**
 * PDF export needs the Authorization bearer header, so a plain <a href>
 * link can't carry it — fetch as a blob (with axios, so the bearer
 * interceptor applies) and hand the browser a save prompt directly.
 */
export async function downloadReportPdf(auditId: number): Promise<void> {
  try {
    const response = await apiClient.get(`/audits/${auditId}/report/pdf`, { responseType: 'blob' });
    const url = window.URL.createObjectURL(new Blob([response.data], { type: 'application/pdf' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `audit-report-${auditId}.pdf`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.URL.revokeObjectURL(url);
  } catch (error) {
    if (error instanceof AxiosError && error.response?.data instanceof Blob) {
      const text = await error.response.data.text();
      const body = JSON.parse(text) as { message?: string };
      throw new ApiRequestError(body.message ?? 'Could not download the PDF.', error.response.status);
    }
    throw error;
  }
}
