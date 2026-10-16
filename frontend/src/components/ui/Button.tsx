import type { ButtonHTMLAttributes } from 'react';

type Variant = 'primary' | 'secondary' | 'danger' | 'ghost' | 'subtle';
type Size = 'sm' | 'md' | 'lg';

/**
 * Button variants (§58).
 *
 * Built on the semantic tokens rather than raw palette steps, so the two
 * themes cannot drift. The primary keeps a slight lift on hover — enough
 * to feel responsive, not enough to bounce.
 */
const variantClasses: Record<Variant, string> = {
  primary:
    'bg-brand text-on-brand shadow-soft hover:bg-brand-strong hover:shadow-lift disabled:bg-brand/50 disabled:shadow-none',
  secondary:
    'border border-line bg-surface text-ink hover:border-line-strong hover:bg-surface-2',
  subtle: 'bg-surface-2 text-ink hover:bg-line',
  danger: 'bg-red-600 text-white shadow-soft hover:bg-red-700 disabled:bg-red-400',
  ghost: 'text-muted hover:bg-surface-2 hover:text-ink',
};

const sizeClasses: Record<Size, string> = {
  sm: 'px-3 py-1.5 text-xs',
  md: 'px-4 py-2 text-sm',
  lg: 'px-5 py-2.5 text-sm',
};

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant;
  size?: Size;
  isLoading?: boolean;
}

export function Button({
  variant = 'primary',
  size = 'md',
  isLoading = false,
  disabled,
  children,
  className = '',
  ...rest
}: ButtonProps) {
  return (
    <button
      type="button"
      disabled={disabled || isLoading}
      aria-busy={isLoading || undefined}
      className={`inline-flex items-center justify-center gap-2 rounded-control font-medium transition duration-200 disabled:cursor-not-allowed disabled:opacity-70 ${sizeClasses[size]} ${variantClasses[variant]} ${className}`}
      {...rest}
    >
      {isLoading && (
        <span className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-current border-t-transparent" />
      )}
      {children}
    </button>
  );
}
