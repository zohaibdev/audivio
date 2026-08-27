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
  qualified → converted → lost`. Built in Phase 7 — see §8 for the
  public capture endpoint and admin lead-management endpoints.
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
System) per the timeline in §27.

## 6. Database architecture

MySQL, **every table explicitly declares `$table->engine = 'InnoDB'`** in
its migration — see the known-issue note in §31, this is not optional
boilerplate. `Builder::defaultStringLength(191)` is set in
`AppServiceProvider::boot()` for the same underlying reason (old-MySQL
`utf8mb4` index length limits).

## 7. Database tables and relationships

| Table | Key columns | Relationships |
|---|---|---|
| `users` | name, email, password, is_admin (boolean, default false) | Sanctum `HasApiTokens`; `hasMany` websites, audits; `hasOne` subscription |
| `websites` | user_id (nullable), url, domain, platform (enum string) | `belongsTo` user; `hasMany` audits |
| `audits` | website_id, user_id (nullable), status, max_pages, pages_crawled, error_message, started_at, completed_at | `belongsTo` website/user; `hasOne` crawlJob; `hasMany` pages, issues, scores |
| `crawl_jobs` | audit_id, status, total_urls, processed_urls, failed_urls | `belongsTo` audit; `hasMany` urls |
| `crawl_urls` | crawl_job_id, url, status, http_status, depth, discovered_via | `belongsTo` crawlJob |
| `audit_pages` | audit_id, crawl_url_id (nullable), url, http_status, title, meta_description, canonical_url, redirect_chain (json), headings/links/images/forms/structured_data/cta_elements (json), accessibility_violations (json), performance_metrics (json), screenshot_path, html_size_bytes, load_time_ms | `belongsTo` audit, crawlUrl; `hasMany` issues |
| `audit_issues` | audit_id, audit_page_id (nullable), category, severity, code, message, recommendation, meta (json) | `belongsTo` audit, auditPage |
| `audit_scores` | audit_id, category (unique per audit incl. `overall`), score, breakdown (json) | `belongsTo` audit |
| `ai_analyses` | audit_id, category, provider, context_hash, context (json), prompt, response, prompt_tokens, completion_tokens, error | `belongsTo` audit; unique `(audit_id, category, context_hash)` — the cache key |
| `reports` | audit_id (unique), content (json — always the FULL report; visibility is a read-time filter, not stored), generated_at | `belongsTo` audit |
| `plans` | name, slug (unique), price_cents, max_pages, daily_audit_limit, full_recrawls, is_default, features (json) | `hasMany` subscriptions |
| `subscriptions` | user_id (unique — one active plan per user, no history), plan_id, status, started_at, ends_at | `belongsTo` user, plan |
| `usage_logs` | user_id (nullable — anonymous tracked by IP instead), ip_address, action, website_id (nullable), audit_id (nullable) | `belongsTo` user, website, audit |
| `leads` | audit_id (nullable), name, email, phone (nullable), website_url (nullable), message (nullable), source, status | `belongsTo` audit; `hasMany` activities |
| `lead_activities` | lead_id, user_id (nullable — an activity can be system-generated, not just an admin's note), note | `belongsTo` lead, user |
| `cms_pages` | slug (unique), title, meta_description (nullable) | `hasMany` sections (ordered by `sort_order`) |
| `cms_sections` | cms_page_id, section_key (not an enum — admins add new named sections without a migration), content (json), sort_order; unique `(cms_page_id, section_key)` | `belongsTo` page |
| `audit_schedules` | user_id, website_id, frequency (enum string: daily/weekly/monthly), is_active (default true), next_run_at, last_run_at (nullable); unique `(user_id, website_id)` — one schedule per website | `belongsTo` user, website |
| `api_providers` | slug (unique: claude/openai/gemini/pagespeed), name, provider_type (ai/audit), is_enabled, api_key (text, `encrypted` cast — never serialized, see `$hidden`), model (nullable), config (json — max_tokens, temperature, request_timeout, daily/monthly limits, mobile/desktop toggles, cost-per-1k-token rates), last_success_at, last_failure_at, last_error | none (referenced by slug string, not FK, from `api_usage_logs`) |
| `api_usage_logs` | provider (string, not FK'd — see §23), service, request_type (nullable), user_id (nullable), audit_id (nullable), model (nullable), success, http_status (nullable), response_time_ms (nullable), prompt_tokens/completion_tokens/total_tokens (nullable), estimated_cost (nullable), error_category (nullable) | `belongsTo` user, audit (both nullOnDelete) |

**Not yet created** (planned, per the original spec's full table list):
`report_sections` (deferred — a single `reports.content` JSON blob covers
Phase 5's needs; revisit only if PDF pagination needs per-section
storage), `notifications`.

## 8. API endpoints and responsibilities

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/api/register` | none | Create a user, subscribe to the default plan, issue a token |
| POST | `/api/login` | none | Issue a token for valid credentials |
| POST | `/api/logout` | required | Revoke the current token |
| GET | `/api/user` | required | Current authenticated user |
| POST | `/api/audits` | **optional** | Create a website + audit, dispatch the crawl. `throttle:10,1`. |
| GET | `/api/audits/{audit}` | **optional** | Poll status/progress — no report data |
| GET | `/api/audits/{audit}/report` | **optional** | Fetch the report, filtered per viewer (§20) |
| GET | `/api/audits/{audit}/report/pdf` | **optional** | Export the full report as a PDF — requires full access AND the owner's plan to have the `pdf_export` feature (403 otherwise); 404 if no report yet (§22) |

