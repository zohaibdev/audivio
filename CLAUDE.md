# Audivio — Project Brief & Architecture

AI Website Audit SaaS: crawls websites, analyzes technical and business
factors, generates scores and recommendations, and converts users into SaaS
customers or consulting leads.

## Stack

| Layer | Choice |
|---|---|
| Backend | Laravel 12 (PHP 8.2) |
| Frontend | React 19 + TypeScript (Vite), separate SPA — not Inertia |
| Database | MySQL |
| Queue | Laravel Queue on Redis (`predis` client — no `phpredis` extension on this Windows dev box) |
| Queue monitoring | Laravel Horizon **in production only** — requires `pcntl`/`posix`, unavailable on Windows. Use `php artisan queue:work redis` for local dev. |
| Crawler | Playwright + Chromium |
| Accessibility | axe-core |
| Performance | Google PageSpeed Insights API + custom rules |
| AI | Provider abstraction over Claude / OpenAI / Gemini (`App\Contracts\AiProvider`) |
| Auth | Sanctum, Bearer token (not cookie/SPA-stateful) — frontend and backend are decoupled and may not share a parent domain |

## Repo layout

Monorepo: `backend/` (Laravel API) and `frontend/` (React SPA), independent
deploys, communicate only over the JSON API under `/api`.

## Audit modules

`performance`, `accessibility`, `seo`, `ecommerce`, `landing_page`,
`conversion`, `aio`. Each module must stay independent (own service class,
own job, own scoring) so modules can be added/changed without touching others.

**AIO ("AI Search Readiness")**: 0-100 score for how clearly AI-driven search
/ answer systems can parse site content. Never claim the score guarantees
ranking or visibility in ChatGPT, Google AI Overviews, Gemini, Perplexity, etc.

## AI strategy

Rule-first, AI only when needed:
- Deterministic (no AI call): missing alt/title/canonical, broken links, HTTP
  status, image dimensions, performance metrics, accessibility violations.
- AI-assisted: UX interpretation, CRO analysis, content quality, AIO analysis,
  business impact, recommendation copy.
- Never send full raw HTML to an AI provider — extract relevant structured
  context first. Cache repeated analysis, avoid duplicate calls, prefer
  deterministic rules whenever a rule can decide it.

## Crawler safety (SSRF)

Every crawl target must be validated before fetch: block localhost, private
IP ranges, and internal networks; enforce request/page/crawl timeouts,
redirect limits, file size limits, crawl depth limits, and rate limiting.
Same-domain only. This is non-negotiable — the crawler accepts arbitrary
user-supplied URLs.

## Scoring & limits

Category scores + overall score, 0-100, severities
`critical | high | medium | low | passed`. Scoring weights and plan limits
(page counts, audit frequency, report visibility %) must be configurable
(DB-backed `plans` table / config), never hardcoded in audit logic.

## Free vs paid

- Anonymous: 1 audit, max 5 pages, ~60% of report visible.
- Registered free: 1 full recrawl, 1 audit/day, max 25 pages.
- Paid: higher page limits, full reports, PDF export, audit history,
  scheduled audits, advanced AI analysis, daily audits.

## Lead generation

CTAs "Talk to a Consultant" / "Request an Audit Review" feed a `leads` table
with status pipeline `new → contacted → qualified → converted → lost`.

## Development phases

Work phase by phase — do not build the whole app at once.

1. Days 1-4: **Foundation** (this phase)
2. Days 5-12: Crawler
3. Days 13-21: Core Auditors (performance, accessibility, SEO)
4. Days 22-31: AI Auditors (ecommerce, landing page, conversion, AIO, AI provider implementations)
5. Days 32-36: Report System
6. Days 37-40: User System (full auth, subscriptions, plans)
7. Days 41-46: Admin and CMS
8. Days 47-50: PDF and Scheduling
9. Days 51-58: Testing and Security
10. Days 59-60: Production

### Phase 1 (Foundation) — done

