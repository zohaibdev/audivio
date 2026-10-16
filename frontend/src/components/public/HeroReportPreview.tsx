/**
 * The hero's report mock-up (§58).
 *
 * Deliberately and visibly an **example**: it is labelled as one, the
 * domain is `example.com`, and nothing here is presented as a real
 * customer's result. For a product whose entire pitch is that it does not
 * invent numbers, a hero showing fabricated scores dressed as real ones
 * would undercut the claim before the visitor reached it.
 *
 * Everything is inline SVG and CSS — no chart library, no images — so the
 * hero costs nothing to load.
 */

/*
 * Deeper shades in light, brighter in dark.
 *
 * The 500 steps looked right and measured 2.1-2.7:1 on a light card —
 * failing AA for text and the 3:1 floor for meaningful graphics. Rings
 * are graphics (3:1) so they sit one step lighter than the numerals,
 * which are text (4.5:1) (§58).
 */
const scores = [
  { label: 'Performance', value: 72, tone: 'text-amber-700 dark:text-amber-400', ring: 'stroke-amber-600 dark:stroke-amber-400' },
  { label: 'Accessibility', value: 94, tone: 'text-emerald-700 dark:text-emerald-400', ring: 'stroke-emerald-600 dark:stroke-emerald-400' },
  { label: 'SEO', value: 88, tone: 'text-sky-700 dark:text-sky-400', ring: 'stroke-sky-600 dark:stroke-sky-400' },
];

/** Illustrative trend — a score climbing over six audits. */
const trend = [48, 55, 61, 60, 68, 79];

function ScoreRing({ value, ring, tone, label }: { value: number; ring: string; tone: string; label: string }) {
  const radius = 22;
  const circumference = 2 * Math.PI * radius;
  const offset = circumference - (value / 100) * circumference;

  return (
    <div className="flex flex-col items-center gap-1.5">
      <div className="relative">
        <svg viewBox="0 0 56 56" className="h-14 w-14 -rotate-90" aria-hidden="true">
          <circle cx="28" cy="28" r={radius} fill="none" strokeWidth="5" className="stroke-line" />
          <circle
            cx="28"
            cy="28"
            r={radius}
            fill="none"
            strokeWidth="5"
            strokeLinecap="round"
            className={ring}
            strokeDasharray={circumference}
            strokeDashoffset={offset}
          />
        </svg>
        <span className={`absolute inset-0 grid place-items-center text-sm font-bold ${tone}`}>{value}</span>
      </div>
      <span className="text-[11px] font-medium text-faint">{label}</span>
    </div>
  );
}

function Sparkline() {
  const width = 180;
  const height = 44;
  const max = Math.max(...trend);
  const min = Math.min(...trend);
  const span = max - min || 1;

  const points = trend.map((value, index) => {
    const x = (index / (trend.length - 1)) * width;
    const y = height - ((value - min) / span) * (height - 6) - 3;

    return `${x.toFixed(1)},${y.toFixed(1)}`;
  });

  return (
    <svg viewBox={`0 0 ${width} ${height}`} className="w-full" aria-hidden="true">
      <polyline
        points={points.join(' ')}
        fill="none"
        strokeWidth="2.5"
        strokeLinecap="round"
        strokeLinejoin="round"
        className="stroke-brand"
      />
      {points.slice(-1).map((point) => {
        const [x, y] = point.split(',');

        return <circle key={point} cx={x} cy={y} r="3.5" className="fill-brand" />;
      })}
    </svg>
  );
}

export function HeroReportPreview() {
  return (
    <div className="relative">
      {/* Main report card */}
      <div className="glass rounded-card p-5 shadow-lift">
        <div className="flex items-center justify-between gap-3">
          <div className="flex items-center gap-2">
            <span aria-hidden="true" className="flex gap-1">
              <span className="h-2 w-2 rounded-full bg-red-400" />
              <span className="h-2 w-2 rounded-full bg-amber-400" />
              <span className="h-2 w-2 rounded-full bg-emerald-400" />
            </span>
            <span className="rounded-md bg-surface-2 px-2 py-0.5 text-[11px] text-faint">example.com</span>
          </div>
          <span className="rounded-full bg-brand-soft px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand">
            Example
          </span>
        </div>

        <div className="mt-5 flex items-end justify-between gap-4">
          <div>
            <p className="text-[11px] uppercase tracking-wider text-faint">Overall</p>
            <p className="text-4xl font-bold text-ink">84</p>
          </div>
          <div className="flex gap-4">
            {scores.map((score) => (
              <ScoreRing key={score.label} {...score} />
            ))}
          </div>
        </div>

        <div className="mt-5 grid grid-cols-3 gap-2 border-t border-line pt-4">
          <div>
            <p className="text-lg font-bold text-red-600 dark:text-red-400">2</p>
            <p className="text-[11px] text-faint">Critical</p>
          </div>
          <div>
            <p className="text-lg font-bold text-amber-700 dark:text-amber-400">7</p>
            <p className="text-[11px] text-faint">High</p>
          </div>
          <div>
            <p className="text-lg font-bold text-ink">31</p>
            <p className="text-[11px] text-faint">Pages</p>
          </div>
        </div>
      </div>

      {/* Floating trend card */}
      <div className="glass absolute -bottom-8 -left-6 hidden w-52 rounded-card p-4 shadow-lift sm:block">
        <p className="text-[11px] uppercase tracking-wider text-faint">Score trend</p>
        <div className="mt-2">
          <Sparkline />
        </div>
        <p className="mt-1 text-xs font-medium text-emerald-700 dark:text-emerald-400">+31 over 6 audits</p>
      </div>

      {/* Floating N/A card — the product's own honesty rule, on display */}
      <div className="glass absolute -right-4 -top-6 hidden w-44 rounded-card p-4 shadow-lift lg:block">
        <p className="text-[11px] uppercase tracking-wider text-faint">Ecommerce</p>
        <p className="mt-1 text-sm font-semibold text-ink">N/A</p>
        <p className="mt-1 text-[11px] leading-snug text-muted">No shop to assess — not scored as zero.</p>
      </div>
    </div>
  );
}
