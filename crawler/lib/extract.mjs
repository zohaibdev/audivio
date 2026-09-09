/**
 * Runs inside the page context via page.evaluate(). Must be a single
 * self-contained function — no closures over outer scope, no imports.
 */
export function extractPageData() {
  const CTA_KEYWORDS = [
    // 'add to basket'/'add to bag' are the standard UK/EU wording — without
    // them, product pages on those stores yield no purchase CTA at all,
    // which made ProductPageDetector miss real product pages entirely.
    'buy', 'shop now', 'add to cart', 'add to basket', 'add to bag', 'checkout', 'sign up', 'sign in', 'log in',
    'get started', 'start free', 'start trial', 'book', 'contact', 'call',
    'subscribe', 'download', 'learn more', 'request a', 'talk to', 'schedule',
    'demo', 'quote', 'apply now', 'join', 'register',
  ];

  const abs = (href) => {
    try {
      return new URL(href, document.baseURI).href;
    } catch {
      return null;
    }
  };

  const headings = [];
  document.querySelectorAll('h1, h2, h3, h4, h5, h6').forEach((el) => {
    headings.push({ level: Number(el.tagName[1]), text: el.textContent.trim().slice(0, 500) });
  });

  const links = [];
  document.querySelectorAll('a[href]').forEach((el) => {
    const href = abs(el.getAttribute('href'));
    if (!href) return;
    links.push({
      href,
      text: el.textContent.trim().slice(0, 200),
      rel: el.getAttribute('rel'),
      target: el.getAttribute('target'),
    });
  });

  const images = [];
  document.querySelectorAll('img').forEach((el) => {
    images.push({
      src: abs(el.getAttribute('src') || ''),
      alt: el.getAttribute('alt'),
      width: el.naturalWidth || null,
      height: el.naturalHeight || null,
      // The *declared* dimensions, which are what reserve layout space.
      // Distinct from the natural size above: an image can render fine and
      // still shift the page because the markup never said how big it is.
      declaredWidth: el.getAttribute('width'),
      declaredHeight: el.getAttribute('height'),
      loading: el.getAttribute('loading'),
      hasSrcset: el.hasAttribute('srcset'),
    });
  });

  const forms = [];
  document.querySelectorAll('form').forEach((el) => {
    const fields = [];
    el.querySelectorAll('input, select, textarea').forEach((field) => {
      fields.push({
        tag: field.tagName.toLowerCase(),
        type: field.getAttribute('type'),
        name: field.getAttribute('name'),
        required: field.hasAttribute('required'),
      });
    });
    forms.push({
      action: abs(el.getAttribute('action') || ''),
      method: (el.getAttribute('method') || 'get').toLowerCase(),
      fields,
    });
  });

  const structuredData = [];
  document.querySelectorAll('script[type="application/ld+json"]').forEach((el) => {
    try {
      structuredData.push(JSON.parse(el.textContent));
    } catch {
      // malformed JSON-LD — skip rather than fail the whole extraction
    }
  });

  const ctaElements = [];
  document.querySelectorAll('a, button, [role="button"], input[type="submit"]').forEach((el) => {
    const text = (el.textContent || el.getAttribute('value') || '').trim();
    if (!text || text.length > 60) return;
    const lower = text.toLowerCase();
    if (CTA_KEYWORDS.some((kw) => lower.includes(kw))) {
      ctaElements.push({
        tag: el.tagName.toLowerCase(),
        text,
        href: el.tagName === 'A' ? abs(el.getAttribute('href') || '') : null,
      });
    }
  });

  const metaDescription = document.querySelector('meta[name="description"]')?.getAttribute('content') ?? null;
  const canonical = document.querySelector('link[rel="canonical"]')?.getAttribute('href');

  // Indexability directives (section 44). `robots` is the generic
  // directive; `googlebot` overrides it for Google specifically, so both
  // are captured rather than merged — a page that is `index` generally
  // and `noindex` for googlebot is a real and reportable state.
  const metaRobots = document.querySelector('meta[name="robots" i]')?.getAttribute('content') ?? null;
  const metaGooglebot = document.querySelector('meta[name="googlebot" i]')?.getAttribute('content') ?? null;

  // Social cards. Collected as whole maps rather than a fixed handful of
  // properties: which tags a page *should* carry depends on its og:type,
  // and that is a judgement for the audit module, not the extractor.
  const openGraph = {};
  document.querySelectorAll('meta[property^="og:" i]').forEach((el) => {
    const property = el.getAttribute('property')?.toLowerCase();
    const content = el.getAttribute('content');
    if (!property || content === null) return;
    // First occurrence wins, matching how crawlers read repeated tags.
    if (!(property in openGraph)) openGraph[property] = content.slice(0, 1000);
  });

  const twitterCard = {};
  document.querySelectorAll('meta[name^="twitter:" i]').forEach((el) => {
    const name = el.getAttribute('name')?.toLowerCase();
    const content = el.getAttribute('content');
    if (!name || content === null) return;
    if (!(name in twitterCard)) twitterCard[name] = content.slice(0, 1000);
  });

  // og:image and twitter:image are URLs that may be relative in the
  // markup; a preview renderer needs the absolute form.
  if (openGraph['og:image']) openGraph['og:image'] = abs(openGraph['og:image']) ?? openGraph['og:image'];
  if (twitterCard['twitter:image']) {
    twitterCard['twitter:image'] = abs(twitterCard['twitter:image']) ?? twitterCard['twitter:image'];
  }

  const html = document.documentElement.outerHTML;

  return {
    title: document.title || null,
    metaDescription,
    canonicalUrl: canonical ? abs(canonical) : null,
    metaRobots,
    metaGooglebot,
    openGraph,
    twitterCard,
    headings,
    links,
    images,
    forms,
    structuredData,
    ctaElements,
    htmlSizeBytes: html.length,
    // Bounded sample for deterministic platform-signature matching
    // (meta generator tag, cdn.shopify.com, wp-content, etc.) — not
    // persisted, only used transiently by App\Services\Crawler\PlatformDetector.
    htmlSample: html.slice(0, 200000),
  };
}
