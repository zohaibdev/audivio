import { LegalPage, type LegalSection } from '../../components/public/LegalPage';

/**
 * Terms of service (§57).
 *
 * The limits stated here are the ones the product actually enforces —
 * plan caps, concurrent audits, rate limits — plus the honest boundaries
 * of what an automated audit can tell you. Promising less than we deliver
 * is fine; promising more is how a tool that reports "0 critical issues"
 * gets read as a compliance certificate.
 *
 * Needs review by someone qualified before launch; the placeholders are
 * marked rather than invented.
 */
const sections: LegalSection[] = [
  {
    heading: 'What Audivio is',
    body: [
      'Audivio is an automated website auditing service. You give us a URL, we crawl it, run a set of checks against what we find, and give you a report of the issues we detected along with their severity.',
      'By using the service you agree to these terms. If you are using it on behalf of an organisation, you confirm you are authorised to accept them for that organisation.',
    ],
  },
  {
    heading: 'What an audit is, and is not',
    body: [
      'An audit is an automated assessment. It reports what our checks detected at the time they ran, on the pages that were crawled. It is evidence you can act on — not a guarantee, a certification, or a legal opinion.',
      'This matters most for accessibility. Automated scanning finds a meaningful share of accessibility barriers, and cannot find the rest: keyboard traps in complex widgets, whether alt text is accurate, whether a page makes sense to a screen-reader user. A clean accessibility score is not a statement that your site complies with the ADA, the Equality Act, WCAG, or any other standard, and must not be presented as one.',
      'The same applies elsewhere: our AI search readiness assessment describes structural clarity, not search rankings, and no part of this service predicts revenue.',
    ],
  },
  {
    heading: 'Only audit sites you are allowed to audit',
    body: [
      'You may submit a website only if you own it or have permission from whoever does. Crawling generates real traffic against a real server.',
      'You must not use the service to attack, overload, or map infrastructure you do not control, to bypass access controls, or to audit systems where doing so would be unlawful. We may refuse or stop any audit.',
    ],
  },
  {
    heading: 'Accounts',
    body: [
      'You are responsible for what happens under your account and for keeping your password and any API tokens to yourself. Tell us promptly if you believe your account has been accessed by someone else.',
      'Shared report links are bearer credentials: anyone holding the link, and the password if you set one, can open the report. Share them accordingly, and revoke a link if it reaches the wrong person.',
    ],
  },
  {
    heading: 'Plan limits',
    body: [
      'Each plan sets limits on pages per audit, audits per day and per month, how many websites you can track, how many audits can run at once, and which services and exports are included. These limits are enforced by the service, and are shown on the pricing page and in your dashboard.',
      'Requests are also rate-limited to keep the service available for everyone. Repeatedly exceeding limits, or working around them, may result in suspension.',
    ],
  },
  {
    heading: 'Your content and ours',
    body: [
      'You keep all rights in your website and its content. You grant us permission to crawl it, store what we find, and process it in order to produce your reports.',
      'We keep the rights in the service itself — the software, the checks, the scoring, and the report format. Your reports are yours to use, publish and share.',
    ],
  },
  {
    heading: 'Availability',
    body: [
      'We aim to keep the service running and accurate, but we provide it as-is. Audits depend on external factors — your server responding, third-party APIs being reachable — and some parts of a report may be unavailable when those fail. Where that happens, the report says so rather than filling the gap.',
      'We may change, suspend, or discontinue parts of the service. Where a change materially reduces what a paid plan includes, we will give notice.',
    ],
  },
  {
    heading: 'Liability',
    body: [
      'To the extent permitted by law, we are not liable for indirect or consequential loss, lost profits, or lost data arising from your use of the service, nor for decisions you take based on an audit report.',
      'Nothing in these terms limits liability that cannot lawfully be limited. [Confirm the liability cap and governing jurisdiction with a qualified adviser before launch.]',
    ],
  },
  {
    heading: 'Ending your use',
    body: [
      'You can stop using the service and ask us to delete your account and its data at any time. We may suspend or end access where these terms are broken, and will tell you why where we can.',
    ],
  },
  {
    heading: 'Changes and contact',
    body: [
      'If we change these terms we will update the date at the top of this page. Continuing to use the service after a change means you accept it.',
      'Questions can be sent through our contact page. [Add your registered company name, address, and governing law here before launch.]',
    ],
  },
];

export function TermsPage() {
  return (
    <LegalPage
      slug="terms"
      title="Terms of Service"
      updated="5 September 2026"
      intro="These terms cover what Audivio provides, what it does not claim to provide, and what we each expect of the other."
      sections={sections}
    />
  );
}
