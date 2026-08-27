# Audivio — Project Context

**Read this file before starting any development task.** It exists so
Claude Code doesn't need to re-read the whole repository to understand the
architecture, current status, and past decisions. After reading this,
inspect only the files/modules directly relevant to the requested change.

Update this file whenever architecture, schema, API behavior, business
logic, status, known bugs, or technical decisions change — see the
maintenance rule at the bottom.

---

## 1. Project overview

AI-driven website audit SaaS. Crawls a submitted website, runs deterministic
and AI-assisted audits across seven categories, produces a 0-100 overall
score with category breakdowns, issues, and recommendations, and converts
free/anonymous users into paying customers or consulting leads.

## 2. Product requirements

- **Audit categories**: `performance`, `accessibility`, `seo`, `ecommerce`,
  `landing_page`, `conversion`, `aio` ("AI Search Readiness").
- **AIO constraint (hard rule, enforced at the prompt level, not just UI
  copy)**: never claim the AIO score predicts or guarantees ranking,
  citation, or visibility in ChatGPT, Google AI Overviews, Gemini,
  Perplexity, or any other AI search product. It measures structural
  clarity for machine parsing only.
- **Free tiers** (limits belong in config/DB, never hardcoded in audit
  logic — see §12 for current state):
  - Anonymous: 1 audit, max 5 pages, ~60% of report visible.
  - Registered free: 1 full recrawl, 1 audit/day, max 25 pages.
  - Paid: higher page limits, full reports, PDF export, audit history,
    scheduled audits, advanced AI analysis, daily audits.
- **Lead generation**: CTAs "Talk to a Consultant" / "Request an Audit
  Review" feed a `leads` table, status pipeline `new → contacted →
  qualified → converted → lost`. **Not built yet.**
- **MVP exclusions** (do not build unless explicitly requested): Shopify/
  WordPress/WooCommerce platform integrations, automated fixes, competitor
  analysis, white-label reports, a public API, an agency dashboard, team
  management, complex billing.

## 3. Stack

| Layer | Choice |
|---|---|
| Backend | Laravel 12 (PHP 8.2) |
| Frontend | React 19 + TypeScript (Vite), separate SPA — not Inertia |
| Database | MySQL |
| Queue | Laravel Queue on Redis (`predis` client — no `phpredis` extension on this Windows dev box) |
| Queue monitoring | Laravel Horizon, **production only** — requires `pcntl`/`posix`, unavailable on Windows. Local dev uses `php artisan queue:work redis`. |
| Crawler | Playwright + Chromium (Node, invoked as a subprocess from PHP) |
| Accessibility | axe-core, injected into the same Playwright page load |
| Performance | Google PageSpeed Insights API + custom heuristics |
| AI | Provider abstraction over Claude / OpenAI / Gemini |
| Auth | Sanctum, Bearer token (not cookie/SPA-stateful) — frontend/backend are decoupled, may not share a parent domain |

**Repo layout**: monorepo — `backend/` (Laravel API), `frontend/` (React
SPA), `crawler/` (Node/Playwright, invoked as a subprocess by backend queue
jobs — not a standalone service). No shared code between them; they
communicate only via the JSON API under `/api` (frontend↔backend) and
subprocess stdin/stdout JSON (backend↔crawler).

## 4. Laravel backend architecture

```
backend/app/
  Console/Commands/     CrawlWebsite.php — php artisan audit:crawl (Phase 2+ test entry point)
  Contracts/             AiProvider, AuditModule — the two pluggability seams in this codebase
  DTOs/                  AiResponse, CrawledPageData, AuditIssueData, AuditModuleResult
  Enums/                 AiProviderName, AuditCategory, AuditStatus, CrawlJobStatus,
                         CrawlUrlStatus, IssueSeverity, PlatformName
  Http/
    Controllers/         Controller.php only (base) — no real endpoints exist yet
    Resources/           empty — no API resources built yet
    Responses/           ApiResponse.php — success()/error() helpers for consistent JSON shape
  Jobs/
    Crawl/                CrawlWebsiteJob, CrawlPageJob
    Audit/                RunAuditModuleJob, CalculateScoreJob
    Report/               empty — Phase 5
  Models/                User, Website, Audit, CrawlJob, CrawlUrl, AuditPage,
                         AuditIssue, AuditScore, AiAnalysis
  Providers/             AppServiceProvider — InnoDB string-length fix, crawler rate limiter
  Services/
    AI/                   AiProviderFactory, AiAnalysisService, AiProviderException,
                         ClaudeProvider, OpenAiProvider, GeminiProvider
    Audit/                AuditModuleRegistry (the module list), ScoreCalculator,
                         AiIssueParser, PageContextSummarizer,
                         SeoAuditModule, AccessibilityAuditModule, PerformanceAuditModule,
                         PageSpeedService, EcommerceAuditModule, LandingPageAuditModule,
                         ConversionAuditModule, AioAuditModule
    Crawler/              CrawlOrchestrator, PlaywrightCrawlerClient, UrlSecurityValidator,
                         RobotsTxtService, SitemapService, PlatformDetector,
                         SsrfValidationException, CrawlerException