- Git repo initialized at root.
- `backend/`: Laravel 12 installed, Sanctum + `install:api` scaffolding,
  Predis, MySQL/Redis `.env` configured, CORS config made env-driven
  (`CORS_ALLOWED_ORIGINS`), base folder skeleton (`app/Contracts`,
  `app/DTOs`, `app/Enums`, `app/Services/{AI,Crawler,Audit}`,
  `app/Jobs/{Crawl,Audit,Report}`, `app/Http/Responses`), `AiProvider`
  contract + `AiResponse` DTO + `AiProviderName` enum (no concrete provider
  implementations yet — that's Phase 4), `ApiResponse` helper for consistent
  API responses.
- `frontend/`: Vite React-TS app, `react-router-dom` + `axios`, base folder
  skeleton (`src/{api,components,pages,hooks,types,lib}`), typed API client
  with Bearer token interceptor.
- Horizon deliberately **not** installed locally (Windows platform
  limitation — see Stack table). Add it when provisioning the Linux
  production/staging environment.

### Phase 2 (Crawler) — done

- **Schema**: `websites`, `audits`, `crawl_jobs`, `crawl_urls`, `audit_pages`
  migrations + models, all explicitly `InnoDB` (see below), real FK
  constraints with cascade/null-on-delete.
- **Node/Playwright bridge**: `crawler/` is a third top-level directory
  (sibling to `backend/`/`frontend/`) — PHP has no mature Playwright
  binding, so `crawler/crawl.mjs` is invoked as a subprocess per page via
  `App\Services\Crawler\PlaywrightCrawlerClient` (Symfony Process, array
  args — never shell-interpolated), printing one JSON object to stdout.
  `crawler/lib/extract.mjs` does the DOM extraction (headings, links,
  images, forms, JSON-LD, heuristic CTA detection); `crawler/lib/ssrf.mjs`
  re-validates every navigation's resolved IP from inside the browser
  process itself.
- **SSRF protection is layered, not single-point**: `App\Services\Crawler\UrlSecurityValidator`
  validates scheme/port/credentials/query-length and resolves+checks the
  hostname's IPs (via PHP's `FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE`)
  before a URL is ever handed to Node; `crawl.mjs` independently re-resolves
  and re-checks on the initial navigation *and every redirect hop* via
  `page.route()`, because DNS can rebind between the PHP check and the
  browser's actual connection, and redirects can point anywhere. Both
  layers block localhost, loopback, private/link-local ranges (including
  cloud metadata `169.254.169.254`), and non-http(s) schemes.
- **Discovery**: `RobotsTxtService` (parses `Disallow`/`Sitemap` directives)
  → `SitemapService` (handles sitemap indexes, same-domain filter, capped
  at 20 sub-sitemaps / 5000 URLs) → falls back to breadth-first same-domain
  link discovery from crawled pages (`CrawlOrchestrator::expandFrontier`)
  when a site has no sitemap. Both `RobotsTxtService` and `SitemapService`
  send an explicit `crawler.user_agent` header — some WAFs (hit this on
  wordpress.org) 403 Laravel's default Guzzle UA with none set.
- **Platform detection**: `PlatformDetector` runs on a bounded HTML sample
  (`htmlSample`, first 200KB) + response headers from the homepage crawl
  only — signature-matches Shopify/WordPress/WooCommerce/Webflow/Wix,
  falls back to `custom` (unrecognized `<meta name="generator">`) or
  `unknown` (no signal at all).
- **Jobs**: `CrawlWebsiteJob` (seeds the crawl via `CrawlOrchestrator::startCrawl`,
  dispatches `CrawlPageJob` per discovered URL) and `CrawlPageJob` (crawls
  one URL, expands the frontier, marks the `CrawlJob`/`Audit` completed once
  no pending/crawling URLs remain). Both carry a `RateLimited('crawler')`
  middleware backed by `crawler.rate_limit_per_second` (registered in
  `AppServiceProvider`).
- **Logging**: dedicated `crawler` and `ai` log channels (`config/logging.php`)
  — crawl failures log there with URL + error context, never just to the
  default channel.
- **Testing entry point**: `php artisan audit:crawl {url} --pages=N --wait`
  — creates a `Website`/`Audit` and dispatches the real queue pipeline.
  This is deliberately a CLI command, not a public API endpoint yet:
  there's no rate limiting or plan-limit enforcement in front of "crawl any
  URL" until Phase 6 (User System), and exposing that publicly first would
  make this server an open crawling proxy.
