import { useState } from 'react';

/**
 * Screenshots are stored on Laravel's `public` disk and served from
 * /storage — derived from the API base URL rather than configured
 * separately, so a deployment only has one URL to set.
 */
function storageUrl(path: string): string {
  const apiBase = import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api';
  return `${apiBase.replace(/\/api\/?$/, '')}/storage/${path.replace(/^\/+/, '')}`;
}

type Viewport = 'mobile' | 'desktop';

/**
 * Desktop/mobile screenshot switcher. Mobile is the default view: mobile
 * is the primary audit priority (§35), so it is what the reader sees
 * first unless only a desktop capture exists.
 */
export function ScreenshotViewer({
  screenshots,
  alt,
}: {
  screenshots: { desktop: string | null; mobile: string | null };
  alt: string;
}) {
  const [viewport, setViewport] = useState<Viewport>(screenshots.mobile ? 'mobile' : 'desktop');

  const available = (['mobile', 'desktop'] as const).filter((v) => screenshots[v]);

  if (available.length === 0) {
    // A failed capture must read as unavailable, never as a broken image.
    return (
      <p className="rounded-md bg-slate-50 p-3 text-xs text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
        No screenshot was captured for this page.
      </p>
    );
  }

  const active = screenshots[viewport] ?? screenshots[available[0]];

  return (
    <div>
      <div className="mb-2 flex gap-1">
        {available.map((v) => (
          <button
            key={v}
            type="button"
            onClick={() => setViewport(v)}
            className={`rounded-md px-2.5 py-1 text-xs font-medium capitalize transition ${
              viewport === v
                ? 'bg-indigo-600 text-white'
                : 'text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800'
            }`}
          >
            {v}
            {v === 'mobile' && <span className="ml-1 opacity-70">(priority)</span>}
          </button>
        ))}
      </div>
      {active && (
        <img
          src={storageUrl(active)}
          alt={`${viewport} screenshot of ${alt}`}
          loading="lazy"
          className={`rounded-md border border-slate-200 dark:border-slate-700 ${
            viewport === 'mobile' ? 'max-h-96 w-auto' : 'max-h-96 w-full object-contain object-top'
          }`}
        />
      )}
    </div>
  );
}
