import { Link } from 'react-router-dom';

const steps = [
  {
    title: '1. Submit your URL',
    description: 'Paste your website address. We validate it, then queue a real crawl — no sign-up required for your first audit.',
  },
  {
    title: '2. We crawl your site',
    description: 'A real browser crawls your pages (respecting robots.txt and your sitemap), extracting content, structure, and performance data.',
  },
  {
    title: '3. Six audits run in parallel',
    description: 'Performance, accessibility, SEO, ecommerce/landing, conversion, and AI search readiness are each scored independently.',
  },
  {
    title: '4. Your report is ready',
    description: 'See your overall score and top issues immediately. Sign up for a full report with every issue, page-level detail, and recommendations.',
  },
  {
    title: '5. Fix, recrawl, track progress',
    description: 'Registered users get a full recrawl and audit history. Paid plans add scheduled recurring audits, so you can track improvement over time.',
  },
];

export function HowItWorksPage() {
  return (
    <div className="mx-auto max-w-3xl px-4 py-16">
      <h1 className="text-3xl font-bold text-slate-900 dark:text-white">How it works</h1>
      <ol className="mt-10 space-y-8">
        {steps.map((step) => (
          <li key={step.title} className="border-l-2 border-indigo-500 pl-6">
            <h2 className="font-semibold text-slate-900 dark:text-white">{step.title}</h2>
            <p className="mt-1 text-sm text-slate-600 dark:text-slate-300">{step.description}</p>
          </li>
        ))}
      </ol>
      <div className="mt-12 text-center">
        <Link to="/" className="inline-block rounded-md bg-indigo-600 px-6 py-3 text-sm font-medium text-white hover:bg-indigo-700">
          Run your free audit
        </Link>
      </div>
    </div>
  );
}
