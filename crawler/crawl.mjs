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

    // Re-validate every navigation (initial load + every redirect hop) —
    // defense against DNS rebinding and redirect-based SSRF, since the
    // Laravel side can only validate the URL it was given up front.
    await page.route('**/*', async (route) => {
      const request = route.request();
      if (request.isNavigationRequest() && request.frame() === page.mainFrame()) {
        try {
          await assertHostnameIsPublic(new URL(request.url()).hostname);
        } catch {
          await route.abort('blockedbyclient');
          return;
        }
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

      resources.push({
        url: res.url().slice(0, 500),
        type: req.resourceType(),
        status: res.status(),
        isThirdParty: host !== null && host !== pageHost,
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

    const contentLength = Number(response.headers()['content-length'] ?? 0);
    if (contentLength > maxFileSizeBytes) {
      throw new CrawlFailure(`Response exceeds max file size (${contentLength} bytes)`);
    }

    const redirectChain = [];
    let current = response.request();
    while (current.redirectedFrom()) {
      current = current.redirectedFrom();
      redirectChain.unshift(current.url());
    }

    const extracted = await page.evaluate(extractPageData);

    let accessibilityViolations = [];
    try {
      await page.addScriptTag({ path: axeCorePath });
      const axeResults = await page.evaluate(() => window.axe.run());
      accessibilityViolations = axeResults.violations.map((v) => ({
        id: v.id,
        impact: v.impact,
        description: v.description,
        help: v.help,
        helpUrl: v.helpUrl,
        nodeCount: v.nodes.length,
        targets: v.nodes.slice(0, 5).map((n) => n.target),
      }));
    } catch (error) {
      // Accessibility scan failing shouldn't fail the whole page crawl —
      // it's an enhancement on top of the base extraction, not required.
      accessibilityViolations = null;
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
      performanceMetrics,
      ...extracted,
    };
  } finally {
    await browser.close();
  }
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
