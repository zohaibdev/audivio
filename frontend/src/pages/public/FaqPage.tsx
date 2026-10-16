import { useState } from 'react';

const faqs = [
  {
    question: 'Is the free audit really free?',
    answer:
      'Yes. Anyone can run one audit with no sign-up and no credit card. You\'ll see your overall score and category scores immediately, plus the most critical issues found.',
  },
  {
    question: 'What do I get by creating an account?',
    answer:
      'A full report with every issue and recommendation, a full recrawl of your site, and a higher daily audit allowance with up to 25 pages per audit on the free tier.',
  },
  {
    question: 'Does the AI Search Readiness score guarantee I\'ll rank in ChatGPT or Google AI Overviews?',
    answer:
      'No. It measures how structurally clear your content is for machine parsing only — it never predicts or guarantees ranking, citation, or visibility in any specific AI search product.',
  },
  {
    question: 'How is my data used during a crawl?',
    answer:
      'We crawl only publicly accessible pages of the URL you submit, respecting robots.txt. Every URL is validated against SSRF protections before it\'s ever crawled.',
  },
  {
    question: 'Can I run audits on a schedule?',
    answer: 'Yes, on paid plans that include scheduled audits — pick a website and a daily, weekly, or monthly cadence, and we\'ll recrawl it automatically.',
  },
  {
    question: 'What if I need help interpreting my results?',
    answer: 'Use the "Talk to a Consultant" link on your report or the Contact page — a real person will follow up.',
  },
];

export function FaqPage() {
  const [openIndex, setOpenIndex] = useState<number | null>(0);

  return (
    <div className="mx-auto max-w-2xl px-4 py-16">
      <h1 className="text-3xl font-bold text-ink">Frequently asked questions</h1>
      <dl className="mt-8 divide-y divide-line">
        {faqs.map((faq, index) => {
          const isOpen = openIndex === index;
          return (
            <div key={faq.question} className="py-4">
              <dt>
                <button
                  type="button"
                  className="flex w-full items-center justify-between text-left font-medium text-ink"
                  aria-expanded={isOpen}
                  onClick={() => setOpenIndex(isOpen ? null : index)}
                >
                  {faq.question}
                  <span aria-hidden="true" className="ml-4 text-faint">
                    {isOpen ? '−' : '+'}
                  </span>
                </button>
              </dt>
              {isOpen && <dd className="mt-2 text-sm text-muted">{faq.answer}</dd>}
            </div>
          );
        })}
      </dl>
    </div>
  );
}
