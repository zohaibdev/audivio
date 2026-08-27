function toneFor(score: number): string {
  if (score >= 90) return 'text-emerald-600 dark:text-emerald-400';
  if (score >= 70) return 'text-amber-600 dark:text-amber-400';
  return 'text-red-600 dark:text-red-400';
}

export function ScoreGauge({ score, label, size = 'md' }: { score: number | null; label: string; size?: 'sm' | 'md' | 'lg' }) {
  const sizeClasses = { sm: 'text-xl', md: 'text-3xl', lg: 'text-5xl' }[size];

  return (
    <div className="flex flex-col items-center gap-1">
      <span className={`font-bold ${sizeClasses} ${score === null ? 'text-slate-400' : toneFor(score)}`}>
        {score === null ? '—' : Math.round(score)}
      </span>
      <span className="text-center text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
        {label}
      </span>
    </div>
  );
}
