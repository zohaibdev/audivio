import { LegalPage, type LegalSection } from '../../components/public/LegalPage';

/**
 * Privacy policy (§57).
 *
 * Written from what this application actually does — the data flows below
 * were read off the schema and the services, not lifted from a template.
 * A policy that describes a generic product is worse than none: it tells
 * the reader nothing true and gives the operator false comfort.
 *
 * It still needs review by someone qualified before launch, and the
 * placeholders (contact address, company details, jurisdiction) have to be
 * filled in. Those are marked plainly rather than invented.
 */
const sections: LegalSection[] = [
  {
    heading: 'What we collect',
    body: ['The data we hold falls into four groups, and each exists for a stated reason:'],
    bullets: [
      'Account details — your name, email address, and a hashed password. Passwords are stored as bcrypt hashes and cannot be read back by us.',
      'Websites and audits — the URLs you submit, the pages we crawl from them, the findings we record, and mobile and desktop screenshots captured during the crawl.',
      'Consultation requests — the name, email, optional phone number and message you send, plus the services you asked about and the findings you were viewing.',
      'Technical data — IP addresses, used to enforce rate limits, to track free anonymous audits, and to record who opened a shared report link.',
    ],
  },
  {
    heading: 'What we do not collect',
    body: [
      'We do not use advertising trackers or third-party analytics cookies. We do not sell, rent, or share your personal data with data brokers.',
      'We do not store payment card details. Where paid plans are offered, payment is handled by a payment provider and card data never reaches our servers.',
    ],
  },
  {
    heading: 'What we send to third parties, and what we do not',
    body: [
      'Running an audit involves a small number of external services. In each case we send the minimum required:',
    ],
    bullets: [
      'AI providers (Anthropic, OpenAI, Google) receive extracted, structured summaries of a page — headings, link and form counts, and similar signals. We do not send raw page HTML, and we do not send your account details.',
      'Google PageSpeed Insights receives the URL being audited, where a key is configured, in order to return performance measurements.',
      'Email delivery, where configured, receives the address and content of messages we send you.',
    ],
  },
  {
    heading: 'Websites you audit',
    body: [
      'We crawl the URL you submit and store what we find: page content signals, response headers, timings, and screenshots. Screenshots are stored privately and are served only through short-lived signed links to people entitled to see that report.',
      'Only submit websites you own or are authorised to audit. We crawl at a modest rate and identify ourselves in the user agent, but permission is yours to hold, not ours to assume.',
    ],
  },
  {
    heading: 'Shared reports',
    body: [
      'A shared report link contains a random token. We store only a hash of that token and, where you set one, a hash of the password — neither can be reconstructed from our database.',
      'We record when a shared link is opened, when an incorrect password is entered, and the IP address in each case, so the owner of a report can see if a link has reached the wrong person. Revoking a link takes effect immediately.',
    ],
  },
  {
    heading: 'How long we keep it',
    body: [
      'Account data is kept while your account exists. Audits, their findings and screenshots are kept so you can compare results over time.',
      'Deleting a website removes its audits, findings and screenshots along with it. If you would like your account and all associated data removed, contact us and we will do it.',
    ],
  },
  {
    heading: 'Your rights',
    body: [
      'You can ask us for a copy of the personal data we hold about you, ask us to correct it, or ask us to delete it. Depending on where you live, you may have additional rights under laws such as the GDPR or the CCPA.',
      'To exercise any of these, contact us using the details below. We will not charge you for a reasonable request.',
    ],
  },
  {
    heading: 'Security',
    body: [
      'Passwords are hashed with bcrypt and share tokens with SHA-256. API credentials for external providers are encrypted at rest. Screenshots and report files are stored outside the public web root and served only through authorised, signed URLs.',
      'No system is perfectly secure, and we do not claim otherwise. If you believe you have found a vulnerability, please tell us before disclosing it publicly.',
    ],
  },
  {
    heading: 'Contact',
    body: [
      'Questions about this policy, or a request about your data, can be sent through our contact page. [Add your registered company name, postal address, and data-protection contact here before launch.]',
    ],
  },
];

export function PrivacyPage() {
  return (
    <LegalPage
      slug="privacy"
      title="Privacy Policy"
      updated="5 September 2026"
      intro="This policy describes what Audivio collects, why, and what we do with it. It is written to describe how this product actually works rather than to cover every eventuality in general terms."
      sections={sections}
    />
  );
}