"Optional" auth: the route carries no `auth:sanctum` middleware (which
would reject an unauthenticated request outright), and the controller
calls `$request->user('sanctum')` directly — Sanctum's guard resolves a
Bearer token independently of route middleware, so this returns the
authenticated user if a valid token is present or `null` otherwise,
letting one endpoint serve both anonymous and registered callers with
different limits/visibility.

`AuditController::store()` derives `max_pages` server-side from
`UsageLimitService::maxPagesFor()` — **the caller cannot request more
pages than their tier allows**; there is no `max_pages` input field on
the request at all. Enforces `UsageLimitService::canCreateAudit()`
(429 if exceeded) and `UrlSecurityValidator::assertSafe()` (422 if
unsafe) before creating any DB rows.

`php artisan audit:crawl {url} --pages=N --wait` still exists as a
CLI convenience for local testing (its `--pages` option is real there,
since it bypasses `AuditController` entirely) — it's just no longer the
only way to trigger an audit.

**Public, non-admin** (Phase 7):

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/api/leads` | none | Capture a "Talk to a Consultant" / "Request an Audit Review" CTA submission |
| GET | `/api/cms/{slug}` | none | Fetch a CMS page + its sections, for the (future) frontend — never hardcode page content |

**Registered user** (`auth:sanctum` only — no admin needed, Phase 8):

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/audit-schedules` | List the current user's own recurring-audit schedules |
| POST | `/api/audit-schedules` | Create a schedule for one of the user's own websites — 422 if not their website or already scheduled, 403 if their plan lacks `scheduled_audits` or they're at their `full_recrawls` limit (§22) |
| PUT | `/api/audit-schedules/{auditSchedule}` | Update `frequency` and/or `is_active` (pause/resume) — 404 (not 403) if it isn't the caller's schedule |
| DELETE | `/api/audit-schedules/{auditSchedule}` | Remove a schedule — same 404-not-403 ownership rule |

**Admin** (`/api/admin/*`, all behind `auth:sanctum` + the `admin`
middleware alias, which checks `$request->user()->is_admin`):

| Method | Path | Purpose |
|---|---|---|
| GET | `/dashboard` | Aggregate counts: users/admins, websites, audits by status, leads by status, AI usage (calls/tokens), failed jobs, audit schedules (total/active) |
| GET | `/users` | Paginated user listing, with subscription+plan eager-loaded |
| GET | `/websites` | Paginated website listing, with audit count |
| GET | `/audits`, `/audits/{audit}` | Paginated listing / single audit with scores+issues+crawl job |
| GET | `/crawl-jobs` | Paginated crawl job listing |
| GET | `/audit-schedules` | Paginated listing of every user's schedules, with user+website eager-loaded — read-only, no admin CRUD since schedules are user-owned (§22) |
| GET | `/failed-jobs`, DELETE `/failed-jobs/{id}` | List/remove Laravel's own failed-job bookkeeping rows (no retry endpoint yet — see §29) |
| GET | `/ai-usage` | Aggregates by provider and category, plus the 50 most recent `ai_analyses` rows |
| GET/POST `/plans`, PUT/DELETE `/plans/{plan}` | Full plan CRUD — **the only place paid tiers get created**, since none are seeded (§29) |
| GET | `/usage` | Paginated `usage_logs`, filterable by `user_id`/`action` |
| GET `/leads`, GET `/leads/{lead}`, PUT `/leads/{lead}`, POST `/leads/{lead}/activities` | Lead management — status updates auto-log a `LeadActivity`; manual notes via the activities endpoint |
| Full CRUD `/cms`, `/cms/{page}`, PUT/DELETE `/cms/{page}/sections/{sectionKey}` | CMS page + section management. Section endpoints are an upsert-by-key — one PUT both creates and edits, so the frontend never needs to know which case it is |
| GET `/providers`, GET `/providers/{slug}`, PUT `/providers/{slug}`, PATCH `/providers/{slug}/status`, POST `/providers/{slug}/test` | AI/API provider configuration (§23) — `{slug}` not `{id}`; `api_key` is never returned, only `masked_key` |
| GET `/usage/summary`, `/usage/daily`, `/usage/by-provider`, `/usage/logs` | Cross-provider usage/cost analytics from `api_usage_logs` (§23) — distinct from `/ai-usage` above |

There is no "promote a user to admin" endpoint — `is_admin` is set
directly in the database (or via `tinker`) by design. A self-service or
API-driven path to grant admin access would be a privilege-escalation
surface with no product requirement to justify it yet.

## 9. Authentication and authorization rules

Sanctum bearer tokens (`AuthController@register/login/logout/me`).
Registration creates the user, subscribes them to whichever `Plan` has
`is_default = true` (skips silently if none exists — seed one via
`PlanSeeder`), and issues a token. No email verification, no password
reset — not built, not required by the spec's Phase 6 description, and
would need a decided email-sending setup this project doesn't have yet.

Authorization for report access lives in `ReportAccessResolver` (§20),
not route middleware — `hasFullAccess(Audit $audit, ?User $viewer)`
takes the *specific* viewer, not just "is this audit owned by someone."
An earlier version of this class only checked whether the audit had
*any* owner, which would have let any authenticated viewer see another
user's full report by guessing/knowing the audit ID — caught and fixed
before any real caller used it (see §28 for the equivalent bug that
*did* ship and get fixed, in `CalculateScoreJob`; this one was a design
review catch, not a shipped defect).