- **Known environment issue found & fixed**: this WAMP MySQL 5.7 install's
  `default_storage_engine` is `MyISAM`, which silently accepts (and
  ignores) foreign key clauses — cascades and referential integrity were
  no-ops. Fixed by declaring `$table->engine = 'InnoDB';` explicitly in
  every migration (Phase 1's included) rather than depending on server
  defaults — portable to any deployment target regardless of its MySQL
  config.

### Phase 3 (Core Auditors) — done

- **Schema**: `audit_issues` (findings, category+severity+code+message+
  recommendation+meta), `audit_scores` (one row per category + one
  `overall` row, with a `breakdown` json for transparency), plus
  `audit_pages.accessibility_violations` / `.performance_metrics` columns.
- **`App\Contracts\AuditModule`**: each category is an independent class
  (`category(): AuditCategory`, `run(Audit): AuditModuleResult`) that only
  reads already-crawled `AuditPage` data — modules never depend on each
  other. `App\Services\Audit\AuditModuleRegistry` is the single list of
  which modules run after a crawl; Phase 4's ecommerce/landing_page/
  conversion/aio modules just get added there, nothing else changes.
- **Scoring** (`config/audit_scoring.php`, `ScoreCalculator`): every module
  starts its category at 100 and deducts configured points per issue
  severity (critical/high/medium/low), worst-first, optionally capped at
  N deductions so a long tail of minor findings on a large site can't
  bottom a score out at 0. The overall score is a weighted average
  (`category_weights`) **renormalized over only the categories that have
  actually run** — an audit mid-way through Phase 4 rollout still gets a
  fair overall score instead of being diluted by categories that don't
  exist yet.
- **Accessibility**: axe-core runs *inside the same Playwright page load*
  in `crawler/crawl.mjs` (`page.addScriptTag` + `axe.run()`) rather than a
  second browser pass. `AccessibilityAuditModule` aggregates violations
  **by rule across all pages, not per-page-per-violation** — the same
  site-wide contrast issue on every page is one `AuditIssue`, not one per
  page, so score damage scales with distinct problems, not page count.
- **Performance**: `PageSpeedService` calls Google PSI, but only against
  `audit_scoring.performance.pagespeed_pages` pages (homepage by default)
  — it's a slow (10-30s), rate-limited external call, never run per
  crawled page. Combined with custom heuristics already available from
  the crawl (load time, HTML size, images missing explicit dimensions).
  Degrades gracefully (module still scores, just without PSI-derived
  issues) when `PAGESPEED_API_KEY` isn't set.
- **SEO**: fully deterministic against crawled data — title/meta-
  description presence and length, canonical presence, H1 count, images
  missing alt (aggregated per page), redirect chain length, duplicate
  titles across pages (site-wide, once), and broken internal links
  (best-effort: only flagged when the link target is itself a page we
  crawled and know returned 4xx/5xx — we don't fetch untouched targets
  just to check).
- **Pipeline wiring**: `CrawlPageJob`/`CrawlWebsiteJob` transition the
  audit to `analyzing` and dispatch one `RunAuditModuleJob` per registered
  module once crawling finishes; each one persists its issues/score, and
  whichever finishes last (order isn't deterministic) dispatches
  `CalculateScoreJob`, which computes the overall score and marks the
  audit `completed`.
- **Tests**: `tests/Unit/Services/Audit/` — `SeoAuditModuleTest`,
  `AccessibilityAuditModuleTest`, `ScoreCalculatorTest` (17 tests total)
  covering the deterministic rules and the shared scoring math.
- **Bug fixed during testing**: `CalculateScoreJob` used
  `pluck('score', 'category')` where `category` is enum-cast — using an
  enum object as an array key throws "Illegal offset type". Fixed with
  `mapWithKeys` to pull `->value` explicitly.

### Phase 4 (AI Auditors) — done

- **Concrete `AiProvider` implementations**: `ClaudeProvider` (Anthropic
  Messages API), `OpenAiProvider` (Chat Completions), `GeminiProvider`
  (Generative Language API, key as query param — that's Google's actual
  API contract, not a shortcut). `AiProviderFactory` resolves whichever is
  configured as `AI_DEFAULT_PROVIDER` (`config/ai.php`). All three throw
  `AiProviderException` on a missing key or non-2xx response; nothing
  crashes the caller.
- **`AiAnalysisService`**: the single entry point every AI-driven module
  calls. Hashes `(category, prompt, context)` and checks the `ai_analyses`
  table before calling a provider — a second identical call never spends
  tokens (verified: `Http::assertSentCount(1)` across two calls with
  identical input). A provider failure logs to the `ai` channel, records
  an error row (so failures are visible without re-triggering the same
  failing call), and returns `null` — callers fall back to
  deterministic-only results, the same pattern `PageSpeedService`
  established in Phase 3.
- **`AiIssueParser`**: shared, defensive parser for the JSON-array response
  shape every module's prompt asks for (`[{severity, code, message,
  recommendation}]`) — strips a markdown fence if present, falls back to
  `medium` severity on garbage input, and returns `[]` (never throws) on
  unparseable output.
- **`PageContextSummarizer`**: builds the *only* thing sent to an AI
  provider — title/headings/CTA text/form field types/structured-data
  types/image counts per page, capped at `audit_scoring.ai_context_pages`
  (default 5) pages per audit. Raw HTML never leaves the crawler.
- **Ecommerce**: skips the AI call entirely (not just its result) when a
  site shows no commerce signals (no Product schema, no purchase CTA, not
  on Shopify) — verified in testing that a non-ecommerce audit produces
  zero `ai_analyses` rows for that category, not just an ignored one.
  Otherwise flags missing Product schema / missing purchase CTA
  deterministically, then layers the AI's CRO-style findings on top.
- **Landing Page**: deterministic "no CTA anywhere" / "no heading at all"
  checks, then AI value-proposition/focus assessment.
- **Conversion**: deterministic form-friction check (too many required
  fields, `MAX_REQUIRED_FIELDS_BEFORE_FRICTION = 6`), then AI CRO findings.
- **AIO**: deterministic "no structured data anywhere" / "page with no
  heading structure" checks. The prompt has an explicit, hard constraint —
  it must not state or imply the score predicts/guarantees ranking or
  citation in ChatGPT, Google AI Overviews, Gemini, Perplexity, or any
  other AI search product; it may only describe structural clarity for
  machine parsing. This is enforced at the prompt level, not just in UI
  copy, so it holds regardless of which surface renders the result later.
- **Schema**: `ai_analyses` (audit_id, category, provider, context_hash,
  context, prompt, response, token counts, error) with a unique
  `(audit_id, category, context_hash)` cache key.
- **`AuditModuleRegistry`** now lists all 7 modules — nothing else needed
  to change in the crawl→analyze handoff; `RunAuditModuleJob`'s
  "dispatch `CalculateScoreJob` once every module is done" check already
  derived its required-categories list from the registry itself, so it
  picked up the new modules automatically.
- **Tests**: `tests/Unit/Services/AI/` (issue parser, all 3 providers via
  `Http::fake()`, `AiAnalysisService` caching/error/cache-disabled
  behavior) + `EcommerceAuditModuleTest`/`AioAuditModuleTest` covering the
  deterministic-only fallback path — 23 new tests, 40 total, all passing
  with no API keys configured (this dev environment has none set).
- Tested end-to-end against `example.com` with all 7 modules live: scores
  and weighted overall matched hand-computed expected values; AI calls
  correctly attempted, logged, and gracefully skipped without failing the
  audit.

Not built yet (do not build ahead of schedule): report generation, PDF
export, subscriptions/plans, admin, CMS, scheduling, the public "create
audit" API endpoint.

## MVP exclusions (do not build unless explicitly requested)

Shopify/WordPress/WooCommerce integrations, automated fixes, competitor
analysis, white label, public API, advanced agency dashboard, advanced team
management, complex billing.

## Working rules

- Inspect existing code before modifying it.
- Architecture before implementation; migrations before dependent models.
- Keep crawler logic out of controllers — service classes for audit engines,
  each audit module independent.
- AI calls only through the `AiProvider` abstraction.
- Never crawl synchronously — always via queue jobs.
- Scoring and plan limits configurable, never hardcoded; CMS content never
  hardcoded.
- Log crawl failures and AI failures.
- Protect every crawled URL against SSRF.
- Optimize AI token usage (extract-first, cache, avoid duplicate calls).
- Reusable React components, responsive UI, consistent API response shape
  (see `App\Http\Responses\ApiResponse`).
- Write tests for critical audit rules.
- Document architecture decisions (this file).
