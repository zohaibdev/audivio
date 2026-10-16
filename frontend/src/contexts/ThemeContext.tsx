import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';

/**
 * Three states, not two (§47), defaulting to light (§58).
 *
 * `system` follows the browser's own preference and keeps following it
 * when the visitor changes it mid-session, which is what someone on a
 * phone switching to dark at sunset expects. It is still offered — losing
 * it would be a downgrade for those visitors — but it is no longer the
 * default: a first-time visitor gets light, and the inline script in
 * index.html applies the same rule before first paint so the two can
 * never disagree and cause a flash.
 */
export type ThemePreference = 'light' | 'dark' | 'system';

const STORAGE_KEY = 'audivio.theme';

interface ThemeContextValue {
  /** What the visitor chose. */
  preference: ThemePreference;
  /** What is actually rendered right now, after resolving `system`. */
  resolved: 'light' | 'dark';
  setPreference: (preference: ThemePreference) => void;
}

const ThemeContext = createContext<ThemeContextValue | null>(null);

function readStoredPreference(): ThemePreference {
  // Storage can throw outright in a private window or with site data
  // blocked, so a failure here falls back to the light default rather
  // than taking the page down.
  try {
    const stored = localStorage.getItem(STORAGE_KEY);

    if (stored === 'light' || stored === 'dark' || stored === 'system') {
      return stored;
    }
  } catch {
    // Ignored deliberately.
  }

  // Light for a first-time visitor. `system` remains selectable, but it
  // has to be chosen rather than assumed.
  return 'light';
}

function systemPrefersDark(): boolean {
  return typeof window !== 'undefined' && window.matchMedia('(prefers-color-scheme: dark)').matches;
}

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [preference, setPreferenceState] = useState<ThemePreference>(readStoredPreference);
  const [systemDark, setSystemDark] = useState<boolean>(systemPrefersDark);

  // Tracked for as long as the preference is `system`: a visitor whose OS
  // switches to dark while the tab is open should see the page follow,
  // not wait for a reload.
  useEffect(() => {
    const query = window.matchMedia('(prefers-color-scheme: dark)');
    const onChange = (event: MediaQueryListEvent) => setSystemDark(event.matches);

    query.addEventListener('change', onChange);

    return () => query.removeEventListener('change', onChange);
  }, []);

  const resolved: 'light' | 'dark' =
    preference === 'system' ? (systemDark ? 'dark' : 'light') : preference;

  useEffect(() => {
    const root = document.documentElement;

    root.classList.toggle('dark', resolved === 'dark');
    // Tells the browser which palette to use for form controls,
    // scrollbars and the like, which CSS classes alone do not cover.
    root.style.colorScheme = resolved;
  }, [resolved]);

  const setPreference = useCallback((next: ThemePreference) => {
    setPreferenceState(next);

    try {
      localStorage.setItem(STORAGE_KEY, next);
    } catch {
      // A preference that cannot be persisted still applies for this
      // session; failing to save is not a reason to fail to apply.
    }
  }, []);

  const value = useMemo(
    () => ({ preference, resolved, setPreference }),
    [preference, resolved, setPreference],
  );

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export function useTheme(): ThemeContextValue {
  const context = useContext(ThemeContext);

  if (context === null) {
    throw new Error('useTheme must be used inside a ThemeProvider');
  }

  return context;
}