**Partially closed in Phase 9**: audit IDs are still plain auto-increment
integers (no UUID migration — too invasive for the actual risk level),
but `AuditController::show()` now enforces the same ownership rule as
report access: a **registered** audit's status is visible only to its
own owner (or anonymously, matching the flow that creates it) — a
*different* logged-in user gets a 404, not the real status. This closes
the realistic risk (one account probing another account's audits) while
leaving the accepted gap for anonymous audits alone, since those have no
ownership concept at all to check (§20) — anyone who guesses an
anonymous audit's ID can still see its status, same as before.

**Admin authorization** (Phase 7): a single `is_admin` boolean on
`users`, checked by the `EnsureUserIsAdmin` middleware (aliased as
`admin` in `bootstrap/app.php`, stacked after `auth:sanctum` on the
`/api/admin/*` group so `$request->user()` is guaranteed non-null when
it runs). No roles/permissions system — one flag is sufficient for a
single admin tier, and building more would be scope the spec's MVP
exclusions ("advanced team management") explicitly reject.
`is_admin` had to be added to `User::$fillable` to be settable via
`User::create()`/`update()` at all — this is safe because no
request-driven code path passes raw/validated request input into a
User create/update call that could smuggle it in (`AuthController::register()`
builds an explicit `['name', 'email', 'password']` array, and no
`RegisterRequest` rule even permits an `is_admin` field, so Laravel's
`validated()` would strip it even if a client sent one).

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

`App\Services\User\UsageLimitService` enforces the spec's free_audit
limits, sourced from two different places by design:

- **Registered users**: `daily_audit_limit` and `max_pages` come from
  the user's `Plan` (via their `Subscription`) — DB-configurable, never
  hardcoded per tier. `canCreateAudit()` counts today's `usage_logs`
  rows for that user against the plan's daily limit. A user with no
  subscription cannot create audits at all (`canCreateAudit()` returns
  `false`) — registration always creates one against the default plan,
  so this only bites a user created outside that flow (e.g. directly in
  a script/seeder).
- **Anonymous (no account)**: `config/audit_limits.php`
  (`anonymous_max_pages`, `anonymous_audit_limit`) — there's no user row
  to attach a plan to, so this is one global config value, still
  env-configurable, just not per-account. The limit is checked by IP
  address and is a **lifetime** limit (per spec: "1 audit"), not daily —
  `canCreateAudit(null, $ip)` checks whether that IP has *ever* created
  an audit, not just today.

`AuditController::store()` is the only caller of `maxPagesFor()` and
`canCreateAudit()` — page limits are never client-supplied (§8).

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

`App\Services\Report\ReportAccessResolver::hasFullAccess(Audit $audit, ?User $viewer)`
decides who gets which view, and takes the *specific requesting viewer*
as of Phase 6 — not just "does this audit have some owner." Rules:
- An anonymous audit (`audit.user_id === null`) grants full access to
  **no one**, including its own (anonymous) creator — there's no
  session/cookie mechanism to let an anonymous visitor "claim" their own
  audit later, so it stays partial-view forever unless/until a real
  claiming flow exists.
- A registered audit grants full access **only to its own owner**
  (`audit.user_id === $viewer->id`) — a different authenticated user
  who knows/guesses the audit ID gets the partial view, same as
  anonymous.
- **Interim rule**: being the owner is sufficient regardless of plan —
  this doesn't yet check whether the owner's plan actually includes full
  reports as a feature. Tightening this to check `$viewer->plan()`
  features is a one-method change, isolated from
  `ReportVisibilityFilter` and its callers by design.

## 21. Admin dashboard functionality

All 11 modules from the spec's `admin.modules` list are built as API
endpoints (§8) — this is backend-only; there's no admin frontend yet
(§24), so these are the surface a future admin UI would consume.

`AdminDashboardService::summary()` is the one piece of real aggregation
logic, isolated and unit-tested independently of the controller —
users/admins counted separately, audits and leads grouped by status
(`selectRaw(...)->groupBy('status')->pluck('count', 'status')`), AI
usage split into successful vs. failed calls with token sums, failed
job count from Laravel's own `failed_jobs` table (queried directly via
`DB::table()` — there's no Eloquent model for it, it's queue
infrastructure, not an application concept).

