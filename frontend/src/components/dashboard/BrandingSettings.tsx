import { useEffect, useState } from 'react';
import { Card } from '../ui/Card';
import { fetchBranding, saveBranding } from '../../api/branding';
import type { BrandingProfile } from '../../api/branding';
import { apiErrorMessage } from '../../api/client';

/**
 * White-label branding for exported reports (§48).
 *
 * Editable on any plan. The entitlement is checked when a report renders,
 * not when these values are saved, so a user on a plan without
 * white-labelling can still set it up — and someone who downgrades keeps
 * their details rather than losing them and having to re-enter everything
 * on upgrading. The banner says plainly which state they are in.
 */
export function BrandingSettings() {
  const [profile, setProfile] = useState<BrandingProfile | null>(null);
  const [logo, setLogo] = useState<File | null>(null);
  const [status, setStatus] = useState<'idle' | 'saving' | 'saved'>('idle');
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    fetchBranding()
      .then(setProfile)
      .catch((caught) => setError(apiErrorMessage(caught)));
  }, []);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    if (profile === null) return;

    setStatus('saving');
    setError(null);

    try {
      const saved = await saveBranding({
        brand_name: profile.brand_name ?? '',
        contact_email: profile.contact_email ?? '',
        contact_phone: profile.contact_phone ?? '',
        contact_website: profile.contact_website ?? '',
        footer_text: profile.footer_text ?? '',
        logo,
      });

      setProfile(saved);
      setLogo(null);
      setStatus('saved');
    } catch (caught) {
      setStatus('idle');
      setError(apiErrorMessage(caught));
    }
  }

  if (profile === null) {
    return (
      <Card>
        <p className="text-sm text-muted">
          {error ?? 'Loading branding…'}
        </p>
      </Card>
    );
  }

  function update(field: keyof BrandingProfile, value: string) {
    setProfile((current) => (current === null ? current : { ...current, [field]: value }));
    setStatus('idle');
  }

  return (
    <Card>
      <h2 className="text-lg font-semibold text-ink">Report branding</h2>
      <p className="mt-1 text-sm text-muted">
        Applied to exported PDF reports in place of ours.
      </p>

      {!profile.is_available_on_plan && (
        <div className="mt-3 rounded-md border border-amber-300 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950/40">
          {/* Saved but inactive is a real state, not a reason to hide the
              form and lose what is behind it. */}
          <p className="text-sm text-amber-900 dark:text-amber-200">
            White-label reports are not included in your current plan. You can still set this up —
            it will apply automatically if you upgrade.
          </p>
        </div>
      )}

      <form onSubmit={submit} className="mt-4 flex flex-col gap-4">
        <label className="text-sm">
          <span className="text-muted">Brand name</span>
          <input
            value={profile.brand_name ?? ''}
            onChange={(event) => update('brand_name', event.target.value)}
            className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
          />
        </label>

        <div className="grid gap-4 sm:grid-cols-2">
          <label className="text-sm">
            <span className="text-muted">Contact email</span>
            <input
              type="email"
              value={profile.contact_email ?? ''}
              onChange={(event) => update('contact_email', event.target.value)}
              className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
            />
          </label>

          <label className="text-sm">
            <span className="text-muted">Contact phone</span>
            <input
              value={profile.contact_phone ?? ''}
              onChange={(event) => update('contact_phone', event.target.value)}
              className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
            />
          </label>
        </div>

        <label className="text-sm">
          <span className="text-muted">Website</span>
          <input
            type="url"
            placeholder="https://youragency.com"
            value={profile.contact_website ?? ''}
            onChange={(event) => update('contact_website', event.target.value)}
            className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
          />
        </label>

        <label className="text-sm">
          <span className="text-muted">Report footer</span>
          <textarea
            rows={2}
            value={profile.footer_text ?? ''}
            onChange={(event) => update('footer_text', event.target.value)}
            className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
          />
        </label>

        <label className="text-sm">
          <span className="text-muted">
            Logo {profile.has_logo && <span className="text-faint">(one is already saved)</span>}
          </span>
          <input
            type="file"
            accept="image/png,image/jpeg,image/webp"
            onChange={(event) => setLogo(event.target.files?.[0] ?? null)}
            className="mt-1 w-full text-sm text-muted"
          />
        </label>

        {error && <p className="text-sm text-red-600 dark:text-red-400">{error}</p>}

        <div className="flex items-center gap-3">
          <button
            type="submit"
            disabled={status === 'saving'}
            className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-500 disabled:opacity-60"
          >
            {status === 'saving' ? 'Saving…' : 'Save branding'}
          </button>

          {status === 'saved' && (
            <span className="text-sm text-emerald-600 dark:text-emerald-400">
              Saved{profile.is_active ? ' and active on your reports.' : '.'}
            </span>
          )}
        </div>
      </form>
    </Card>
  );
}
