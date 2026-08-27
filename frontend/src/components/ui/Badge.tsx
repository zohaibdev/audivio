type BadgeTone = 'green' | 'red' | 'yellow' | 'gray' | 'blue' | 'purple';

const toneClasses: Record<BadgeTone, string> = {
  green: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400',
  red: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-400',
  yellow: 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-400',
  gray: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
  blue: 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-400',
  purple: 'bg-purple-100 text-purple-700 dark:bg-purple-950 dark:text-purple-400',
};

export function Badge({ children, tone = 'gray' }: { children: string; tone?: BadgeTone }) {
  return (
    <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${toneClasses[tone]}`}>
      {children}
    </span>
  );
}

const severityTone: Record<string, BadgeTone> = {
  critical: 'red',
  high: 'red',
  medium: 'yellow',
  low: 'blue',
  passed: 'green',
};

export function SeverityBadge({ severity }: { severity: string }) {
  return <Badge tone={severityTone[severity] ?? 'gray'}>{severity}</Badge>;
}

const statusTone: Record<string, BadgeTone> = {
  pending: 'gray',
  crawling: 'blue',
  analyzing: 'blue',
  completed: 'green',
  failed: 'red',
};

export function StatusBadge({ status }: { status: string }) {
  return <Badge tone={statusTone[status] ?? 'gray'}>{status}</Badge>;
}