Everything else (users/websites/audits/crawl-jobs/usage listings) is
plain paginated Eloquent queries in the controller — no service layer,
because there's no real business logic in "list this resource,
paginated." `AdminPlanController` is the one CRUD controller with actual
logic: setting a plan `is_default` unsets it on every other plan inside
a DB transaction (so two plans can never both claim to be the default),
and deleting a plan with an active subscription returns a friendly 409
instead of a raw 500 by catching the `QueryException` the DB's `RESTRICT`
FK (§7, verified in Phase 6's `CascadeDeleteTest`) throws.

Failed-job management is deliberately list+delete only — no retry
endpoint. Retrying (`php artisan queue:retry`) re-serializes and
re-dispatches a job, which has more failure modes than a simple CRUD
pair; add it if a real operational need shows up rather than
speculatively.

## 22. PDF export and scheduled audits

**PDF export** (`App\Services\Report\ReportPdfService`, via
`barryvdh/laravel-dompdf`): renders the report's Blade view
(`resources/views/reports/pdf.blade.php`) to a PDF binary and streams it
from `GET /api/audits/{audit}/report/pdf` (§8). Always renders the
**full** report content — this endpoint is only ever reached after a
full-access check, so there's no separate "free PDF" concept to build.
The view is self-contained HTML/CSS (no remote fonts/assets — dompdf
doesn't fetch them by default and there was no need to enable that).

Gating is two separate checks, not one: `ReportAccessResolver::hasFullAccess()`
(same check the JSON report endpoint uses — must be the audit's owner)
**and** `Plan::hasFeature('pdf_export')` (PDF export is a paid-tier
feature per the spec, distinct from full report access itself — an
owner's free-tier audit already gets "full access" under the interim
rule in §20, but that alone doesn't entitle them to a PDF). Missing
either returns 403; no report yet returns 404, matching the JSON
endpoint's behavior.

**Scheduled (recurring) audits** (`App\Services\Schedule\AuditSchedulerService`,
`audit_schedules` table, `App\Enums\ScheduleFrequency`: daily/weekly/
monthly): a registered-user-only feature (no anonymous path) gated by
two plan fields that sat unused since Phase 6 (see §7, §31) —
`Plan::hasFeature('scheduled_audits')` decides whether a user can
schedule at all, and `plans.full_recrawls` caps how many *active*
schedules (i.e. distinct websites on a recurring cadence) a user can
have at once. Both are independently plan-configurable: a plan could
grant the feature with a low cap, or vice versa (though a plan granting
the feature with `full_recrawls = 0` would be a self-defeating admin
configuration, not something the code needs to prevent).

CRUD lives behind `auth:sanctum` at `/api/audit-schedules` (§8):
creating one validates the website belongs to the requesting user (422
otherwise), that it isn't already scheduled (422 — DB also enforces this
via a `(user_id, website_id)` unique constraint, but the controller
checks first for a friendlier error than a raw constraint violation),
and both gates above (403). Update/delete check ownership and return
404 (not 403) for someone else's schedule, to avoid confirming another
user's schedule even exists.

Execution is a Laravel scheduled task (`bootstrap/app.php`'s
`withSchedule`), not a queue job directly — `AuditSchedulerService::runDue()`
runs hourly (recurring audits are day/week/month granularity, so
per-minute precision isn't needed), finds every `AuditSchedule` with
`is_active` and `next_run_at <= now()`, and for each: creates a new
`Audit` against the *existing* `Website` (no new `Website` row, unlike
the normal `POST /api/audits` flow), dispatches `CrawlWebsiteJob` (the
same job the manual flow uses — the pipeline doesn't know or care
whether a crawl was scheduled or requested), logs a `usage_logs` row
under the `recrawl` action (distinct from `audit_created`, so admin
usage listings can tell the two apart — the migration's column comment
already anticipated this action name), and advances `next_run_at` by
the schedule's frequency. There's no caller IP for a background job, so
this path doesn't go through `UsageLimitService` at all — being on an
active schedule is itself the authorization, checked once at creation
time, not re-checked per run.

Admin gets a read-only listing (`GET /api/admin/audit-schedules`, §8)
and a dashboard count (`AdminDashboardService::summary()['audit_schedules']`,
total and active) — consistent with every other resource's admin
visibility, no CRUD since schedules are user-owned and admins have no
product reason to create one on a user's behalf.

## 23. AI/API provider configuration and usage tracking

**Provider config moved from `.env`/`config/*.php` to a DB-backed,
admin-editable table** (`api_providers`, `App\Models\ApiProvider`) —
credentials are encrypted at rest via Eloquent's `encrypted` cast
(AES-256-CBC, `APP_KEY`), never appear in `$hidden`-protected
serialization, and the model exposes only `masked_key` (first 4 + last
4 chars, e.g. `sk-a****mnop`). `ProviderSeeder` imports any existing
`.env` credentials once on first run as a migration convenience; from
then on the admin UI is the source of truth, not `.env`. Provider-
specific settings that don't apply uniformly (temperature, mobile/
desktop toggles, request/token limits, per-1k-token cost rates) live in
a `config` JSON column rather than dedicated nullable columns, so a
future fifth provider is a config shape, not a migration — this is what
"future provider support" means in practice here, not literally
provider-agnostic *code* (the admin "Test Connection" action is still a
4-way `match` in `AdminProviderController::test()`, since only 4
providers exist to test).

`App\Services\Provider\ProviderResolver` (no constructor dependencies,
safe to `new` directly) is the one place that decides whether a
provider is usable: `resolve()` requires enabled + configured (used by
real `analyze()`/`PageSpeedService::analyze()` calls — throws
`AiProviderException` otherwise, same graceful-degradation contract as
before), `resolveConfigured()` requires only configured (used by "Test
Connection," so an admin can validate a key before flipping it on).
`ClaudeProvider`/`OpenAiProvider`/`GeminiProvider` each extract a
private `performRequest()` shared between `analyze()` and the new
`testConnection()` (added to the `AiProvider` contract) — avoids
duplicating the HTTP-call/parsing logic between the two paths.

**Usage tracking** (`api_usage_logs`, `App\Models\ApiUsageLog`) is a
cross-provider, request-level log distinct from `ai_analyses` — the two
answer different questions and neither replaces the other:
`ai_analyses` is the per-audit AI cache/result record (§12, unique per
audit+category+context-hash, drives the cache-before-call rule);
`api_usage_logs` is ops/cost telemetry (provider, service, model,
success, http_status, response_time_ms, tokens, estimated_cost,
error_category) covering *every* external call including PageSpeed,
which `ai_analyses` never covered at all. `AiAnalysisService::analyze()`
writes one row per attempt (success or failure) after the existing
`ai_analyses` write; `PageSpeedService::analyze()` does the same.
Estimated cost is computed via `ApiProvider::estimateCost()`, which
returns `null` (not a guessed number) when the admin hasn't set
`cost_per_1k_input_tokens`/`cost_per_1k_output_tokens`.

**Per-provider request limits** (`config.daily_request_limit`/
`monthly_request_limit`) are enforced at the same two chokepoints:
`AiAnalysisService::analyze()` checks before calling a provider and
logs a `limit_exceeded`-category row instead of returning null
silently; `PageSpeedService::analyze()` does the same for its one
(daily-only) limit. Reached limits degrade gracefully (return `null`,
caller falls back to deterministic-only results) — same contract a
missing/disabled provider already had.

**Admin API** (`/api/admin/providers/*`, `/api/admin/usage/*`, §8):
provider CRUD is a `{slug}`-keyed resource (`ApiProvider::getRouteKeyName()`
returns `'slug'`, not `id`) — `GET /providers`, `GET /providers/{slug}`,
`PUT /providers/{slug}` (configure — merges submitted config keys into
the existing `config` JSON rather than replacing it wholesale),
`PATCH /providers/{slug}/status` (enable/disable — rejects enabling an
unconfigured provider with 422), `POST /providers/{slug}/test` (test
connection — logs its own `api_usage_logs` row under
`service: 'test_connection'`). Usage analytics
(`AdminApiUsageController`, separate from the existing
`AdminAiUsageController`) expose `/usage/summary`, `/usage/daily`,
`/usage/by-provider`, `/usage/logs` (paginated, filterable by provider/
service/model/user/audit/success/date range) — token and cost figures
are fields within these responses rather than the spec's suggested
separate `/usage/tokens`/`/usage/cost` endpoints, a deliberate
consolidation to avoid three endpoints returning overlapping subsets of
the same query.

`AdminDashboardService::summary()['provider_usage_today']` adds
today's AI request/token/cost totals and PageSpeed request count to the
existing dashboard aggregate, alongside the pre-existing `ai_usage`
block (which stays `ai_analyses`-based, per-audit-scoped — the new
block is `api_usage_logs`-based, today-scoped).

## 24. Frontend functionality

**Not built yet beyond the Vite scaffold.** `src/api/client.ts` exists
(axios + bearer interceptor) but no page, component, or route has been
implemented. The default Vite "Get started" counter page is still what
renders at `/`.

## 25. Queue/job architecture

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

## 26. Security requirements

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

**Rate limiting** (Phase 9 hardening pass — `/register`, `/login`, and
`/leads` were previously unthrottled entirely; `LeadController` even had
a stale comment claiming a "shared api throttle" that didn't exist):
- `throttle:5,1` on `POST /api/register` and `POST /api/login` — tighter
  than the audit/lead endpoints since these are the actual brute-force/
  spam surface (credential guessing, signup flooding).
- `throttle:10,1` on `POST /api/audits`, `GET /api/audits/{audit}` (+
  `/report`, `/report/pdf`), and `POST /api/leads` — matches the
  pre-existing audit-endpoint rate.
- `GET /api/cms/{slug}` stays unthrottled — public, read-only, cheap; no
  meaningful DoS surface to rate-limit differently from any other static
  content read.

**Password policy** (`AppServiceProvider::boot()`, via
`Password::defaults()`): minimum 8 characters, at least one letter and
one number. Laravel's own unconfigured default is *only* "at least 8
characters" — no complexity requirement at all — which this closes for
the one place `Password::defaults()` is used (`RegisterRequest`).

**Baseline security headers** (`X-Content-Type-Options: nosniff`,
`X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`)
on every response, added via a `RequestHandled` event listener in
`AppServiceProvider::boot()` — **deliberately not a terminable
middleware**. A middleware's post-`$next()` code only runs on the
success path: an exception thrown deeper in the pipeline (e.g. Sanctum's
`AuthenticationException` on a 401) unwinds straight to the Kernel's
exception handler, skipping every middleware's after-code entirely — so
a middleware-based version of this would silently miss headers on every
401/403/422/429/404 response, exactly the responses where a header like
`X-Frame-Options` matters most. `RequestHandled` fires after the
response is finalized either way (success or exception-rendered), so
it's the one hook that actually covers both. Caught by writing the test
for it (`AuthTest::test_exception_rendered_api_responses_also_include_security_headers`)
before trusting the first (middleware) implementation.

## 27. Current development status

Phases 1-9 complete and committed. See §28/29 for what's outstanding.

| Phase | Days | Status |
|---|---|---|
| 1. Foundation | 1-4 | ✅ done |
| 2. Crawler | 5-12 | ✅ done |
| 3. Core Auditors (performance/accessibility/SEO) | 13-21 | ✅ done |
| 4. AI Auditors (ecommerce/landing_page/conversion/aio + providers) | 22-31 | ✅ done |
| 5. Report System | 32-36 | ✅ done |
| 6. User System (auth endpoints, subscriptions, plans) | 37-40 | ✅ done |
| 7. Admin and CMS | 41-46 | ✅ done |
| 8. PDF and Scheduling | 47-50 | ✅ done |
| 9. Testing and Security | 51-58 | ✅ done — see §26 for the security hardening (rate limits, password policy, security headers, audit-ID ownership check) and §32 for the testing-gap closures |
| 10. Production | 59-60 | ⬜ not started |

## 28. Known bugs

None currently open. Three were found and fixed during development
(kept here for history, not because they're still present):
- `CalculateScoreJob` used `pluck('score', 'category')` where `category`
  is enum-cast — using an enum object as an array key throws "Illegal
  offset type". Fixed with `mapWithKeys` to pull `->value` explicitly.
  (Phase 3)
- A sitemap-discovered homepage URL (`https://x/`) didn't string-match
  the user-supplied seed URL (`https://x`, no trailing slash), so the
  homepage got crawled twice. Fixed by normalizing both sides before
  comparing in `CrawlOrchestrator`. (Phase 2)
- `AuditController::store()` returned `$audit->status->value` right
  after dispatching `CrawlWebsiteJob` — correct with the real async
  Redis queue (still Pending at that point), but with a synchronous
  driver (tests) `dispatch()` runs the job inline first, and the
  in-memory `$audit` object's status was never refreshed, so the
  response's correctness silently depended on which queue driver was
  configured. Fixed to return the literal known value
  (`AuditStatus::Pending->value`) instead of reading back
  possibly-stale state. (Phase 6)
- `User::$fillable` never included `is_admin` when the column was added
  — `User::create([..., 'is_admin' => true])` silently dropped the
  field instead of erroring, so the very first `AdminDashboardService`
  test ("counts users and admins separately") failed with a count of 0
  admins instead of 1. Fixed by adding it to `$fillable` — verified safe
  to do so since no request-driven create/update path passes raw input
  through (see §9). (Phase 7)
- Two migrations sharing the same auto-generated timestamp minute
  sorted in the wrong dependency order — `create_lead_activities_table`
  (needs `leads` to exist first, via its `lead_id` FK) alphabetically
  sorts *before* `create_leads_table` ('a' < 's'). Same root cause as
  a Phase 2 bug (`crawl_urls`/`audit_pages` ordering) — caught
  immediately by the `Cannot add foreign key constraint` error on
  first migrate attempt, fixed by bumping the later migration's
  timestamp. Worth remembering: always check migration run order when
  `make:migration` generates multiple files in the same command batch,
  not just when adding one at a time. (Phase 7)
- A first implementation of the Phase 9 security headers used a
  terminable middleware (code after `$next($request)`). That code never
  runs for a response rendered from an exception thrown deeper in the
  pipeline (Sanctum's `AuthenticationException` on a 401, validation's
  422, throttle's 429, etc.) — the exception unwinds straight to the
  Kernel's handler, skipping every middleware's after-code. Caught
  before shipping by writing the exception-path test first (see §26);
  replaced with a `RequestHandled` event listener, which fires after the
  response is finalized either way. Not a shipped defect, but worth
  remembering: **a middleware's post-`$next()` code is a success-path
  hook, not a guaranteed one.** (Phase 9)

**Testing-only gotcha #2, not a bug** (found writing the PDF-export
anonymous-viewer test, Phase 8): `$this->withHeader('Authorization', ...)`
sets a *default* header on the test client that persists on every
subsequent request from that test method — it does not scope to a
single call the way it reads. A test that authenticates once and then
makes a second, deliberately-anonymous request in the same method will
still send the first token unless `withoutHeader('Authorization')` (or
`withoutToken()`) is called first. Combined with the Sanctum-guard-
memoization gotcha below, a test simulating "authenticated, then truly
anonymous" in one method needs *both* `$this->app['auth']->forgetGuards()`
and `withoutHeader('Authorization')` — dropping either one leaves the
second request resolving as the first request's user.

**Testing-only gotcha, not a bug** (documented so it isn't rediscovered
and mistaken for one): within a single test method, Sanctum's
`RequestGuard` memoizes its resolved user for the guard instance's
lifetime, and PHPUnit test methods share one app container across
multiple simulated HTTP calls — so a *second* simulated request in the
same test can appear to stay authenticated as whichever user the *first*
request resolved, even after that user's token was deleted, or even when
a *different* token is sent. A real server never does this (every actual
HTTP request is a fresh PHP process with no shared state). Two places
this bit test-writing in Phase 6: verifying logout (fixed by asserting
`$user->tokens()->count() === 0` directly against the DB instead of a
second simulated request) and verifying cross-user report isolation
(fixed by calling `$this->app['auth']->forgetGuards()` between the two
authenticated calls). If a future test needs to simulate two different
authenticated actors in one method, use one of these two patterns.

## 29. Known limitations

- **Redis is not installed** on this dev machine. All testing uses the
  `database` queue/cache driver as a per-command env override.
  Architecture (`.env`) still declares Redis; this is a local-environment
  gap, not a design change.
- **No real AI API keys configured.** All AI-driven module testing
  exercises the graceful-degradation (deterministic-only) path, not
  actual model responses. Provider request/response *parsing* is unit-
  tested via `Http::fake()`, but no real Claude/OpenAI/Gemini call has
  been made from this codebase yet.
- **Anonymous usage tracking is IP-based only.** Shared IPs (offices,
  NAT, mobile carriers) will hit false "already used your free audit"
  positives; a signed cookie or browser fingerprint would be more
  accurate but adds complexity not obviously worth it before real
  traffic patterns are known.
- **No email verification, no password reset.** Registration issues a
  token immediately; there's no email-sending infrastructure decided
  yet to build either on top of.
- **Audit IDs are plain auto-increment integers.** The ownership gap on
  `GET /api/audits/{id}` is closed for registered audits (§9, Phase 9);
  anonymous audits remain visible to anyone who guesses the ID, which is
  accepted since there's no session/cookie mechanism to check ownership
  against for them at all.
- **Sanctum tokens never expire** (`config/sanctum.php`'s `expiration`
  is `null`). Not changed in the Phase 9 hardening pass — picking a
  concrete duration is a product decision (how often should a user have
  to re-authenticate?) this project hasn't made, and there's no
  refresh-token or re-auth UX built on the frontend to handle an
  expired token gracefully yet. Revisit once that UX exists.
- Broken-internal-link detection (SEO module) only catches links to pages
  already in the same audit's crawled set — it does not fetch untouched
  link targets just to check them.
- Concurrency: `CrawlPageJob`'s "is the crawl job done yet" check and
  `RunAuditModuleJob`'s "have all modules finished yet" check both use a
  simple existence query with no locking. Fine for the current single-
  worker local setup; worth revisiting once Horizon/multi-worker queue
  processing is in play (production, Phase 10+).
- **No "promote user to admin" endpoint** — deliberate (§9), but means
  the very first admin has to be created via `tinker` or a direct DB
  write. Fine for a single-operator MVP; would need a real bootstrapping
  story before this could be handed to a team.
- **No failed-job retry endpoint** — list and delete only (§21).
- **No plans are seeded except the free default.** Paid tiers don't
  exist until an admin creates them via `POST /api/admin/plans` — no
  concrete pricing/limits were invented since the spec doesn't specify
  them and "complex billing" is an MVP exclusion (see Phase 6's
  `PlanSeeder` comment).

## 30. Pending tasks

Phase 9's hardening pass closed the audit-ID-enumeration gap and the
unthrottled-auth-endpoints gap; the remaining §29 items
(IP-based-anonymous-tracking, no "promote to admin" endpoint, no email
verification/password reset, Sanctum tokens never expiring) were
deliberately left as accepted limitations or deferred product decisions
rather than being fixed speculatively — revisit only if real usage
shows they're actually load-bearing problems, not preemptively.

**Out-of-spec-order work**: the DB-backed AI/API provider configuration
and usage-tracking system (§23) and the full frontend (public site,
user dashboard, audit flow, admin dashboard, provider-config UI, usage
analytics — see §24) were requested directly, ahead of Phase 10, since
the original 10-phase plan never allocated a phase to the frontend at
all. After the frontend is complete: **Phase 10: Production** remains
the last phase in the original plan.

## 31. Important technical decisions

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
  (`ReportAccessResolver`) — as of Phase 6 it takes the specific viewer
  and checks actual ownership, not just "does this audit have some
  owner." The remaining interim simplification (owner = full access
  regardless of plan features) is isolated the same way, so tightening
  it later touches one method, not `ReportVisibilityFilter` or its
  callers.
- **No `report_sections` table** — a single `reports.content` JSON blob
  covered everything Phase 5 needed, and PDF export (Phase 8) rendered
  straight from that same JSON via a Blade view with no need for
  per-section storage.
- **"Optional" Sanctum auth via `$request->user('sanctum')` on routes
  with no `auth:sanctum` middleware**, not two separate route sets (one
  guarded, one not) — Sanctum's guard resolves a Bearer token
  independently of route middleware, so this lets `POST /api/audits`
  and the report/status endpoints serve anonymous and registered callers
  from the same controller with different limits/visibility.
- **Registered-user limits live on a DB-configurable `plans` table;
  anonymous limits live in `config/audit_limits.php`** — not the same
  mechanism, because anonymous visitors have no account row to attach a
  plan to. Both are equally "not hardcoded," just at different scopes.
- **`AuditController::store()` derives `max_pages` server-side only** —
  no client input can request more pages than the resolved tier allows.
  The CLI command's `--pages` option is a deliberately separate, more
  permissive path for local testing, not a precedent the API follows.
- **One subscription per user, no history** (`subscriptions.user_id` is
  unique) — matches "complex billing" being an explicit MVP exclusion;
  upgrading/downgrading updates the existing row. Add subscription
  history only if a real feature needs it, not preemptively.
- **A single `is_admin` boolean, not a roles/permissions system** — one
  admin tier is all the spec calls for; a full RBAC system would be
  scope the MVP exclusions ("advanced team management") explicitly
  reject.
- **No admin-creation endpoint** — `is_admin` is set directly via
  `tinker`/DB, not through the API. An API path to grant admin access
  is a privilege-escalation surface with no product requirement behind
  it yet.
- **`cms_sections.section_key` is a free-text string, not an enum** —
  admins can add a new named section (beyond the spec's hero/services/
  pricing/faq/etc. list) without a migration. The upsert-by-key PUT
  endpoint (create-or-update in one call) means the frontend never
  needs to know whether a section already exists.
- **Only the free plan is seeded** (`PlanSeeder`) — paid tiers get
  created through the new admin Plan CRUD once real pricing is decided,
  rather than inventing numbers the spec doesn't specify.
- **Failed-job management is list+delete only, no retry** — retrying
  re-serializes and re-dispatches a job, a meaningfully different
  (and riskier) operation than simple CRUD; add it only if a real
  operational need shows up.
- **`barryvdh/laravel-dompdf`, not a headless-browser PDF renderer** —
  the report is simple tabular/text content with no need for JS
  execution or pixel-perfect CSS; dompdf needs no external binary
  (unlike wkhtmltopdf/Puppeteer), which matters on this Windows dev box
  and keeps deployment simple.
- **PDF export requires the owner's plan to have `pdf_export` as a
  feature flag, checked separately from `ReportAccessResolver::hasFullAccess()`**
  — the interim rule that "owner = full report access regardless of
  plan" (§20) predates PDF export and was never meant to extend to it;
  the spec calls out PDF export as a distinct paid-tier feature, so it
  gets its own plan-feature gate rather than riding on the report-access
  check.
- **`plans.features` is a keyed map of feature-flag booleans**
  (`{"pdf_export": false, "scheduled_audits": false, ...}`), not a plain
  list — `PlanSeeder` already anticipated this shape before either
  feature was wired up. `Plan::hasFeature(string $key)` is the one place
  that reads it, so adding a new gated feature later is a one-line
  addition to a plan's `features` JSON, not a schema change.
- **`plans.full_recrawls` caps active `audit_schedules` rows, not a
  separate "manual recrawl" counter** — the field sat unused since
  Phase 6 with no other caller; reusing it as "how many websites can
  this user have on a recurring schedule at once" avoided inventing a
  second, undifferentiated recrawl-counting mechanism the spec never
  concretely defined.
- **Scheduled audits run via Laravel's task scheduler (`withSchedule`
  in `bootstrap/app.php`), calling `AuditSchedulerService::runDue()`
  hourly** — not a dedicated always-running worker process, since audit
  recurrence is day/week/month granularity and an hourly reconciliation
  loop (checking `next_run_at <= now()`) is simple, idempotent, and
  needs no new infrastructure beyond what `php artisan schedule:run`
  (already a standard Laravel deployment cron entry) provides.
- **A scheduled run dispatches the same `CrawlWebsiteJob` the manual
  `POST /api/audits` flow uses**, against the schedule's existing
  `Website` row (no new `Website` created) — the crawl/analysis
  pipeline has no concept of "scheduled vs. manual," and duplicating it
  would be pure risk for no benefit.
- **Audit-ID enumeration was closed with an ownership check on the
  existing `show()` controller, not a UUID migration** (§9) — a UUID
  reference for every audit would touch every FK, every route, and
  every existing test; the actual risk (a *different account* probing
  another user's registered audits) is fully addressed by checking
  `audit.user_id` against the requesting viewer, which is a few lines in
  one controller. The remaining gap (anonymous audits are enumerable by
  anyone) is accepted, not fixed, because there's no session mechanism
  for an anonymous audit to have an "owner" to check against in the
  first place.
- **Security headers are added via a `RequestHandled` event listener,
  not a terminable middleware** — see §26/§28. A middleware's
  post-`$next()` code is skipped whenever an exception is thrown deeper
  in the pipeline, which is exactly when most of these responses
  (401/403/422/429/404) are produced; `RequestHandled` fires after the
  response is finalized regardless of which path produced it.
- **Password policy tightened to 8+ characters with letters and
  numbers**, not left at Laravel's bare "8 characters, nothing else"
  default — a minimal, defensible baseline that doesn't require a
  product decision the way a specific token-expiration duration would
  (see the Sanctum-expiration limitation in §29, deliberately left
  alone for that reason).
- **`/register`/`/login` throttled tighter (`throttle:5,1`) than
  `/audits`/`/leads` (`throttle:10,1`)** — credential-guessing and
  signup-spam are a more direct abuse vector per request than creating
  an audit or submitting a lead, both of which are already bounded by
  usage limits (§19) and a lead-capture form's low incentive to spam.
- **Provider credentials moved from `.env` to an encrypted, admin-
  editable DB table** (§23) — a deliberate architectural change (not
  just a UI on top of existing config) so an admin can rotate a key or
  flip a provider off without a redeploy. `.env` values are imported
  once by `ProviderSeeder` as a migration convenience, then the DB is
  the sole source of truth.
- **One generic `api_usage_logs` table for every provider, not one
  table per provider or a provider-specific schema per type** — a
  single request-level log with a plain `provider` string column scales
  to a future fifth provider without a migration, and lets the same
  admin usage-analytics queries (daily/by-provider/summary) work
  unchanged regardless of which providers exist.
- **`ai_analyses` and `api_usage_logs` deliberately coexist rather than
  one replacing the other** (§23) — different questions, different
  shapes: `ai_analyses` is the per-audit AI cache/result record (drives
  the cache-before-call rule); `api_usage_logs` is cross-provider ops/
  cost telemetry, the only one of the two that covers PageSpeed at all.

## 32. Testing architecture

Backend: PHPUnit, `backend/tests/Unit/...` and `backend/tests/Feature/...`,
SQLite in-memory DB for tests (`phpunit.xml`), `RefreshDatabase` trait
(except `DatabaseMigrationTest`, which manages migration state itself so
it can test `migrate:rollback`/`migrate:reset` without conflicting with
RefreshDatabase's transaction wrapping). External HTTP (PageSpeed, AI
providers, robots.txt/sitemap) mocked via `Http::fake()` — no test hits a
real third-party API. The Node/Playwright crawler subprocess
(`PlaywrightCrawlerClient`) is mocked in the one Feature test that drives
the full pipeline (`CrawlAndAnalyzePipelineTest`) — no test launches a
real browser. `tests/Feature/Api/` covers the real HTTP endpoints
(`AuthTest`, `AuditApiTest`, `AdminApiTest`, `AuditScheduleApiTest`) —
the first tests in this project to exercise actual routes/middleware/
controllers rather than services directly. `ReportPdfServiceTest`
asserts on the actual rendered PDF binary (`%PDF-` header), not just
that dompdf was called, so a broken Blade view would fail it.
`UniqueConstraintsTest` (Phase 9) asserts the DB itself rejects a
duplicate `audit_scores`/`ai_analyses` insert, not just that application
code happens to call `updateOrCreate()`. Several Phase 9 tests exist
specifically to prove a security fix actually works end-to-end rather
than trusting the implementation: throttle tests fire real requests past
the configured limit and assert 429, the audit-ID-ownership tests assert
a different account gets a 404 (not just that `ReportAccessResolver` was
called), and the injection/XSS-style-input tests assert the string was
stored verbatim and the table survives intact rather than merely
asserting "no exception was thrown." `AdminProviderApiTest` (§23) proves
the provider-config/usage-tracking system end-to-end against real HTTP
requests: `Http::fake()`'d provider calls, real encryption round-trips,
masked-key assertions against the raw response body (not just the
decoded JSON path), and real daily/date-range aggregation queries. 287
PHP tests currently, all passing.

Crawler: `crawler/lib/*.test.mjs`, run via `npm test` (Node's built-in
`node --test`, no added dependency). Covers the SSRF logic
(`isPrivateAddress`, `assertHostnameIsPublic`) directly — the same
defense-in-depth layer described in §26, tested independently of the PHP
side since the two can't share code across the language boundary. 40
Node tests currently, all passing.

**327 tests total** (287 PHP + 40 Node). See `tests/TEST_CASES.md` at the
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