```

**Config**: `config/crawler.php` (SSRF/rate/timeout limits, user agent),
`config/audit_scoring.php` (severity→points, category weights, per-module
thresholds), `config/ai.php` (provider keys/models/endpoints, caching
toggle), `config/services.php` (`pagespeed.key`), `config/logging.php`
(`crawler` and `ai` dedicated channels), `config/cors.php` (env-driven
allowed origins).

## 5. React frontend architecture

```
frontend/src/
  api/        client.ts — axios instance, VITE_API_URL base, bearer-token
              request interceptor reading localStorage 'auth_token'
  components/ empty — nothing built yet
  pages/      empty
  hooks/      empty
  types/      empty
  lib/        empty
```

Only the Vite scaffold page renders currently. No real UI has been built —
that starts in earnest around Phase 5 (Report System) / Phase 6 (User
System) per the timeline in §24.

## 6. Database architecture

MySQL, **every table explicitly declares `$table->engine = 'InnoDB'`** in
its migration — see the known-issue note in §16, this is not optional
boilerplate. `Builder::defaultStringLength(191)` is set in
`AppServiceProvider::boot()` for the same underlying reason (old-MySQL
`utf8mb4` index length limits).

## 7. Database tables and relationships

| Table | Key columns | Relationships |
|---|---|---|
| `users` | name, email, password | Sanctum `HasApiTokens`; `hasMany` websites, audits |
| `websites` | user_id (nullable), url, domain, platform (enum string) | `belongsTo` user; `hasMany` audits |
| `audits` | website_id, user_id (nullable), status, max_pages, pages_crawled, error_message, started_at, completed_at | `belongsTo` website/user; `hasOne` crawlJob; `hasMany` pages, issues, scores |
| `crawl_jobs` | audit_id, status, total_urls, processed_urls, failed_urls | `belongsTo` audit; `hasMany` urls |
| `crawl_urls` | crawl_job_id, url, status, http_status, depth, discovered_via | `belongsTo` crawlJob |
| `audit_pages` | audit_id, crawl_url_id (nullable), url, http_status, title, meta_description, canonical_url, redirect_chain (json), headings/links/images/forms/structured_data/cta_elements (json), accessibility_violations (json), performance_metrics (json), screenshot_path, html_size_bytes, load_time_ms | `belongsTo` audit, crawlUrl; `hasMany` issues |
| `audit_issues` | audit_id, audit_page_id (nullable), category, severity, code, message, recommendation, meta (json) | `belongsTo` audit, auditPage |
| `audit_scores` | audit_id, category (unique per audit incl. `overall`), score, breakdown (json) | `belongsTo` audit |
| `ai_analyses` | audit_id, category, provider, context_hash, context (json), prompt, response, prompt_tokens, completion_tokens, error | `belongsTo` audit; unique `(audit_id, category, context_hash)` — the cache key |
| `reports` | audit_id (unique), content (json — always the FULL report; visibility is a read-time filter, not stored), generated_at | `belongsTo` audit |

**Not yet created** (planned, per the original spec's full table list):
`report_sections` (deferred — a single `reports.content` JSON blob covers
Phase 5's needs; revisit only if PDF pagination in Phase 8 needs
per-section storage), `subscriptions`, `plans`, `usage_logs`, `leads`,
`lead_activities`, `cms_pages`, `cms_sections`, `audit_schedules`,
`notifications`.

## 8. API endpoints and responsibilities

**None exist yet.** The only way to trigger an audit is
`php artisan audit:crawl {url} --pages=N --wait` (a CLI command, not an
API route) — deliberate: there is no rate limiting or plan-limit
enforcement in front of "crawl any URL" until Phase 6 (User System), and a
public unauthenticated "audit any URL" endpoint today would make this
server an open crawling proxy. `routes/api.php` only has the Sanctum-
scaffolded `GET /api/user`.

## 9. Authentication and authorization rules

Sanctum installed (`php artisan install:api` scaffolding, `User` has
`HasApiTokens`), bearer-token model chosen over cookie/SPA-stateful auth
because frontend and backend may not share a parent domain. **No login/
register/token-issuance endpoints exist yet** — that's Phase 6. No
authorization policies exist yet (nothing to authorize against).

## 10. Crawler architecture

Node/Playwright script (`crawler/crawl.mjs`) invoked as a subprocess per
page by `App\Services\Crawler\PlaywrightCrawlerClient` (Symfony `Process`,
array args — never shell-interpolated), printing one JSON object to
stdout. `crawler/lib/extract.mjs` does DOM extraction (headings, links,
images, forms, JSON-LD, heuristic CTA-text matching, a bounded 200KB HTML
sample for platform detection). axe-core is injected into the *same* page
load (`page.addScriptTag` + `axe.run()`) rather than a second browser
pass.

**Orchestration** (`App\Services\Crawler\CrawlOrchestrator`): crawls the
homepage first, runs `PlatformDetector` on its HTML sample + headers,
seeds `CrawlJob`/`CrawlUrl` rows from sitemap discovery
(`RobotsTxtService` → `SitemapService`, sitemap-index aware, capped at 20
sub-sitemaps / 5000 URLs), falls back to breadth-first same-domain link
discovery from crawled pages (`expandFrontier`) when a site has no
sitemap. Both `RobotsTxtService` and `SitemapService` send an explicit
`crawler.user_agent` header — wordpress.org's WAF 403s Laravel's default
Guzzle UA with none set (found during Phase 2 testing).

**Jobs**: `CrawlWebsiteJob` (seeds the crawl, dispatches `CrawlPageJob` per
discovered URL) and `CrawlPageJob` (crawls one URL, expands the frontier,
detects crawl completion). Both carry `RateLimited('crawler')` middleware
backed by a limiter registered in `AppServiceProvider` from
`crawler.rate_limit_per_second`.

## 11. Audit engines

`App\Contracts\AuditModule` — `category(): AuditCategory`,
`run(Audit): AuditModuleResult`. Each of the 7 categories is an
independent class that only reads already-crawled `AuditPage` data;
modules never depend on each other's output. `AuditModuleRegistry::modules()`
is the single list of which classes run after a crawl — adding a module
means adding one line there, nothing else in the crawl→analyze handoff
changes.

**Pipeline**: `CrawlPageJob`/`CrawlWebsiteJob` detect crawl completion →
`AuditModuleRegistry::dispatchAnalysis()` sets `Audit.status = analyzing`
and dispatches one `RunAuditModuleJob` per registered module → each
persists its `AuditIssue`/`AuditScore` rows → whichever finishes last
(order is not deterministic — modules run independently) dispatches
`CalculateScoreJob` → computes the weighted overall score, sets
`Audit.status = completed`.

**Scoring** (`ScoreCalculator`, `config/audit_scoring.php`): each module
starts its category at 100, deducts `severity_points[severity]` per issue
(worst-first), optionally capped at N deductions so a long tail of minor
findings on a large site can't bottom a score at 0. Overall score is a
weighted average (`category_weights`) **renormalized over only the
categories that have actually run** — an audit that hasn't finished every
module yet still gets a fair overall score, not one diluted by categories
that haven't run.

## 12. AI architecture

`App\Contracts\AiProvider` — `analyze(string $prompt, array $context = []): AiResponse`,
`name(): string`. Concrete implementations: `ClaudeProvider` (Anthropic
Messages API), `OpenAiProvider` (Chat Completions), `GeminiProvider`
(Generative Language API — API key as a URL query param, which is
Google's actual API contract for this endpoint, not a shortcut).
`AiProviderFactory::make()` resolves whichever `AI_DEFAULT_PROVIDER`
names. All three throw `AiProviderException` on a missing key or non-2xx
response — never crash the caller.

**`AiAnalysisService`** is the single entry point every AI-driven module
calls: hashes `(category, prompt, context)`, checks the `ai_analyses`
table before calling a provider (a second identical call never spends
tokens — verified via `Http::assertSentCount(1)` in tests), logs provider
failures to the `ai` channel and records an error row, then returns
`null` on failure — callers fall back to deterministic-only results.

**`AiIssueParser`** is the shared, defensive parser every AI module uses
for the JSON-array response shape its prompt requests
(`[{severity, code, message, recommendation}]`) — strips a markdown fence
if the model wrapped one, falls back to `medium` severity on garbage
input, returns `[]` (never throws) on unparseable output.

**`PageContextSummarizer`** builds the *only* thing ever sent to an AI
provider: title/headings/CTA text/form field types/structured-data
types/image counts per page, capped at `audit_scoring.ai_context_pages`
(default 5) pages per audit. **Raw HTML never leaves the crawler.**

**Optimization rules actually enforced in code, not just documented**:
- Rule-first: every AI module runs its deterministic checks regardless of
  AI availability: `EcommerceAuditModule` skips the AI call *entirely*
  (not just its result) when a site shows no commerce signals — verified
  to produce zero `ai_analyses` rows for a non-commerce audit.
- Cache before call (`ai_analyses` unique key on
  `(audit_id, category, context_hash)`).
- Never send raw HTML (`PageContextSummarizer`).
- Log every AI failure (`ai` log channel + `ai_analyses.error`).

## 13. AIO scoring logic

`App\Services\Audit\AioAuditModule`. Deterministic: flags "no structured
data anywhere" (medium) and "page with no heading structure at all"
(high). AI layer: assesses whether headings state clear direct topics,
whether structured data disambiguates entities, whether titles/meta
descriptions clearly summarize content, whether sections look
independently extractable. **The prompt contains an explicit, hard
constraint forbidding any claim that the score predicts/guarantees
ranking or citation in a specific AI search product** — enforced at the
prompt level so it holds regardless of what UI surface renders the output
later, not left to be remembered when building the frontend.

## 14. Performance scoring logic

`App\Services\Audit\PerformanceAuditModule` + `PageSpeedService`.
Deterministic: load time vs `max_load_time_ms` budget, HTML size vs
`max_page_weight_bytes` budget, images missing explicit width/height
(CLS risk). PSI: called only against `audit_scoring.performance.pagespeed_pages`
pages (homepage by default) since it's a slow (10-30s), rate-limited
external call — never run per crawled page. Flags PSI score <50
(critical) / <90 (medium), LCP >2500ms (high), CLS >0.1 (medium).
Degrades gracefully (deterministic issues only) when `PAGESPEED_API_KEY`
is unset — true in this dev environment right now.

## 15. Accessibility/ADA analysis

`App\Services\Audit\AccessibilityAuditModule`. axe-core runs inside the
crawler's own Playwright page load (not a second pass). Violations are
**aggregated by rule ID across all pages, not per-page-per-violation** —
the same site-wide contrast issue found on every page becomes one
`AuditIssue`, not one per page, so score damage scales with the number of
distinct problems rather than page count. Highest impact seen for a rule
across pages wins (`impact_severity_map`: critical→critical,
serious→high, moderate→medium, minor→low).

## 16. SEO analysis

`App\Services\Audit\SeoAuditModule`. Fully deterministic, no AI: title/
meta-description presence and length, canonical presence, H1 count
(0 or >1 both flagged), images missing alt text (aggregated per page, not
per image), redirect chain length >1, duplicate titles across pages
(site-wide, once — not once per duplicate page), broken internal links
(best-effort: only flagged when the link target is itself a page this
audit crawled and knows returned 4xx/5xx — untouched targets are never
fetched just to check).

## 17. CRO analysis

`App\Services\Audit\ConversionAuditModule`. Deterministic: form friction
(>6 required fields on any form). AI layer: competing/unclear CTAs,
absence of trust/urgency signals *inferred only from text actually
present* (prompt explicitly forbids inventing claims), forms with no
nearby CTA, unclear next step.

## 18. Ecommerce/Landing Page analysis

**Ecommerce** (`EcommerceAuditModule`): only runs its checks if the site
actually shows commerce signals (Product schema, a purchase-intent CTA
text match, or platform=Shopify) — otherwise scores 100 with zero issues
*and skips the AI call entirely*, not just its result. When commerce
signals exist: flags missing Product schema (medium) and missing purchase
CTA (high) deterministically, then layers AI-found CRO/trust issues on
top.

**Landing Page** (`LandingPageAuditModule`): deterministic "no CTA
anywhere across pages" (high) and "page with zero headings" (medium), AI
layer assesses value-proposition clarity and page focus.

## 19. Usage limits

**Not built yet.** No `plans` table, no enforcement of the anonymous
(5 pages / 1 audit) or free (25 pages / 1 per day) limits described in
§2 — `Audit.max_pages` is currently just whatever the caller passes
(the CLI command's `--pages` option, unclamped). This is a known gap to
close in Phase 6 (User System).

## 20. Report visibility rules

`App\Services\Report\ReportVisibilityFilter` applies the free-vs-full
split **at read time**, never baked into what's generated — a `reports`
row always stores the full content, so an audit owner who later gets
full access sees the full version of the *same* report without
regenerating it. This matters because visibility depends on who's
looking, not on facts fixed when the report was built.

Free/anonymous view (per spec's field list, not a literal percentage —
see the note in `config/report.php`): overall score + category scores
(always fully visible), only "major" issues (critical/high severity),
capped at `report.anonymous_issue_limit` (default 5), and up to
`report.anonymous_recommendation_limit` (default 3) recommendations,
each with its `priority` field stripped. `hidden_issue_count`/
`hidden_recommendation_count` reflect *all* hidden issues (including
medium/low, excluded entirely), not just excess major ones — that's the
more honest "N more issues, sign up to see them" signal.

Full view: everything — all issues at every severity with `priority`,
all recommendations, per-page findings (`pages`), and per-category raw
AI analysis text (`ai_analysis`, sourced from `ai_analyses.response`).

`App\Services\Report\ReportAccessResolver::hasFullAccess()` decides who
gets which view — **interim rule** (no `plans`/`subscriptions` tables
exist yet): any audit tied to a registered user (`audit.user_id !==
null`) gets full access. This does not yet distinguish a free registered
user from a paid one per the spec's actual tiers; Phase 6 replaces this
one method's logic once plans exist — nothing else needs to change,
since `ReportVisibilityFilter` only ever sees the resulting boolean.

## 21. Admin dashboard functionality

**Not built yet** (Phase 7 per the original timeline: dashboard, users,
websites, audits, crawl_jobs, failed_jobs, ai_usage, plans, usage, leads,
cms modules).

## 22. Frontend functionality

**Not built yet beyond the Vite scaffold.** `src/api/client.ts` exists
(axios + bearer interceptor) but no page, component, or route has been
implemented. The default Vite "Get started" counter page is still what
renders at `/`.

## 23. Queue/job architecture

Redis-backed (`predis` client). **Redis itself is not installed on this
dev machine** — every end-to-end test so far has used
`QUEUE_CONNECTION=database CACHE_STORE=database` as an inline env
override for that test run only; `.env` itself still declares Redis as
the architecture. Horizon cannot run on Windows (`pcntl`/`posix`
missing) — add it only when provisioning a Linux environment; local dev
uses `php artisan queue:work redis` (or `database` while Redis is
unavailable).

Job graph: `CrawlWebsiteJob` → N × `CrawlPageJob` (each can spawn more
`CrawlPageJob`s via frontier expansion) → once crawl completes,
`AuditModuleRegistry::dispatchAnalysis()` → 7 × `RunAuditModuleJob` (one
per registered module, order not deterministic) → whichever finishes
last → `CalculateScoreJob`.

## 24. Security requirements

SSRF protection is layered, not single-point, because DNS can rebind
between a check and the actual connection, and redirects can retarget
anywhere:
- **PHP layer** (`App\Services\Crawler\UrlSecurityValidator`): validates
  scheme (http/https only) and port (80/443 only), rejects embedded
  credentials and over-long query strings, resolves the hostname and
  checks every IP via PHP's `FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE`
  — before a URL is ever handed to the Node subprocess.
- **Node layer** (`crawler/lib/ssrf.mjs`): independently re-resolves and
  re-checks the hostname on the *initial navigation and every redirect
  hop* via Playwright's `page.route()`, using its own IPv4/IPv6
  private-range logic (mirrors the PHP checks; can't share code across
  the language boundary).
- Both layers block localhost/loopback, private ranges (10/8, 172.16/12,
  192.168/16), link-local including cloud metadata (`169.254.169.254`),
  and non-http(s) schemes.
- Crawl depth limit, page-count limit, request/page/crawl timeouts,
  redirect limit, response file-size limit, and rate limiting are all
  config-driven (`config/crawler.php`), not hardcoded.

Frontend validation, if/when built, must never be the only validation —
the backend re-validates everything.

## 25. Current development status

Phases 1-5 complete and committed. See §26/27 for what's outstanding.

| Phase | Days | Status |
|---|---|---|
| 1. Foundation | 1-4 | ✅ done |
| 2. Crawler | 5-12 | ✅ done |
| 3. Core Auditors (performance/accessibility/SEO) | 13-21 | ✅ done |
| 4. AI Auditors (ecommerce/landing_page/conversion/aio + providers) | 22-31 | ✅ done |
| 5. Report System | 32-36 | ✅ done |
| 6. User System (auth endpoints, subscriptions, plans) | 37-40 | ⬜ not started |
| 7. Admin and CMS | 41-46 | ⬜ not started |
| 8. PDF and Scheduling | 47-50 | ⬜ not started |
| 9. Testing and Security | 51-58 | ⬜ not started (ongoing testing exists per-phase; a dedicated hardening pass has not happened) |
| 10. Production | 59-60 | ⬜ not started |

## 26. Known bugs

None currently open. Two were found and fixed during development (kept
here for history, not because they're still present):
- `CalculateScoreJob` used `pluck('score', 'category')` where `category`
  is enum-cast — using an enum object as an array key throws "Illegal
  offset type". Fixed with `mapWithKeys` to pull `->value` explicitly.
  (Phase 3)
- A sitemap-discovered homepage URL (`https://x/`) didn't string-match
  the user-supplied seed URL (`https://x`, no trailing slash), so the
  homepage got crawled twice. Fixed by normalizing both sides before
  comparing in `CrawlOrchestrator`. (Phase 2)

