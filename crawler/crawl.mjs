#!/usr/bin/env node
import { createRequire } from 'node:module';
import { chromium } from 'playwright';
import { assertHostnameIsPublic, SsrfError } from './lib/ssrf.mjs';
import { extractPageData } from './lib/extract.mjs';

const require = createRequire(import.meta.url);
const axeCorePath = require.resolve('axe-core/axe.min.js');

class CrawlFailure extends Error {}

// Must match App\Enums\ScreenshotViewport on the PHP side — both capture
// (here) and storage metadata (Screenshot model) need the same consistent
// dimensions for screenshots to be comparable page to page and audit to
// audit.
const DESKTOP_VIEWPORT = { width: 1366, height: 900 };
const MOBILE_VIEWPORT = { width: 390, height: 844 };

function parseArgs(argv) {
  const args = {};
  for (const arg of argv) {
    const match = arg.match(/^--([^=]+)=(.*)$/);
    if (match) args[match[1]] = match[2];
  }
  return args;
}

// A screenshot failure (e.g. a page that hangs mid-render) must not fail
// the whole page crawl — it's an enhancement on top of the base
// extraction, not required, same contract as the accessibility scan below.
async function captureScreenshot(page, path) {
  try {
    await page.screenshot({ path, fullPage: false });

    return path;
  } catch {
    return null;
  }
}

