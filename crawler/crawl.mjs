#!/usr/bin/env node
import { chromium } from 'playwright';
import { assertHostnameIsPublic, SsrfError } from './lib/ssrf.mjs';
import { extractPageData } from './lib/extract.mjs';

class CrawlFailure extends Error {}

function parseArgs(argv) {
  const args = {};
  for (const arg of argv) {
    const match = arg.match(/^--([^=]+)=(.*)$/);
    if (match) args[match[1]] = match[2];
  }
  return args;
}

async function run() {
  const args = parseArgs(process.argv.slice(2));
  const url = args.url;
  const pageTimeoutMs = Number(args.timeout ?? 30) * 1000;
  const screenshotPath = args['screenshot-path'] || null;
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
      viewport: { width: 1366, height: 900 },
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

    const startedAt = Date.now();
    let response;
    try {
      response = await page.goto(url, { waitUntil: 'networkidle', timeout: pageTimeoutMs });
    } catch (error) {
      throw new CrawlFailure(`Navigation failed: ${error.message}`);
    }
    const loadTimeMs = Date.now() - startedAt;

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

    if (screenshotPath) {
      await page.screenshot({ path: screenshotPath, fullPage: false });
    }

    return {
      url: response.url(),
      httpStatus: response.status(),
      headers: response.headers(),
      redirectChain,
      loadTimeMs,
      screenshotPath,
      ...extracted,
    };
  } finally {
    await browser.close();
  }
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
