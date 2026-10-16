import { Link } from 'react-router-dom';
import { Card } from '../../components/ui/Card';

const links = [
  { to: '/admin/providers', title: 'AI & API Providers', description: 'Configure Claude, OpenAI, Gemini, and PageSpeed credentials and limits.' },
  { to: '/admin/plans', title: 'Plans & Usage Limits', description: 'Page limits, daily audit limits, and feature entitlements per plan.' },
  { to: '/admin/cms', title: 'CMS', description: 'Homepage, pricing, FAQ, and other dynamic marketing content.' },
  { to: '/admin/system-health', title: 'System Health', description: 'Database, cache, queue, and provider status.' },
];

export function AdminSettingsPage() {
  return (
    <div>
      <h1 className="mb-6 text-2xl font-bold text-ink">Settings</h1>
      <div className="grid gap-4 sm:grid-cols-2">
        {links.map((link) => (
          <Link key={link.to} to={link.to}>
            <Card className="h-full transition hover:border-indigo-300 dark:hover:border-indigo-700">
              <h2 className="font-semibold text-ink">{link.title}</h2>
              <p className="mt-1 text-sm text-muted">{link.description}</p>
            </Card>
          </Link>
        ))}
      </div>
    </div>
  );
}
