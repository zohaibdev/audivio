import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { fetchCmsPage, sectionContent } from '../../api/cms';
import { AuditUrlForm } from '../../components/audit/AuditUrlForm';
import { HeroReportPreview } from '../../components/public/HeroReportPreview';
import type { CmsPage } from '../../types';

/**
 * The public homepage (§57, redesigned §58).
 *
 * Section layouts deliberately alternate — split hero, numbered rail,
 * asymmetric service grid, two-column report preview, bordered list — so
 * the page does not read as one card grid repeated six times.
 *
 * Everything claimed is something the product does. There are still **no
 * testimonials, customer counts, or client logos**, and the hero mock-up
 * is labelled an example: a product whose pitch is that it does not
 * invent numbers cannot open with invented ones (§57).
 */

const services = [
  {
    key: 'performance',
    title: 'Performance',
    dot: 'bg-amber-500',
    description:
      'Core Web Vitals, page weight, request counts, caching and compression — measured on the real page load.',
  },
  {
    key: 'accessibility',
    title: 'Accessibility / ADA',
    dot: 'bg-emerald-500',
    description:
      'axe-core scanning across 16 check areas, mapped to WCAG criteria. An automated assessment, not a certification.',
  },
  {
    key: 'seo',
    title: 'SEO',
    dot: 'bg-sky-500',
    description:
      'Titles, meta, canonicals, indexability, redirect chains, structured data and internal linking — page by page.',
  },
  {
    key: 'conversion',
    title: 'Conversion (CRO)',
    dot: 'bg-rose-500',
    description:
      'Form friction, competing calls to action, trust signals and contact paths that quietly cost you enquiries.',
  },
  {
    key: 'aio',
    title: 'AI Search Readiness',
    dot: 'bg-indigo-500',
    description:
      'How clearly an answer engine can parse and cite your content. Structural readiness, not a ranking promise.',
  },
  {
    key: 'ecommerce',
    title: 'Ecommerce & Product',
    dot: 'bg-fuchsia-500',
    description:
      'Product schema, purchase paths and cart signals — skipped with a stated reason when your site does not sell online.',
  },
];

const steps = [
  {
    number: '01',
    title: 'Enter your URL',
    body: 'No account needed for your first audit. We check the address is safe to crawl before anything runs.',
  },
  {
    number: '02',
    title: 'We crawl it properly',
    body: 'A real browser renders your pages, measures load timings, and captures mobile and desktop screenshots.',
  },
  {
    number: '03',
    title: 'Checks run, then AI',
    body: 'Deterministic rules do the measurable work. AI is used only where interpretation genuinely adds signal.',
  },
  {
    number: '04',
    title: 'You get a ranked list',
    body: 'Findings graded by severity and grouped by service, each with what it means and why it matters.',
  },
];

const deliverables = [
  'An overall score, plus one for every service that applied to your site',
  'Findings graded critical, high, medium or low, in plain language',
  'Page-by-page results, so you know which URL to open first',
  'Mobile and desktop screenshots captured during the crawl',
  'Redirect, schema, Open Graph and page-level SEO reports',
  'PDF export and a password-protected share link, on plans that include them',
];

const guarantees = [
  {
    title: 'N/A is not zero',
    body: 'A service that does not apply is marked Not Applicable with the reason recorded — never scored as a failure.',
  },
  {
    title: 'A measurement we cannot take stays empty',
    body: 'Never estimated or filled with a placeholder. An invented number is indistinguishable from a measured one.',
  },
  {
    title: 'We say when part of the audit did not run',
    body: 'If an AI-assisted check could not complete, the report says so rather than showing a partial result as a pass.',
  },
  {
    title: 'Re-audits are compared honestly',
    body: 'A finding on a page we did not crawl again is reported as not re-checked — never as fixed.',
  },
];

function SectionHeading({
  eyebrow,
  title,
  body,
  align = 'center',
}: {
  eyebrow: string;
  title: string;
  body?: string;
  align?: 'center' | 'left';
}) {
  return (
    <div className={align === 'center' ? 'mx-auto max-w-2xl text-center' : 'max-w-2xl'}>
      <p className="text-xs font-semibold uppercase tracking-[0.18em] text-brand">{eyebrow}</p>
      <h2 className="mt-3 text-3xl font-bold text-ink sm:text-4xl">{title}</h2>
      {body && <p className="mt-4 text-base leading-relaxed text-muted">{body}</p>}
    </div>
  );
}

