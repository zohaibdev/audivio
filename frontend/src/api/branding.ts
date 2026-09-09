import { apiClient, unwrap } from './client';

export interface BrandingProfile {
  brand_name: string | null;
  contact_email: string | null;
  contact_phone: string | null;
  contact_website: string | null;
  footer_text: string | null;
  has_logo: boolean;
  /** Saved *and* being applied — both are needed for branding to appear. */
  is_active: boolean;
  /** Whether the plan includes white-labelling at all. */
  is_available_on_plan: boolean;
}

export function fetchBranding() {
  return unwrap<BrandingProfile>(apiClient.get('/me/branding'));
}

/**
 * Sent as multipart because the logo is a file. The other fields are
 * appended individually rather than as JSON so the same request can carry
 * both.
 */
export function saveBranding(values: {
  brand_name?: string;
  contact_email?: string;
  contact_phone?: string;
  contact_website?: string;
  footer_text?: string;
  logo?: File | null;
}) {
  const form = new FormData();

  Object.entries(values).forEach(([key, value]) => {
    if (key === 'logo' || value === undefined || value === null) return;
    form.append(key, String(value));
  });

  if (values.logo) {
    form.append('logo', values.logo);
  }

  return unwrap<BrandingProfile>(apiClient.post('/me/branding', form));
}
