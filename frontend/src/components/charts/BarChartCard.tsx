import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { Card } from '../ui/Card';
import { EmptyState } from '../ui/EmptyState';

export function BarChartCard({
  title,
  data,
  xKey,
  bars,
}: {
  title: string;
  data: Record<string, unknown>[];
  xKey: string;
  bars: { key: string; color: string; name: string }[];
}) {
  return (
    <Card>
      <h3 className="mb-4 text-sm font-semibold text-ink">{title}</h3>
      {data.length === 0 ? (
        <EmptyState title="No data yet" description="This chart will populate once requests start coming in." />
      ) : (
        <div className="h-64 w-full">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={data}>
              <CartesianGrid strokeDasharray="3 3" className="stroke-slate-200 dark:stroke-slate-800" />
              <XAxis dataKey={xKey} tick={{ fontSize: 12 }} />
              <YAxis tick={{ fontSize: 12 }} allowDecimals={false} />
              <Tooltip />
              {bars.map((bar) => (
                <Bar key={bar.key} dataKey={bar.key} name={bar.name} fill={bar.color} radius={[4, 4, 0, 0]} />
              ))}
            </BarChart>
          </ResponsiveContainer>
        </div>
      )}
    </Card>
  );
}