export function HomePage() {
  const [cms, setCms] = useState<CmsPage | null>(null);

  useEffect(() => {
    fetchCmsPage('homepage').then(setCms);
  }, []);

  const hero = sectionContent(cms, 'hero', {
    heading: 'Know exactly why your website is losing customers.',
    subheading:
      'Audivio audits performance, accessibility, SEO, conversion, and AI search readiness in minutes — then tells you exactly what to fix first.',
  }) as { heading: string; subheading: string };

  return (
    <div className="overflow-x-clip">
      {/* ---------- Hero ---------- */}
      <section className="relative px-4 pb-24 pt-16 sm:pt-20">
        {/* Decorative backdrop, hidden from assistive tech on a site that
            audits accessibility for a living. */}
        <div aria-hidden="true" className="pointer-events-none absolute inset-0 -z-10 overflow-hidden">
          <div className="animate-drift absolute -top-40 left-1/2 h-[34rem] w-[64rem] -translate-x-1/2 rounded-full bg-[radial-gradient(closest-side,rgba(99,102,241,0.20),transparent)]" />
          <div className="absolute -top-20 right-[8%] h-80 w-80 rounded-full bg-[radial-gradient(closest-side,rgba(16,185,129,0.12),transparent)]" />
        </div>

        <div className="mx-auto grid max-w-6xl items-center gap-14 lg:grid-cols-[1.05fr_1fr]">
          <div className="animate-rise">
            <span className="inline-flex items-center gap-2 rounded-full border border-line bg-surface/70 px-3 py-1 text-xs font-medium text-muted backdrop-blur">
              <span aria-hidden="true" className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
              Six audits · one report · nothing invented
            </span>

            <h1 className="mt-5 text-4xl font-bold leading-[1.08] text-ink sm:text-5xl lg:text-6xl">{hero.heading}</h1>

            <p className="mt-5 max-w-xl text-lg leading-relaxed text-muted">{hero.subheading}</p>

            <div className="mt-8 max-w-xl">
              <AuditUrlForm />
            </div>

            <div className="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-faint">
              <span>One free audit</span>
              <span aria-hidden="true">·</span>
              <span>No credit card</span>
              <span aria-hidden="true">·</span>
              <Link to="/pricing" className="font-medium text-brand hover:underline">
                See plans
              </Link>
            </div>
          </div>

          <div className="animate-rise lg:pl-6" style={{ animationDelay: '120ms' }}>
            <HeroReportPreview />
          </div>
        </div>
      </section>

      {/* ---------- Value / trust strip ---------- */}
      <section className="border-y border-line bg-surface px-4 py-14">
        <div className="mx-auto grid max-w-6xl gap-8 sm:grid-cols-3">
          {[
            { stat: 'Minutes', label: 'From URL to a ranked list of what to fix' },
            { stat: '6 services', label: 'Performance, accessibility, SEO, CRO, AIO and ecommerce' },
            { stat: 'Page level', label: 'Every finding tied to the URL it was found on' },
          ].map((item) => (
            <div key={item.stat} className="text-center sm:text-left">
              <p className="text-2xl font-bold text-ink">{item.stat}</p>
              <p className="mt-1.5 text-sm leading-relaxed text-muted">{item.label}</p>
            </div>
          ))}
        </div>
      </section>

      {/* ---------- Services ---------- */}
      <section className="px-4 py-24">
        <div className="mx-auto max-w-6xl">
          <SectionHeading
            eyebrow="What we audit"
            title="Six services, one report"
            body="Each service runs its deterministic checks first, then layers AI analysis only where interpretation adds real signal."
          />

          <div className="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            {services.map((service) => (
              <div
                key={service.key}
                className="group rounded-card border border-line bg-surface p-6 transition duration-300 hover:-translate-y-0.5 hover:border-line-strong hover:shadow-lift"
              >
                <span aria-hidden="true" className={`block h-2 w-2 rounded-full ${service.dot}`} />
                <h3 className="mt-4 font-semibold text-ink">{service.title}</h3>
                <p className="mt-2 text-sm leading-relaxed text-muted">{service.description}</p>
              </div>
            ))}
          </div>

          <p className="mt-8 text-center text-sm text-faint">
            <Link to="/services" className="font-medium text-brand hover:underline">
              See what each service checks in detail →
            </Link>
          </p>
        </div>
      </section>

      {/* ---------- How it works ---------- */}
      <section className="border-y border-line bg-surface px-4 py-24">
        <div className="mx-auto max-w-6xl">
          <SectionHeading eyebrow="How it works" title="Four steps, no setup" align="left" />

          <ol className="mt-14 grid gap-px overflow-hidden rounded-card border border-line bg-line sm:grid-cols-2 lg:grid-cols-4">
            {steps.map((step) => (
              <li key={step.number} className="bg-surface p-6">
                <span className="text-xs font-bold tracking-[0.2em] text-brand">{step.number}</span>
                <h3 className="mt-3 font-semibold text-ink">{step.title}</h3>
                <p className="mt-2 text-sm leading-relaxed text-muted">{step.body}</p>
              </li>
            ))}
          </ol>
        </div>
      </section>

      {/* ---------- Report preview ---------- */}
      <section className="px-4 py-24">
        <div className="mx-auto grid max-w-6xl items-center gap-16 lg:grid-cols-2">
          <div>
            <SectionHeading
              eyebrow="Your report"
              title="A work list, not a verdict"
              body="Every finding says what it is, which page it is on, how serious it is, and why it matters — so you can start at the top instead of guessing."
              align="left"
            />

            <ul className="mt-8 space-y-3.5">
              {deliverables.map((item) => (
                <li key={item} className="flex gap-3 text-sm leading-relaxed text-ink">
                  <svg
                    viewBox="0 0 20 20"
                    className="mt-0.5 h-4 w-4 shrink-0 text-emerald-500"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="2.5"
                    aria-hidden="true"
                  >
                    <path strokeLinecap="round" strokeLinejoin="round" d="M4 10.5l4 4 8-9" />
                  </svg>
                  <span>{item}</span>
                </li>
              ))}
            </ul>
          </div>

          <div className="relative">
            <div aria-hidden="true" className="pointer-events-none absolute -inset-8 -z-10 rounded-full bg-[radial-gradient(closest-side,rgba(99,102,241,0.14),transparent)]" />
            <HeroReportPreview />
          </div>
        </div>
      </section>

      {/* ---------- Why choose us ---------- */}
      <section className="border-y border-line bg-surface px-4 py-24">
        <div className="mx-auto max-w-5xl">
          <SectionHeading
            eyebrow="Why trust the number"
            title="An audit is only useful if its numbers mean something"
            body="These are rules the product holds itself to, and there are tests behind each one."
          />

          <div className="mt-14 divide-y divide-line border-y border-line">
            {guarantees.map((item, index) => (
              <div key={item.title} className="grid gap-3 py-7 sm:grid-cols-[auto_1fr] sm:gap-8">
                <span className="text-sm font-bold tabular-nums text-faint">
                  {String(index + 1).padStart(2, '0')}
                </span>
                <div>
                  <h3 className="font-semibold text-ink">{item.title}</h3>
                  <p className="mt-1.5 text-sm leading-relaxed text-muted">{item.body}</p>
                </div>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ---------- Final CTA ---------- */}
      <section className="px-4 py-24">
        <div className="relative mx-auto max-w-4xl overflow-hidden rounded-[1.75rem] bg-gradient-to-br from-indigo-600 via-indigo-600 to-violet-700 px-6 py-16 text-center shadow-lift">
          <div
            aria-hidden="true"
            className="pointer-events-none absolute inset-0 bg-[radial-gradient(closest-side,rgba(255,255,255,0.22),transparent)]"
          />
          <div className="relative">
            <h2 className="text-3xl font-bold text-white sm:text-4xl">See your score in a few minutes</h2>
            <p className="mx-auto mt-4 max-w-lg text-base leading-relaxed text-indigo-100">
              One free audit. No card, no sales call, no obligation.
            </p>

            <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
              <Link
                to="/audit"
                className="rounded-control bg-white px-6 py-3 text-sm font-semibold text-indigo-700 shadow-soft transition hover:bg-indigo-50"
              >
                Audit my website
              </Link>
              <Link
                to="/pricing"
                className="rounded-control border border-white/40 px-6 py-3 text-sm font-semibold text-white transition hover:bg-white/10"
              >
                Compare plans
              </Link>
            </div>
          </div>
        </div>
      </section>
    </div>
  );
}
