import { useEffect, useState } from 'react';
import { fetchCmsPage, sectionContent } from '../../api/cms';
import { AuditUrlForm } from '../../components/audit/AuditUrlForm';
import type { CmsPage } from '../../types';

const auditCategories = [
  {
    key: 'performance',
    title: 'Performance',
    description: 'Load-time budgets, page weight, and layout-shift risk — backed by Google PageSpeed Insights data.',
  },
  {
    key: 'accessibility',
    title: 'Accessibility / ADA',
    description: 'Automated axe-core scanning surfaces contrast, labeling, and structural issues that put you at legal risk.',
  },
  {
    key: 'seo',
    title: 'SEO',
    description: 'Titles, meta descriptions, headings, canonical tags, broken links, and duplicate-content checks.',
  },
  {
    key: 'ecommerce',
    title: 'AI Ecommerce & Landing Page',
    description: 'AI-assisted review of product schema, purchase CTAs, and landing-page focus for commerce-driven sites.',
  },
  {
    key: 'conversion',
    title: 'Conversion Rate (CRO)',
    description: 'Form friction, competing CTAs, and trust signals — the details that quietly cost you conversions.',
  },
  {
    key: 'aio',
    title: 'AI Search Readiness (AIO)',
    description:
      'Structural clarity for machine parsing — how well AI search tools can read your content. Not a ranking guarantee.',
  },
];

export function HomePage() {
  const [cms, setCms] = useState<CmsPage | null>(null);

  useEffect(() => {
    fetchCmsPage('homepage').then(setCms);
  }, []);

  const hero = sectionContent(cms, 'hero', {
    heading: 'Know exactly why your website is losing customers.',
    subheading:
      'Audivio audits performance, accessibility, SEO, conversion, and AI search readiness in minutes — then tells you exactly what to fix first.',
  });

  return (
    <div>
      <section className="bg-gradient-to-b from-indigo-50 to-white px-4 py-20 text-center dark:from-slate-900 dark:to-slate-950">
        <div className="mx-auto max-w-3xl">
          <h1 className="text-4xl font-bold tracking-tight text-slate-900 dark:text-white sm:text-5xl">
            {(hero as { heading: string }).heading}
          </h1>
          <p className="mt-4 text-lg text-slate-600 dark:text-slate-300">{(hero as { subheading: string }).subheading}</p>

          <div className="mt-8">
            <AuditUrlForm />
          </div>
          <p className="mt-3 text-xs text-slate-500 dark:text-slate-400">
            One free audit, no credit card required. Sign up afterward to unlock the full report and run more.
          </p>
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-4 py-16">
        <h2 className="text-center text-2xl font-bold text-slate-900 dark:text-white">Six audits, one report</h2>
        <p className="mx-auto mt-2 max-w-2xl text-center text-slate-600 dark:text-slate-300">
          Every audit runs the same deterministic checks first, then layers targeted AI analysis where it adds real signal.
        </p>
        <div className="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          {auditCategories.map((category) => (
            <div key={category.key} className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
              <h3 className="font-semibold text-slate-900 dark:text-white">{category.title}</h3>
              <p className="mt-2 text-sm text-slate-600 dark:text-slate-300">{category.description}</p>
            </div>
          ))}
        </div>
      </section>

      <section className="bg-white px-4 py-16 dark:bg-slate-900">
        <div className="mx-auto grid max-w-5xl gap-10 md:grid-cols-2">
          <div>
            <h2 className="text-xl font-bold text-slate-900 dark:text-white">Start free, upgrade when you're ready</h2>
            <p className="mt-3 text-sm text-slate-600 dark:text-slate-300">
              Anyone can run one free audit with no sign-up — you'll see your overall score and the most critical issues
              immediately. Create a free account for a full recrawl, a daily audit allowance, and up to 25 pages per audit.
              Paid plans unlock full reports, PDF export, audit history, and scheduled recurring audits.
            </p>
          </div>
          <div>
            <h2 className="text-xl font-bold text-slate-900 dark:text-white">Not sure where to start?</h2>
            <p className="mt-3 text-sm text-slate-600 dark:text-slate-300">
              Talk to a consultant about your results, or request a guided review of your full report — no obligation.
            </p>
            <a
              href="/contact"
              className="mt-4 inline-block rounded-md border border-indigo-600 px-4 py-2 text-sm font-medium text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-950"
            >
              Talk to a consultant
            </a>
          </div>
        </div>
      </section>
    </div>
  );
}
