import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { Card } from '../ui/Card';
import { EmptyState } from '../ui/EmptyState';

export function LineChartCard({
  title,
  data,
  xKey,
  lines,
}: {
  title: string;
  data: Record<string, unknown>[];
  xKey: string;
  lines: { key: string; color: string; name: string }[];
}) {
  return (
    <Card>
      <h3 className="mb-4 text-sm font-semibold text-slate-700 dark:text-slate-200">{title}</h3>
      {data.length === 0 ? (
        <EmptyState title="No data yet" description="This chart will populate once requests start coming in." />
      ) : (
        <div className="h-64 w-full">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={data}>
              <CartesianGrid strokeDasharray="3 3" className="stroke-slate-200 dark:stroke-slate-800" />
              <XAxis dataKey={xKey} tick={{ fontSize: 12 }} />
              <YAxis tick={{ fontSize: 12 }} allowDecimals={false} />
              <Tooltip />
              {lines.map((line) => (
                <Line key={line.key} type="monotone" dataKey={line.key} name={line.name} stroke={line.color} strokeWidth={2} dot={false} />
              ))}
            </LineChart>
          </ResponsiveContainer>
        </div>
      )}
    </Card>
  );
}
