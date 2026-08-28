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
      loading: el.getAttribute('loading'),
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
  const html = document.documentElement.outerHTML;

  return {
    title: document.title || null,
    metaDescription,
    canonicalUrl: canonical ? abs(canonical) : null,
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
