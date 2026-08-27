const services = [
  {
    title: 'Performance Audit',
    description:
      'We measure real load-time budgets, page weight, and Core Web Vitals via Google PageSpeed Insights, then flag images and assets that are actively hurting your load time.',
  },
  {
    title: 'Accessibility / ADA Audit',
    description:
      'An automated axe-core scan on every crawled page catches contrast failures, missing labels, and structural issues — the kind of gaps that create real legal exposure.',
  },
  {
    title: 'SEO Audit',
    description:
      'Titles, meta descriptions, heading structure, canonical tags, alt text, duplicate content, and broken internal links, checked across your whole site.',
  },
  {
    title: 'AI Ecommerce & Landing Page Audit',
    description:
      'For stores and landing pages, we check product schema, purchase-intent CTAs, and page focus — layering AI analysis on top of deterministic checks only where it adds real signal.',
  },
  {
    title: 'Conversion Rate (CRO) Audit',
    description:
      'Form friction, competing or unclear CTAs, and missing trust signals inferred only from the text actually on your page — never invented.',
  },
  {
    title: 'AI Search Readiness (AIO)',
    description:
      'How clearly your structure and content can be parsed by AI search tools. This measures structural clarity only — it never promises ranking or citation in any specific AI product.',
  },
];

export function ServicesPage() {
  return (
    <div className="mx-auto max-w-5xl px-4 py-16">
      <h1 className="text-3xl font-bold text-slate-900 dark:text-white">What we audit</h1>
      <p className="mt-2 max-w-2xl text-slate-600 dark:text-slate-300">
        Every audit combines deterministic, rule-based checks with targeted AI analysis — never the other way around.
      </p>
      <div className="mt-10 grid gap-6 md:grid-cols-2">
        {services.map((service) => (
          <div key={service.title} className="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
            <h2 className="font-semibold text-slate-900 dark:text-white">{service.title}</h2>
            <p className="mt-2 text-sm text-slate-600 dark:text-slate-300">{service.description}</p>
          </div>
        ))}
      </div>
    </div>
  );
}
