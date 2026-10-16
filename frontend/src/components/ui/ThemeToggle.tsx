import { useTheme } from '../../contexts/ThemeContext';
import type { ThemePreference } from '../../contexts/ThemeContext';

/**
 * The three theme states as one compact control (§47, restyled §58).
 *
 * A two-state toggle cannot express "follow my browser", so a visitor who
 * has set their OS to switch at sunset would be stuck on whichever they
 * last picked. Light is the default (§58), but `system` stays available
 * for the people it actually serves.
 *
 * Icons are inline SVG rather than emoji: emoji render at wildly
 * different sizes and weights across platforms, which is exactly the
 * detail that makes a control look unfinished.
 */

function SunIcon() {
  return (
    <svg viewBox="0 0 24 24" className="h-3.5 w-3.5" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
      <circle cx="12" cy="12" r="4" />
      <path strokeLinecap="round" d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
    </svg>
  );
}

function MoonIcon() {
  return (
    <svg viewBox="0 0 24 24" className="h-3.5 w-3.5" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
      <path strokeLinecap="round" strokeLinejoin="round" d="M20 14.5A8.5 8.5 0 1 1 9.5 4a7 7 0 0 0 10.5 10.5Z" />
    </svg>
  );
}

function AutoIcon() {
  return (
    <svg viewBox="0 0 24 24" className="h-3.5 w-3.5" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
      <circle cx="12" cy="12" r="9" />
      <path d="M12 3a9 9 0 0 1 0 18Z" fill="currentColor" stroke="none" />
    </svg>
  );
}

const OPTIONS: { value: ThemePreference; label: string; title: string; Icon: () => React.ReactElement }[] = [
  { value: 'light', label: 'Light', title: 'Always use the light theme', Icon: SunIcon },
  { value: 'dark', label: 'Dark', title: 'Always use the dark theme', Icon: MoonIcon },
  // Named for what it does rather than "Auto": a visitor should be able
  // to tell what it will follow without trying it.
  { value: 'system', label: 'Browser', title: "Follow your browser's preference", Icon: AutoIcon },
];

export function ThemeToggle({ className = '' }: { className?: string }) {
  const { preference, setPreference } = useTheme();

  return (
    <div
      role="radiogroup"
      aria-label="Colour theme"
      className={`inline-flex items-center gap-0.5 rounded-full border border-line bg-surface-2/70 p-1 ${className}`}
    >
      {OPTIONS.map((option) => {
        const active = preference === option.value;

        return (
          <button
            key={option.value}
            type="button"
            role="radio"
            aria-checked={active}
            aria-label={option.title}
            title={option.title}
            onClick={() => setPreference(option.value)}
            className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium transition ${
              active
                ? 'bg-surface text-ink shadow-soft'
                // `muted`, not `faint`: on the toggle's tinted track,
                // faint measured 3.87:1 at 12px and failed AA (§58).
                : 'text-muted hover:text-ink'
            }`}
          >
            <option.Icon />
            <span className="hidden lg:inline">{option.label}</span>
          </button>
        );
      })}
    </div>
  );
}