## 27. Known limitations

- **Redis is not installed** on this dev machine. All testing uses the
  `database` queue/cache driver as a per-command env override.
  Architecture (`.env`) still declares Redis; this is a local-environment
  gap, not a design change.
- **No real AI API keys configured.** All AI-driven module testing
  exercises the graceful-degradation (deterministic-only) path, not
  actual model responses. Provider request/response *parsing* is unit-
  tested via `Http::fake()`, but no real Claude/OpenAI/Gemini call has
  been made from this codebase yet.
- **No usage/plan limits enforced** — `Audit.max_pages` is unclamped
  input right now.
- **No public API endpoints** — everything is exercised via the
  `audit:crawl` artisan command.
- Broken-internal-link detection (SEO module) only catches links to pages
  already in the same audit's crawled set — it does not fetch untouched
  link targets just to check them.
- Concurrency: `CrawlPageJob`'s "is the crawl job done yet" check and
  `RunAuditModuleJob`'s "have all modules finished yet" check both use a
  simple existence query with no locking. Fine for the current single-
  worker local setup; worth revisiting once Horizon/multi-worker queue
  processing is in play (production, Phase 9+).

## 28. Pending tasks

In spec order, next up is **Phase 6: User System** (auth endpoints,
subscriptions, plans, usage enforcement) — this is also where
`ReportAccessResolver::hasFullAccess()`'s interim `user_id !== null` rule
gets replaced with real plan-tier logic (see §20). After that: Phase 7
(admin/CMS), Phase 8 (PDF export — Phase 5 built the report *data*;
Phase 8 renders it to PDF, to avoid overlap per the original phase-split
decision), Phase 9 (security/testing hardening pass), Phase 10
(production deploy).

