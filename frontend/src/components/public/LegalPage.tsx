import { useEffect, useState } from 'react';
import { fetchCmsPage, sectionContent } from '../../api/cms';
import type { CmsPage } from '../../types';

export interface LegalSection {
  heading: string;
  /** Paragraphs. Plain strings — no HTML, so nothing here can inject markup. */
  body: string[];
  /** Optional bulleted list rendered after the paragraphs. */
  bullets?: string[];
}

/**
 * Shared shell for the long-form public pages (§57).
 *
 * Content is CMS-backed per section with a hardcoded fallback, the same
 * pattern the homepage uses: the fallback answers "what if the row is
 * deleted", not "what does this site say". Sections render as plain text
 * rather than HTML, so editable content can never inject markup into a
 * public page.
 */
export function LegalPage({
  slug,
  title,
  intro,
  updated,
  sections,
}: {
  slug: string;
  title: string;
  intro: string;
  updated: string;
  sections: LegalSection[];
}) {
  const [cms, setCms] = useState<CmsPage | null>(null);

  useEffect(() => {
    fetchCmsPage(slug).then(setCms);
  }, [slug]);

  const heading = sectionContent(cms, 'heading', { title, intro, updated }) as {
    title: string;
    intro: string;
    updated: string;
  };

  const body = sectionContent(cms, 'body', { sections }) as { sections: LegalSection[] };

  return (
    <div className="mx-auto max-w-3xl px-4 py-16">
      <h1 className="text-3xl font-bold tracking-tight text-ink">{heading.title}</h1>

      <p className="mt-2 text-xs uppercase tracking-wide text-faint">Last updated {heading.updated}</p>

      <p className="mt-6 text-base leading-relaxed text-muted">{heading.intro}</p>

      <div className="mt-10 space-y-10">
        {body.sections.map((section) => (
          <section key={section.heading}>
            <h2 className="text-lg font-semibold text-ink">{section.heading}</h2>

            {section.body.map((paragraph) => (
              <p key={paragraph} className="mt-3 text-sm leading-relaxed text-muted">
                {paragraph}
              </p>
            ))}

            {section.bullets && (
              <ul className="mt-3 list-disc space-y-1.5 pl-5 text-sm leading-relaxed text-muted">
                {section.bullets.map((bullet) => (
                  <li key={bullet}>{bullet}</li>
                ))}
              </ul>
            )}
          </section>
        ))}
      </div>
    </div>
  );
}