async function run() {
  const args = parseArgs(process.argv.slice(2));
  const url = args.url;
  const pageTimeoutMs = Number(args.timeout ?? 30) * 1000;
  const screenshotDir = args['screenshot-dir'] || null;
  const maxFileSizeBytes = Number(args['max-file-size-mb'] ?? 10) * 1024 * 1024;
  const maxRedirects = Number(args['max-redirects'] ?? 5);

  if (!url) throw new CrawlFailure('Missing required --url argument');

  let initialHostname;
  try {
    initialHostname = new URL(url).hostname;
  } catch {
    throw new CrawlFailure(`Invalid URL: ${url}`);
  }

  try {
    await assertHostnameIsPublic(initialHostname);
  } catch (error) {
    throw new CrawlFailure(error instanceof SsrfError ? error.message : `SSRF check failed: ${error.message}`);
  }

  const browser = await chromium.launch({ headless: true });

  try {
    const context = await browser.newContext({
      viewport: DESKTOP_VIEWPORT,
      userAgent: args['user-agent'] || 'AudivioBot/1.0 (+https://audivio.example/bot)',
    });
    const page = await context.newPage();

    // Re-validate EVERY request the page makes — the initial load, every
    // redirect hop, every subresource, and every iframe. Defense against
    // DNS rebinding and redirect-based SSRF, since the Laravel side can
    // only validate the URL it was given up front.
    //
    // Subresources are checked too, not just main-frame navigations: a
    // hostile page can point an <img>, a <script>, or an iframe at an
    // internal address, and although the crawler never reads those bodies
    // back, the request still reaches the internal service and still acts
    // on it. Blind SSRF is SSRF.
    //
    // Results are memoized per hostname for the lifetime of the page —
    // a page has hundreds of subresources across a handful of hosts, and
    // a DNS lookup per request would dominate the crawl time. The cache is
    // per page load, so it never outlives the navigation it protects.
    const hostVerdicts = new Map();
    const isHostAllowed = async (hostname) => {
      if (!hostVerdicts.has(hostname)) {
        hostVerdicts.set(
          hostname,
          assertHostnameIsPublic(hostname).then(
            () => true,
            () => false,
          ),
        );
      }

      return hostVerdicts.get(hostname);
    };

    let redirectsFollowed = 0;

    await page.route('**/*', async (route) => {
      const request = route.request();

      let hostname;
      try {
        hostname = new URL(request.url()).hostname;
      } catch {
        // A URL the parser rejects has no host to verify, so it cannot be
        // shown to be safe.
        await route.abort('blockedbyclient');
        return;
      }

      // data:/blob:/about: URLs have no host and never leave the browser.
      if (hostname === '') {
        await route.continue();
        return;
      }

      const isMainNavigation = request.isNavigationRequest() && request.frame() === page.mainFrame();

      // Bound the redirect chain ourselves. Chromium's own limit is ~20,
      // far looser than the crawler's configured budget, and a long chain
      // is how a target stalls or outlasts a one-shot check.
      if (isMainNavigation && request.redirectedFrom()) {
        redirectsFollowed++;
        if (redirectsFollowed > maxRedirects) {
          await route.abort('blockedbyclient');
          return;
        }
      }

      if (!(await isHostAllowed(hostname))) {
        await route.abort('blockedbyclient');
        return;
      }

      await route.continue();
    });

    // Record every response so the performance report can break page
    // weight down by resource type instead of guessing from the HTML size
    // alone. Attached BEFORE goto() so nothing loaded during navigation is
    // missed. Sizes come from request.sizes() (real transferred bytes via
    // CDP), not the content-length header, which is absent on chunked and
    // wrong on compressed responses.
    const resources = [];
    const pageHost = new URL(url).hostname;

    page.on('response', (res) => {
      const req = res.request();
      let host = null;
      try {
        host = new URL(res.url()).hostname;
      } catch {
        // data:/blob: URLs have no host — they're same-document by
        // definition, so treat them as first-party rather than dropping.
      }

      // Headers are read here rather than after load: they are available
      // synchronously on the response event, and the object may not be
      // retrievable later (section 45).
      let headers = {};
      try {
        headers = res.headers();
      } catch {
        headers = {};
      }

      resources.push({
        url: res.url().slice(0, 500),
        type: req.resourceType(),
        status: res.status(),
        isThirdParty: host !== null && host !== pageHost,
        // Caching and compression are two of the reported categories, and
        // both are decided entirely by response headers. Stored raw and
        // judged on the PHP side, so the rules can change without
        // re-crawling.
        cacheControl: headers['cache-control'] ?? null,
        contentEncoding: headers['content-encoding'] ?? null,
        // Filled in after load; sizes() is only reliable once the
        // response body has finished streaming.
        request: req,
      });
    });

    const startedAt = Date.now();
    let response;
    try {
      response = await page.goto(url, { waitUntil: 'networkidle', timeout: pageTimeoutMs });
    } catch (error) {
      throw new CrawlFailure(`Navigation failed: ${error.message}`);
    }
    const loadTimeMs = Date.now() - startedAt;

    const performanceMetrics = await collectPerformanceMetrics(page, response, resources);

    if (!response) {
      throw new CrawlFailure('No response received from navigation');
    }

    // Content-Length lets an oversized response be rejected from the
    // header alone, but it is absent on chunked responses and describes
    // the compressed size on encoded ones — so the transferred size CDP
    // actually measured is checked as well. Without the second check, a
    // chunked response advertising no length bypasses the limit entirely.
    const contentLength = Number(response.headers()['content-length'] ?? 0);
    if (contentLength > maxFileSizeBytes) {
      throw new CrawlFailure(`Response exceeds max file size (${contentLength} bytes)`);
    }

    let transferredBytes = 0;
    try {
      const sizes = await response.request().sizes();
      transferredBytes = (sizes.responseBodySize ?? 0) + (sizes.responseHeadersSize ?? 0);
    } catch {
      transferredBytes = 0;
    }

    if (transferredBytes > maxFileSizeBytes) {
      throw new CrawlFailure(`Response exceeds max file size (${transferredBytes} bytes)`);
    }

    // Each hop as {url, status}: a 301 and a 302 are the same shape to a
    // browser and completely different facts to a search engine, so the
    // chain has to carry the status it was served with (section 44).
    // Entries were bare URL strings before that; the PHP side still reads
    // both shapes, since stored audits keep whatever they recorded.
    const redirectChain = [];
    let current = response.request();
    while (current.redirectedFrom()) {
      current = current.redirectedFrom();
      let hopStatus = null;
      try {
        hopStatus = (await current.response())?.status() ?? null;
      } catch {
        // A hop whose response is no longer retrievable is still a real
        // hop — recorded with a null status rather than dropped.
        hopStatus = null;
      }
      redirectChain.unshift({ url: current.url(), status: hopStatus });
    }

    const extracted = await page.evaluate(extractPageData);

    let accessibilityViolations = [];
    let accessibilityPasses = [];
    let accessibilityIncomplete = [];
    try {
      await page.addScriptTag({ path: axeCorePath });
      const axeResults = await page.evaluate(() => window.axe.run());

      // Kept deliberately bounded: this whole structure is stored as JSON
      // on `audit_pages`, and a page with hundreds of contrast failures
      // would otherwise write a multi-megabyte row per page.
      const MAX_NODES = 8;
      const MAX_HTML = 400;

      const truncate = (value, max) =>
        typeof value === 'string' && value.length > max ? `${value.slice(0, max)}…` : value;

      accessibilityViolations = axeResults.violations.map((v) => ({
        id: v.id,
        impact: v.impact,
        description: v.description,
        help: v.help,
        helpUrl: v.helpUrl,
        // WCAG success criteria and conformance level, straight from axe's
        // own rule tags (wcag2a, wcag143, best-practice...). Carried
        // through rather than re-derived on the PHP side: axe is the
        // authority on which criterion its own rule tests.
        tags: v.tags ?? [],
        nodeCount: v.nodes.length,
        // Per-node detail is what turns "contrast fails" into a finding
        // someone can act on: which element, where in the DOM, and axe's
        // own explanation of why that specific node failed.
        nodes: v.nodes.slice(0, MAX_NODES).map((n) => ({
          target: n.target,
          html: truncate(n.html, MAX_HTML),
          failureSummary: truncate(n.failureSummary, MAX_HTML),
        })),
        targets: v.nodes.slice(0, 5).map((n) => n.target),
      }));

      // Rule IDs only. What matters for the report is *how many* checks
      // passed and which - not their node lists, which would dwarf the
      // violations they are meant to give context to.
      accessibilityPasses = (axeResults.passes ?? []).map((p) => p.id);
      accessibilityIncomplete = (axeResults.incomplete ?? []).map((p) => p.id);
    } catch (error) {
      // Accessibility scan failing shouldn't fail the whole page crawl —
      // it's an enhancement on top of the base extraction, not required.
      accessibilityViolations = null;
      accessibilityPasses = null;
      accessibilityIncomplete = null;
    }

    // Desktop viewport is already active from context creation — capture
    // it first, then resize in-place for mobile rather than re-navigating
    // (a second full page load per page would double crawl time/cost for
    // no benefit, since the DOM/extraction already happened above and
    // doesn't need to be redone per viewport).
    let screenshotDesktopPath = null;
    let screenshotMobilePath = null;

    if (screenshotDir) {
      screenshotDesktopPath = await captureScreenshot(page, `${screenshotDir}/desktop.png`);

      await page.setViewportSize(MOBILE_VIEWPORT);
      screenshotMobilePath = await captureScreenshot(page, `${screenshotDir}/mobile.png`);
    }

    return {
      url: response.url(),
      httpStatus: response.status(),
      headers: response.headers(),
      redirectChain,
      loadTimeMs,
      screenshotDesktopPath,
      screenshotMobilePath,
      accessibilityViolations,
      accessibilityPasses,
      accessibilityIncomplete,
      performanceMetrics,
      ...extracted,
    };
  } finally {
    await browser.close();
  }
}

