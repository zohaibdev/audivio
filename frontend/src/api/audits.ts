import { AxiosError } from 'axios';
import { apiClient, ApiRequestError, unwrap } from './client';
import type { AuditSummary, ExplorerPage, Report, ServiceReport, ServiceReportsResponse } from '../types';

export function createAudit(url: string) {
  return unwrap<{ audit_id: number; status: string }>(apiClient.post('/audits', { url }));
}

export function fetchAuditStatus(auditId: number) {
  return unwrap<AuditSummary>(apiClient.get(`/audits/${auditId}`));
}

export function fetchReport(auditId: number) {
  return unwrap<Report>(apiClient.get(`/audits/${auditId}/report`));
}

/** Every service report for an audit, plus the shared overview (§35). */
export function fetchServiceReports(auditId: number) {
  return unwrap<ServiceReportsResponse>(apiClient.get(`/audits/${auditId}/report/services`));
}

export function fetchServiceReport(auditId: number, service: string) {
  return unwrap<ServiceReport>(apiClient.get(`/audits/${auditId}/report/services/${service}`));
}

/** Page explorer — full access only; anonymous callers get a 403. */
export function fetchAuditPages(auditId: number) {
  return unwrap<ExplorerPage[]>(apiClient.get(`/audits/${auditId}/pages`));
}

/**
 * PDF export needs the Authorization bearer header, so a plain <a href>
 * link can't carry it — fetch as a blob (with axios, so the bearer
 * interceptor applies) and hand the browser a save prompt directly.
 */
export async function downloadReportPdf(auditId: number, service?: string): Promise<void> {
  const path = service
    ? `/audits/${auditId}/report/services/${service}/pdf`
    : `/audits/${auditId}/report/pdf`;
  const filename = service ? `${service}-report.pdf` : `audit-report-${auditId}.pdf`;

  try {
    const response = await apiClient.get(path, { responseType: 'blob' });
    const url = window.URL.createObjectURL(new Blob([response.data], { type: 'application/pdf' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
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