## 29. Important technical decisions

- **Separate SPA + API**, not Inertia — chosen so frontend/backend can
  scale and deploy independently (user-approved architecture choice at
  project start).
- **Sanctum bearer tokens**, not cookie/SPA-stateful auth — frontend and
  backend may not share a parent domain.
- **Node subprocess for Playwright**, not a standing microservice — no
  extra service to deploy/monitor; each `CrawlPageJob` already runs
  inside a queue worker, so subprocess-per-page cost is acceptable and
  keeps the whole crawl inside Laravel's existing job/queue model.
- **axe-core injected into the same Playwright page load**, not a second
  browser pass — avoids double-crawling every page.
- **PageSpeed Insights runs on a capped page subset (default: homepage
  only)**, not per crawled page — it's slow (10-30s) and rate-limited.
- **InnoDB declared explicitly per-migration**, not relying on server
  defaults — portable across any deployment target regardless of that
  server's MySQL configuration (this WAMP install defaults to MyISAM,
  which silently no-ops foreign keys).
- **AI issue-response contract is a plain JSON array** of
  `{severity, code, message, recommendation}`, parsed defensively
  (`AiIssueParser`) — never throws on a malformed model response, worst
  case is zero AI-derived issues for that run.
- **AuditModuleRegistry as the single module list** — adding a category
  is one line there; the crawl-completion handoff and the
  "all-modules-done" check both derive their required-category list from
  it dynamically, so nothing else needs to change per new module.