/** How many sample URLs each summary carries; the rows are stored as JSON. */
const MAX_RESOURCE_SAMPLES = 10;

/**
 * Static assets served without a usable cache lifetime (section 45).
 *
 * "Usable" means a positive `max-age` or `immutable`. `no-store`,
 * `no-cache`, `max-age=0` and a missing header all mean the browser
 * re-fetches on the next visit, which is the thing worth reporting.
 *
 * Only cacheable *kinds* of resource are counted: an HTML document is
 * usually meant not to be cached, and flagging it would be advice to
 * break the site.
 */
function cachingSummary(stats) {
  const CACHEABLE_BUCKETS = ['js', 'css', 'image', 'font', 'media'];
  const considered = stats.filter(
    (r) => CACHEABLE_BUCKETS.includes(r.bucket) && r.status >= 200 && r.status < 300,
  );

  const uncached = considered.filter((r) => !hasUsableCacheLifetime(r.cacheControl));

  return {
    consideredCount: considered.length,
    cachedCount: considered.length - uncached.length,
    uncachedCount: uncached.length,
    uncachedBytes: uncached.reduce((sum, r) => sum + r.bytes, 0),
    uncachedSamples: uncached
      .sort((a, b) => b.bytes - a.bytes)
      .slice(0, MAX_RESOURCE_SAMPLES)
      .map((r) => ({ url: r.url, bytes: r.bytes, cacheControl: r.cacheControl })),
  };
}

function hasUsableCacheLifetime(cacheControl) {
  if (!cacheControl) return false;

  const value = cacheControl.toLowerCase();
  if (value.includes('no-store') || value.includes('no-cache')) return false;
  if (value.includes('immutable')) return true;

  const maxAge = /max-age=(\d+)/.exec(value);

  return maxAge !== null && Number(maxAge[1]) > 0;
}

/**
 * Text resources served without compression (section 45).
 *
 * Images, fonts and media are excluded: they are already compressed
 * formats, and gzipping a JPEG saves nothing while costing CPU.
 */
