import { useState } from 'react';
import { AuthenticatedImage } from '../ui/AuthenticatedImage';

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
      <p className="rounded-md bg-slate-50 p-3 text-xs text-muted dark:bg-slate-800/50 dark:text-faint">
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
                : 'text-muted hover:bg-slate-100 dark:text-faint dark:hover:bg-slate-800'
            }`}
          >
            {v}
            {v === 'mobile' && <span className="ml-1 opacity-70">(priority)</span>}
          </button>
        ))}
      </div>
      {active && (
        // The API sends an absolute signed URL since §48, not a storage
        // path — and it needs the auth header, which an <img> cannot
        // send.
        <AuthenticatedImage
          src={active}
          alt={`${viewport} screenshot of ${alt}`}
          className={`rounded-md border border-line ${
            viewport === 'mobile' ? 'max-h-96 w-auto' : 'max-h-96 w-full object-contain object-top'
          }`}
        />
      )}
    </div>
  );
}
