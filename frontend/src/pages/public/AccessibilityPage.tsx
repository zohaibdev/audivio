import { LegalPage, type LegalSection } from '../../components/public/LegalPage';

/**
 * Accessibility statement (§57).
 *
 * A product that audits accessibility and has no accessibility statement
 * of its own is an easy thing to be caught out on. This one says what has
 * actually been done and — more usefully — what has not, because a
 * statement claiming full conformance without an audit behind it is
 * exactly the overclaim this product exists to catch.
 */
const sections: LegalSection[] = [
  {
    heading: 'Where we stand',
    body: [
      'We aim to meet WCAG 2.1 Level AA across this site and the audit reports it produces. We have not commissioned an independent accessibility audit of our own interface, so we are not claiming conformance — we are telling you what we have done and what we know is still open.',
      'That distinction is the same one we make in every report we produce: an automated pass is evidence, not a certificate.',
    ],
  },
  {
    heading: 'What we have done',
    body: ['Accessibility work built into the interface so far:'],
    bullets: [
      'Semantic headings, landmarks and lists, so the page structure is navigable without sight.',
      'Full keyboard operability for forms, dialogs and navigation, with visible focus indicators.',
      'Colour contrast checked against WCAG AA for body text and interface controls, in both light and dark themes.',
      'A dark mode that follows your system preference, with an explicit override.',
      'Decorative graphics hidden from assistive technology, and meaningful images given text alternatives.',
      'Layouts that reflow to 375px without horizontal scrolling.',
      'Status and error messages surfaced as text rather than colour alone.',
    ],
  },
  {
    heading: 'What we know is not done',
    body: [
      'We would rather list these than let you discover them:',
    ],
    bullets: [
      'No independent audit or assistive-technology user testing has been carried out on this interface.',
      'Charts in the admin and report views convey data visually; the underlying figures are available as text nearby, but the charts themselves are not fully described.',
      'PDF exports are generated from report data and have not been tested for tagged-PDF accessibility.',
      'Screenshots captured during an audit are images of your site; we cannot describe their content.',
    ],
  },
  {
    heading: 'Reporting a problem',
    body: [
      'If something here is difficult or impossible to use, please tell us through the contact page. Describe what you were trying to do, what happened, and the assistive technology and browser you were using if you know them.',
      'We treat accessibility reports as bugs, not feature requests, and we will tell you what we intend to do about it.',
    ],
  },
  {
    heading: 'A note on our accessibility audits',
    body: [
      'Our accessibility service scans your site with axe-core and maps findings to WCAG success criteria. Automated tooling reliably detects a meaningful share of accessibility barriers, and cannot detect the rest — whether alternative text is accurate, whether a custom widget is operable by keyboard in practice, or whether a page is understandable.',
      'A clean automated result is a good sign and not a compliance statement. Manual testing with real assistive-technology users is the only way to answer the questions a scanner cannot reach.',
    ],
  },
];

export function AccessibilityPage() {
  return (
    <LegalPage
      slug="accessibility"
      title="Accessibility Statement"
      updated="5 September 2026"
      intro="How accessible this site is, what we have done about it, and what we know is still outstanding."
      sections={sections}
    />
  );
}