function compressionSummary(stats) {
  const COMPRESSIBLE_BUCKETS = ['js', 'css', 'document'];
  const considered = stats.filter(
    (r) => COMPRESSIBLE_BUCKETS.includes(r.bucket) && r.status >= 200 && r.status < 300,
  );

  // Very small responses are excluded: compression has a per-response
  // overhead, and servers routinely skip it below ~1KB for good reason.
  const uncompressed = considered.filter((r) => !r.contentEncoding && r.bytes > 1024);

  return {
    consideredCount: considered.length,
    compressedCount: considered.filter((r) => Boolean(r.contentEncoding)).length,
    uncompressedCount: uncompressed.length,
    uncompressedBytes: uncompressed.reduce((sum, r) => sum + r.bytes, 0),
    uncompressedSamples: uncompressed
      .sort((a, b) => b.bytes - a.bytes)
      .slice(0, MAX_RESOURCE_SAMPLES)
      .map((r) => ({ url: r.url, bytes: r.bytes })),
  };
}

/**
 * Aggregates the recorded responses into the per-resource-type breakdown
 * the performance report needs, plus browser-reported navigation timings.
 *
 * Every number here is measured. Anything the browser or CDP does not
 * report comes back null rather than 0, so the report can say
 * "unavailable" instead of implying a real measurement of zero.
 */
async function collectPerformanceMetrics(page, response, resources) {
  // Map Playwright resource types onto the buckets the report groups by.
  const bucketFor = (type) => {
    if (type === 'stylesheet') return 'css';
    if (type === 'script') return 'js';
    if (type === 'image') return 'image';
    if (type === 'font') return 'font';
    if (type === 'document') return 'document';
    if (type === 'media') return 'media';
    return 'other';
  };

  const byType = {};
  let totalBytes = 0;
  let thirdPartyBytes = 0;
  let thirdPartyRequests = 0;

  // Per-resource facts the caching and compression summaries are built
  // from, collected in the same pass that sizes the responses.
  const resourceStats = [];

  for (const entry of resources) {
    let bytes = 0;
    try {
      const sizes = await entry.request.sizes();
      bytes = (sizes.responseBodySize ?? 0) + (sizes.responseHeadersSize ?? 0);
    } catch {
      // Served from cache, aborted, or otherwise has no CDP size record.
      // Counted as a request but contributes no bytes, rather than being
      // dropped (which would understate the request count).
      bytes = 0;
    }

    const bucket = bucketFor(entry.type);
    byType[bucket] ??= { requests: 0, bytes: 0 };
    byType[bucket].requests += 1;
    byType[bucket].bytes += bytes;
    totalBytes += bytes;

    if (entry.isThirdParty) {
      thirdPartyBytes += bytes;
      thirdPartyRequests += 1;
    }

    resourceStats.push({
      url: entry.url,
      bucket,
      bytes,
      status: entry.status,
      cacheControl: entry.cacheControl,
      contentEncoding: entry.contentEncoding,
    });
  }

  // Navigation timings straight from the browser. TTFB is responseStart
  // relative to requestStart on the main document.
  let timings = null;
  try {
    timings = await page.evaluate(() => {
      const nav = performance.getEntriesByType('navigation')[0];
      if (!nav) return null;
      return {
        ttfbMs: nav.responseStart > 0 ? Math.round(nav.responseStart - nav.requestStart) : null,
        domContentLoadedMs: nav.domContentLoadedEventEnd > 0 ? Math.round(nav.domContentLoadedEventEnd) : null,
        loadEventMs: nav.loadEventEnd > 0 ? Math.round(nav.loadEventEnd) : null,
      };
    });
  } catch {
    timings = null;
  }

  // First Contentful Paint, if the browser recorded it.
  let fcpMs = null;
  try {
    fcpMs = await page.evaluate(() => {
      const paint = performance.getEntriesByName('first-contentful-paint')[0];
      return paint ? Math.round(paint.startTime) : null;
    });
  } catch {
    fcpMs = null;
  }

  let domNodes = null;
  try {
    domNodes = await page.evaluate(() => document.getElementsByTagName('*').length);
  } catch {
    domNodes = null;
  }

  const compressed = (response.headers()['content-encoding'] ?? '') !== '';

  return {
    totalRequests: resources.length,
    totalTransferBytes: totalBytes,
    byType,
    thirdPartyRequests,
    thirdPartyBytes,
    domNodes,
    fcpMs,
    ttfbMs: timings?.ttfbMs ?? null,
    domContentLoadedMs: timings?.domContentLoadedMs ?? null,
    loadEventMs: timings?.loadEventMs ?? null,
    documentCompressed: compressed,
    caching: cachingSummary(resourceStats),
    compression: compressionSummary(resourceStats),
  };
}

run()
  .then((result) => {
    process.stdout.write(JSON.stringify(result) + '\n');
    process.exit(0);
  })
  .catch((error) => {
    const message = error instanceof CrawlFailure ? error.message : `Unhandled crawler error: ${error.message}`;
    process.stdout.write(JSON.stringify({ error: message }) + '\n');
    process.exit(1);
  });
