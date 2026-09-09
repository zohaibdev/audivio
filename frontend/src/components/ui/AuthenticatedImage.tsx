import { useEffect, useState } from 'react';
import { apiClient } from '../../api/client';

/**
 * An image behind an authenticated, signed URL (§48).
 *
 * A plain `<img src>` cannot carry the Authorization header, and
 * screenshots are deliberately protected by *both* a signature and an
 * ownership check — the signature stops the URL being forged, the
 * ownership check stops a forwarded one being useful. Weakening it to
 * signature-only would make report links leak screenshots, so the image
 * is fetched with the same authenticated client as everything else and
 * rendered from an object URL.
 *
 * The object URL is revoked on unmount and whenever the source changes;
 * without that, browsing a page explorer leaks a blob per screenshot for
 * as long as the tab lives.
 */
export function AuthenticatedImage({
  src,
  alt,
  className = '',
}: {
  src: string;
  alt: string;
  className?: string;
}) {
  const [objectUrl, setObjectUrl] = useState<string | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    let revoked = false;
    let created: string | null = null;

    setFailed(false);
    setObjectUrl(null);

    apiClient
      .get(src, { responseType: 'blob' })
      .then((response) => {
        if (revoked) return;
        created = URL.createObjectURL(response.data as Blob);
        setObjectUrl(created);
      })
      .catch(() => {
        if (!revoked) setFailed(true);
      });

    return () => {
      revoked = true;
      if (created) URL.revokeObjectURL(created);
    };
  }, [src]);

  if (failed) {
    return (
      <div
        className={`flex items-center justify-center bg-slate-100 text-xs text-faint dark:bg-slate-800 ${className}`}
      >
        Screenshot unavailable
      </div>
    );
  }

  if (objectUrl === null) {
    return <div className={`animate-pulse bg-surface-2 ${className}`} />;
  }

  return <img src={objectUrl} alt={alt} className={className} />;
}
