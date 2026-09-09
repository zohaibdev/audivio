import type { ReactNode } from 'react';

type Variant = 'solid' | 'glass' | 'muted';

/**
 * Card variants (§58).
 *
 * `glass` is for surfaces that sit over a gradient or the hero backdrop.
 * It is deliberately not the default: frosting everything is what makes
 * an interface look blurry rather than premium, and a card on a flat
 * background has nothing to frost.
 */
const variantClasses: Record<Variant, string> = {
  solid: 'border border-line bg-surface shadow-soft',
  glass: 'glass shadow-soft',
  muted: 'border border-line bg-surface-2',
};

export function Card({
  children,
  className = '',
  variant = 'solid',
  interactive = false,
}: {
  children: ReactNode;
  className?: string;
  variant?: Variant;
  /** Adds hover elevation. Only for cards that are actually clickable. */
  interactive?: boolean;
}) {
  return (
    <div
      className={`rounded-card p-5 transition duration-300 ${variantClasses[variant]} ${
        interactive ? 'hover:-translate-y-0.5 hover:border-line-strong hover:shadow-lift' : ''
      } ${className}`}
    >
      {children}
    </div>
  );
}