- **Report visibility is a read-time filter, never baked into the stored
  report** — `reports.content` is always the full report;
  `ReportVisibilityFilter::filter($content, $hasFullAccess)` decides what
  a given viewer sees. This means an audit owner who later gets full
  access (or an admin, or any future viewer role) sees the full version
  of the *same* report without regenerating it — visibility depends on
  who's asking, not on anything fixed when the report was built.
- **Who counts as "full access" is isolated in one class**
  (`ReportAccessResolver`) specifically so its interim rule (registered
  user = full access, since no plans/subscriptions exist yet) can be
  swapped for real plan-tier logic in Phase 6 without touching
  `ReportVisibilityFilter` or anything that calls it.
- **No `report_sections` table** — a single `reports.content` JSON blob
  covers everything Phase 5 needs; only worth splitting out if PDF
  pagination (Phase 8) turns out to need per-section storage.

## 30. Testing architecture

Backend: PHPUnit, `backend/tests/Unit/...` and `backend/tests/Feature/...`,
SQLite in-memory DB for tests (`phpunit.xml`), `RefreshDatabase` trait
(except `DatabaseMigrationTest`, which manages migration state itself so
it can test `migrate:rollback`/`migrate:reset` without conflicting with
RefreshDatabase's transaction wrapping). External HTTP (PageSpeed, AI
providers, robots.txt/sitemap) mocked via `Http::fake()` — no test hits a
real third-party API. The Node/Playwright crawler subprocess
(`PlaywrightCrawlerClient`) is mocked in the one Feature test that drives
the full pipeline (`CrawlAndAnalyzePipelineTest`) — no test launches a
real browser. 118 PHP tests currently, all passing.

Crawler: `crawler/lib/*.test.mjs`, run via `npm test` (Node's built-in
`node --test`, no added dependency). Covers the SSRF logic
(`isPrivateAddress`, `assertHostnameIsPublic`) directly — the same
defense-in-depth layer described in §24, tested independently of the PHP
side since the two can't share code across the language boundary. 40
Node tests currently, all passing.

**158 tests total** (118 PHP + 40 Node). See `tests/TEST_CASES.md` at the
repo root for the full registry mapped to test-requirement categories,
coverage status, and any remaining gaps.

Frontend: no test setup exists yet (no components exist yet to test).

E2E: none exist yet — there's no frontend to drive and no public API to
call.

---

## Maintenance rule

Whenever you change architecture, database schema, API behavior,
business logic, project status, known bugs, or a technical decision:
**update this file in the same change**, not as a follow-up. Keep
`CLAUDE.md` for working rules and the phase timeline; keep this file as
the technical/status reference so the two don't duplicate or drift.
