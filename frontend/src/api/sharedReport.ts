import axios from 'axios';

/**
 * The recipient's side of a shared report (§50).
 *
 * A separate axios instance on purpose: the shared client attaches the
 * signed-in user's bearer token to every request, and a share recipient
 * has no account. Reusing it would send whatever token happens to be in
 * this browser to a public endpoint — harmless today, but exactly the
 * kind of accidental credential leak that is hard to notice later.
 */
const sharedClient = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api',
  headers: { Accept: 'application/json' },
});

export interface SharedReportBranding {
  is_white_label: boolean;
  brand_name: string;
  logo_data_uri: string | null;
}

export interface SharePermissions {
  allow_pdf: boolean;
  allow_screenshots: boolean;
  allow_recommendations: boolean;
}

/** Where the unlocked session lives for this tab, and only this tab. */
const ACCESS_KEY = 'audivio.share_access';

export function storedAccessToken(token: string): string | null {
  try {
    const raw = sessionStorage.getItem(`${ACCESS_KEY}:${token}`);
    return raw && raw.length > 0 ? raw : null;
  } catch {
    // Private windows can refuse storage outright; the recipient just
    // re-enters the password.
    return null;
  }
}

function storeAccessToken(token: string, access: string): void {
  try {
    // sessionStorage, not localStorage: an unlocked report should not
    // survive the tab being closed on a shared machine.
    sessionStorage.setItem(`${ACCESS_KEY}:${token}`, access);
  } catch {
    // Ignored — the session simply lasts until the page is reloaded.
  }
}

export function forgetAccessToken(token: string): void {
  try {
    sessionStorage.removeItem(`${ACCESS_KEY}:${token}`);
  } catch {
    // Ignored.
  }
}

export async function fetchShareLock(token: string) {
  const { data } = await sharedClient.get(`/shared-reports/${token}`);
  return data.data as { requires_password: boolean; branding: SharedReportBranding };
}

export async function unlockShare(token: string, password: string) {
  const { data } = await sharedClient.post(`/shared-reports/${token}/unlock`, { password });
  const payload = data.data as { access_token: string; permissions: SharePermissions };

  storeAccessToken(token, payload.access_token);

  return payload;
}

export async function fetchSharedReport(token: string, access: string) {
  const { data } = await sharedClient.get(`/shared-reports/${token}/report`, {
    headers: { 'X-Share-Access': access },
  });
  return data.data;
}

export async function fetchSharedPages(token: string, access: string) {
  const { data } = await sharedClient.get(`/shared-reports/${token}/pages`, {
    headers: { 'X-Share-Access': access },
  });
  return data.data;
}

export async function downloadSharedPdf(token: string, access: string, service: string) {
  const response = await sharedClient.get(`/shared-reports/${token}/pdf/${service}`, {
    headers: { 'X-Share-Access': access },
    responseType: 'blob',
  });

  const url = URL.createObjectURL(response.data as Blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = `${service}-report.pdf`;
  link.click();
  URL.revokeObjectURL(url);
}
