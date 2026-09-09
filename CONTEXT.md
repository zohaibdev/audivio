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

Built out-of-phase-order (§30) — the original 10-phase plan never
allocated a phase to the frontend at all; it was requested directly.
React 19 + TypeScript + Vite, Tailwind CSS v4 (`@tailwindcss/vite`
plugin, no separate PostCSS config), `recharts` for admin charts,
`react-router-dom` v7 for routing. No data-fetching library (TanStack
Query/SWR) — plain `axios` + `useEffect`, consistent with the app's
overall preference for the simplest thing that works over adding a
dependency for a single concern.

```
frontend/src/
  api/          client.ts (axios instance + bearer interceptor + ApiRequestError/
                unwrap() — the one place that translates transport errors into
                app-level ones), auth.ts, audits.ts, websites.ts, schedules.ts,
                leads.ts, cms.ts, plans.ts, admin.ts (every /admin/* endpoint)
  lib/          url.ts — client-side audit-URL checking for the error message
                only; the backend re-validates everything (see section 26)
  contexts/     AuthContext.tsx — user/isLoading/login/register/logout,
                token in localStorage, hydrates from GET /api/user on mount
  components/
    website/    WebsiteCard (one card per domain), WebsiteDetail (the
                nine detail sections + inline profile form), section 37
    layout/     PublicLayout (marketing header/footer + mobile menu),
                SidebarLayout (shared shell for both /dashboard and /admin,
                parameterized by title+navItems — one component, not two),
                RouteGuards (RequireAuth, RequireAdmin)
    ui/         Card, Button, Input, Badge/SeverityBadge/StatusBadge, Spinner,
                ErrorState, EmptyState, StatCard, ScoreGauge, Pagination
    charts/     LineChartCard, BarChartCard (recharts wrappers with a
                consistent card/empty-state shell)
    audit/      AuditUrlForm (shared by the homepage hero, the dedicated
                /audit page, and the dashboard overview — one implementation,
                three entry points), AuditProgressSteps
    report/     ReportOverviewCard, IssueList, RecommendationList,
                PageFindingsTable, ReportView (assembles all of the above,
                branches on `is_full_access`)
    admin/      ProviderCard (the provider-config form + test-connection UI)
  pages/
    public/     HomePage, ServicesPage, HowItWorksPage, PricingPage (fetches
                real plans via GET /api/plans), FaqPage, ContactPage (lead
                capture form)
    auth/       LoginPage, SignupPage, ForgotPasswordPage (intentionally
                read-only/informational — see §24)
    audit/      SubmitAuditPage, AuditStatusPage (one adaptive page serving
                progress + preview report + full report — see §24)
    dashboard/  Overview, Websites, Audits (serves "Audits"/"Audit History"/
                "Reports" — see §24), Schedules, Usage, Profile (read-only —
                no profile-update endpoint exists), Subscription (read-only —
                no self-service plan-change endpoint exists)
    admin/      DashboardHome, Users, Websites, Audits, Leads, Providers,
                ApiUsage (serves "API Usage" and "Logs" — see §24), AiUsage,
                Plans (serves "Plans" and "Usage Limits"), Cms, Settings (a
                hub linking to Providers/Plans/Cms/SystemHealth), SystemHealth,
                FailedJobs
  types/index.ts  Every shared response shape (User, Report, ApiProviderSummary,
                UsageSummary, AdminDashboardSummary, etc.) in one file
App.tsx         All routing — three route trees (public, RequireAuth+dashboard,
                RequireAdmin+admin) plus a catch-all 404
```

No automated frontend test suite exists (no Vitest/React Testing Library
set up) — verification for this work was `tsc -b && vite build` (must
pass with zero errors — `noUnusedLocals`/`noUnusedParameters` are on, so
this also catches dead code), `oxlint` (the project's configured
linter — the only warnings it raises are the generic
`set-state-in-effect` pattern inherent to plain fetch+`useEffect` data
loading, present on every data-fetching page by design, and one
`only-export-components` note on `AuthContext.tsx` for co-locating the
provider and its hook), and manual verification through the actual
running app in a browser (every flow in §24 was exercised against the
real backend, not mocked). See §32 for the full picture and why this is
an accepted gap, not an oversight.

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
| `websites` | user_id (nullable), name, primary_url, domain, normalized_domain, platform (enum string), category, description, products_or_services, aio_goal (§42), country (ISO-3166 alpha-2), budget, average_product_cost, competitors (json); unique `(user_id, normalized_domain)` | `belongsTo` user; `hasMany` audits, auditSchedules; `hasOne` latestAudit, latestCompletedAudit — **one row per domain per user**, §37 |
| `audits` | website_id, user_id (nullable), status (8-state lifecycle, §38), max_pages, pages_crawled, error_message, failed_stage, started_at, completed_at, terminated_at, progress_at | `belongsTo` website/user; `hasOne` crawlJob, snapshot; `hasMany` pages, issues, scores; `scopeActive()` |
| `crawl_jobs` | audit_id, status, total_urls, processed_urls, failed_urls | `belongsTo` audit; `hasMany` urls |
| `crawl_urls` | crawl_job_id, url, status, http_status, depth, discovered_via | `belongsTo` crawlJob |
| `audit_pages` | audit_id, crawl_url_id (nullable), url, http_status, title, meta_description, canonical_url, redirect_chain (json), headings/links/images/forms/structured_data/cta_elements (json), accessibility_violations (json), performance_metrics (json), html_size_bytes, load_time_ms | `belongsTo` audit, crawlUrl; `hasMany` issues (single-page only, see `issue_pages`), screenshots; `belongsToMany` issues (multi-page, via `issue_pages`) — see §34 |
| `audit_issues` | audit_id, audit_page_id (nullable — null for a multi-page issue, see `issue_pages`), category, severity, code, message, recommendation, meta (json) | `belongsTo` audit, auditPage; `belongsToMany` pages (via `issue_pages`) |
| `issue_pages` | audit_issue_id, audit_page_id; unique `(audit_issue_id, audit_page_id)` | `belongsTo` (implicitly, via the pivot) — real bidirectional issue↔page relation for an issue spanning more than one page, §34 |
| `audit_scores` | audit_id, category (unique per audit incl. `overall`), score (nullable), is_applicable (default true), breakdown (json) | `belongsTo` audit — `score=null, is_applicable=false` means "Not Applicable," never a fabricated number, §34 |
| `screenshots` | audit_id, audit_page_id, viewport (`desktop`\|`mobile`), width, height, storage_path; unique `(audit_page_id, viewport)` | `belongsTo` audit, auditPage — §34 |
| `ai_analyses` | audit_id, category, provider, context_hash, context (json), prompt, response, prompt_tokens, completion_tokens, error | `belongsTo` audit; unique `(audit_id, category, context_hash)` — the cache key |
| `reports` | audit_id (unique), content (json — always the FULL report; visibility is a read-time filter, not stored), generated_at | `belongsTo` audit |
| `audit_snapshots` | audit_id (unique), website_url, domain, crawler_version, scoring_version, prompt_version, ai_provider, ai_model, pages_analyzed, audited_at, content (json) | `belongsTo` audit — **immutable**: written once on completion, `updating`/`deleting` throw (§38). Distinct from `reports`, which is regenerable presentation |
| `plans` | name, slug (unique), price_cents, max_pages, max_websites, daily_audit_limit, monthly_audit_limit, concurrent_audit_limit (§38), full_recrawls, max_audit_frequency, report_visibility_percent, issues_per_service, ai_analyses_per_audit, aio_prompt_limit, custom_aio_prompt_limit (§42) (all §40 — nullable numerics mean *unlimited*, never unset), is_default, is_active, features (json — unrelated pdf_export/scheduled_audits/audit_history perks, see §33) | `hasMany` subscriptions; `belongsToMany` features (via `plan_features`), aiModels (via `plan_ai_models`) |
| `features` | key (unique: performance/accessibility/cro/seo/aio/ecommerce/product_auditor), name, description, is_enabled | `belongsToMany` plans (via `plan_features`) — the audit-category registry, §33; not admin-creatable, seeded once |
| `plan_features` | plan_id, feature_id, is_enabled; unique `(plan_id, feature_id)` | `belongsTo` plan, feature — per-plan override of a feature |
| `ai_models` | api_provider_id, model_id, display_name, description, is_enabled, input/output_cost_per_1k_tokens, context_window, capabilities (json); unique `(api_provider_id, model_id)` | `belongsTo` apiProvider; `belongsToMany` plans (via `plan_ai_models`) — catalog of individually addressable models per provider (§33), distinct from `api_providers.model` |
| `plan_ai_models` | plan_id, ai_model_id; unique `(plan_id, ai_model_id)` | `belongsTo` plan, aiModel |
| `subscriptions` | user_id (unique — one active plan per user, no history), plan_id, status, started_at, ends_at | `belongsTo` user, plan; `isActive()` checks status **and** `ends_at` — a lapsed subscription falls back to the default plan (§40), it does not deny everything |
| `usage_logs` | user_id (nullable — anonymous tracked by IP instead), ip_address, action, website_id (nullable), audit_id (nullable) | `belongsTo` user, website, audit |
| `leads` | audit_id (nullable), name, email, phone (nullable), website_url (nullable), message (nullable), source, status | `belongsTo` audit; `hasMany` activities |
| `lead_activities` | lead_id, user_id (nullable — an activity can be system-generated, not just an admin's note), note | `belongsTo` lead, user |
| `cms_pages` | slug (unique), title, meta_description (nullable) | `hasMany` sections (ordered by `sort_order`) |
| `cms_sections` | cms_page_id, section_key (not an enum — admins add new named sections without a migration), content (json), sort_order; unique `(cms_page_id, section_key)` | `belongsTo` page |
| `website_aio_prompts` | website_id, prompt, sort_order | `belongsTo` website — the owner's own AIO questions, on top of the ten defaults in `config/aio_prompts.php` (§42) |
| `website_competitors` | website_id, name, url, normalized_domain, source (user\|discovered), reason, aio_score, pages_analyzed, topics (json), visibility (json), analysis_error, analyzed_at; unique `(website_id, normalized_domain)` | `belongsTo` website — every analysis column is null until a comparison run fills it, and **null is not zero**: an unreachable competitor must never read as one scoring badly (§42) |
| `audit_schedules` | user_id, website_id, frequency (enum string: daily/weekly/monthly), is_active (default true), next_run_at, last_run_at (nullable); unique `(user_id, website_id)` — one schedule per website | `belongsTo` user, website |
| `api_providers` | slug (unique: claude/openai/gemini/pagespeed), name, provider_type (ai/audit), is_enabled, api_key (text, `encrypted` cast — never serialized, see `$hidden`), model (nullable — the one model this provider is *currently actively calling*; see `ai_models` for the full catalog), config (json — max_tokens, temperature, request_timeout, daily/monthly limits, mobile/desktop toggles, cost-per-1k-token rates), last_success_at, last_failure_at, last_error | `hasMany` aiModels; otherwise referenced by slug string, not FK, from `api_usage_logs` |
| `api_usage_logs` | provider (string, not FK'd — see §23), service, request_type (nullable), user_id (nullable), audit_id (nullable), model (nullable), success, http_status (nullable), response_time_ms (nullable), prompt_tokens/completion_tokens/total_tokens (nullable), estimated_cost (nullable), error_category (nullable) | `belongsTo` user, audit (both nullOnDelete) |

**Not yet created** (planned, per the original spec's full table list):
`report_sections` (deferred — a single `reports.content` JSON blob covers
Phase 5's needs; revisit only if PDF pagination needs per-section
storage), `notifications`.

## 8. API endpoints and responsibilities

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/api/register` | none | Create a user, subscribe to the default plan, issue a token. Response's `data.user` includes `subscription.plan` (added for the frontend's dashboard, which needs plan limits right after signup, not just after a later `/api/user` refetch) |
| POST | `/api/login` | none | Issue a token for valid credentials. Same `subscription.plan` inclusion as register |
| POST | `/api/logout` | required | Revoke the current token |
| GET | `/api/user` | required | Current authenticated user, with `subscription.plan` eager-loaded |
| GET | `/api/websites` | required | The caller's own websites, paginated, one card per domain (§37) — screenshot, name, domain, last audit date, overall + per-service scores, pages analyzed, critical/high counts. Distinct from `/admin/websites` below, which lists every user's |
| GET | `/api/websites/{website}` | required | One website's full detail (§37): overview, latest audit, audit history, score trends, service scores, issue trends, pages, recommendations, consultation context. `{website}` is an id **or** a normalized domain, resolved against the caller's own websites only — 404 (never 403) for anyone else's |
| PUT | `/api/websites/{website}` | required | Update the website's profile fields only (name, category, description, products_or_services, country, budget, average_product_cost, competitors). `domain`, `normalized_domain`, `primary_url` and `user_id` are not writable — they are derived from what was audited |
| GET | `/api/audits` | required | The caller's own audit history, paginated, with `website`+`scores` — distinct from `/admin/audits` below |
| GET | `/api/plans` | none | Every *active* plan, ordered by price, with `auditFeatures` eager-loaded (filtered to globally+per-plan enabled) — the pricing page's data source, so paid tiers and their feature set appear automatically once an admin creates/configures them via `/admin/plans` |
| GET/POST | `/api/websites/{website}/aio-prompts` | required | List (with the ten defaults) or add a custom AIO prompt; 403 with `errors.limit` past the plan's `custom_aio_prompt_limit` (§42) |
| DELETE | `/api/websites/{website}/aio-prompts/{prompt}` | required | Remove one, scoped to the caller's own websites (§42) |
| GET | `/api/me/entitlements` | **optional** | The caller's plan limits and on/off entitlements (§40). Descriptive only — every limit is enforced again server-side where it applies |
| GET | `/api/me/features` | **optional** | The caller's (or, anonymously, the default plan's) resolved access to every globally-enabled feature, plus which *other* active plans include a locked one — §33 |
| GET | `/api/me/ai-models` | **optional** | The caller's (or default plan's) currently-usable AI models (no cost fields — that's admin-only data) — §33. No `/api/me/plan`: `/api/user` already eager-loads `subscription.plan` (below), so this isn't duplicated |
| POST | `/api/audits` | **optional** | Resolve the website (§37) + create an audit under the caller's concurrent-audit limit, dispatch the crawl. 429 when at the limit. `throttle:10,1`. |
| GET | `/api/audits/{audit}` | **optional** | Poll status/progress — no report data. Carries `status_label`, `is_active`, `is_cancellable` and `failed_stage` (§38) |
| POST | `/api/audits/{audit}/cancel` | **optional** | Cancel a running audit, releasing its concurrent slot immediately; in-flight jobs stop at their next checkpoint. Owner-only (404 otherwise), 422 if already terminal (§38) |
| GET | `/api/audits/{audit}/report` | **optional** | Fetch the report, filtered per viewer (§20) |
| GET | `/api/audits/{audit}/report/pdf` | **optional** | Export the full report as a PDF — requires full access AND the owner's plan to have the `pdf_export` feature (403 otherwise); 404 if no report yet (§22) |
| GET | `/api/audits/{audit}/report/services` | **optional** | Every per-service report plus the shared overview (§35), filtered by the same free-vs-full split as `/report` |
| GET | `/api/audits/{audit}/report/services/{service}` | **optional** | One service's report — 404 (never an empty shell) if that service didn't run for this audit |
| GET | `/api/audits/{audit}/report/services/{service}/pdf` | **optional** | That one service as its own PDF, from the same normalized model as the web report; same full-access + `pdf_export` gate as the combined PDF |
| GET | `/api/audits/{audit}/pages` | **optional** | Page explorer — every crawled page with cross-service issue counts and both screenshots. **Full access only** (403 otherwise): page-level detail is paid content |

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
| GET/POST `/plans`, PUT/DELETE `/plans/{plan}` | Full plan CRUD — **the only place paid tiers get created**, since none are seeded (§29). `index` eager-loads `subscriptions_count`, `auditFeatures`, `allowedAiModels.apiProvider` for the admin plan table/editor |
| PUT `/plans/{plan}/features` | Bulk-sync (`sync()`, not per-key patch) which of the 7 registry features are enabled for this plan — §33 |
| PUT `/plans/{plan}/ai-models` | Bulk-replace which `ai_models` rows this plan allows — §33 |
| GET `/features`, PATCH `/features/{feature}/status` | The 7-row audit-feature registry — list, and *global* enable/disable only (not admin-creatable; §33) |
| GET/POST `/ai/models`, PUT/DELETE `/ai/models/{aiModel}` | AI model catalog CRUD, optionally filtered `?provider=slug` — §33. Delete is blocked (409) while any plan still allows the model |
| GET | `/usage` | Paginated `usage_logs`, filterable by `user_id`/`action` |
| GET `/leads`, GET `/leads/{lead}`, PUT `/leads/{lead}`, POST `/leads/{lead}/activities` | Lead management — status updates auto-log a `LeadActivity`; manual notes via the activities endpoint |
| Full CRUD `/cms`, `/cms/{page}`, PUT/DELETE `/cms/{page}/sections/{sectionKey}` | CMS page + section management. Section endpoints are an upsert-by-key — one PUT both creates and edits, so the frontend never needs to know which case it is |
| GET `/providers`, GET `/providers/{slug}`, PUT `/providers/{slug}`, PATCH `/providers/{slug}/status`, POST `/providers/{slug}/test` | AI/API provider configuration (§23) — `{slug}` not `{id}`; `api_key` is never returned, only `masked_key` |
| GET `/usage/summary`, `/usage/daily`, `/usage/by-provider`, `/usage/logs` | Cross-provider usage/cost analytics from `api_usage_logs` (§23) — distinct from `/ai-usage` above |
| GET | `/system-health` | Real DB connectivity check (`DB::connection()->getPdo()`) and real cache round-trip check, plus the configured queue connection name (not a live queue heartbeat — nothing in this stack exposes one) |

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
sitemap. Both `RobotsTxtService` and `SitemapService` fetch through
`SafeHttpClient` (§26) rather than the `Http` facade directly — it is
what disables transport-level redirect following, re-validates every hop,
caps the response body, and sends the explicit `crawler.user_agent`
header that wordpress.org's WAF requires (it 403s Laravel's default
Guzzle UA when none is set — found during Phase 2 testing).

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
is the single list of which classes exist — adding a module means adding
one line there, nothing else in the crawl→analyze handoff changes.

**Pipeline**: `CrawlPageJob`/`CrawlWebsiteJob` detect crawl completion →
`AuditModuleRegistry::dispatchAnalysis()` sets `Audit.status = analyzing`,
computes `modulesFor($audit)` (§33 — the subset of `modules()` the
audit's plan actually allows) and dispatches one `RunAuditModuleJob` per
*allowed* module (or dispatches `CalculateScoreJob` directly if the plan
allows none) → each persists its `AuditIssue`/`AuditScore` rows →
whichever finishes last (order is not deterministic — modules run
independently) checks `modulesFor($audit)` again (not the full
`modules()` list — an audit whose plan disables a category would
otherwise wait forever for a score that will never arrive) and dispatches
`CalculateScoreJob` once every *allowed* category has a score →
computes the weighted overall score, sets `Audit.status = completed`.

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
names, or a specific slug when passed one. All three throw
`AiProviderException` on a missing key or non-2xx response — never crash
the caller.

**`AiAnalysisService`** is the single entry point every AI-driven module
calls: hashes `(category, prompt, context)`, checks the `ai_analyses`
table before calling a provider (a second identical call never spends
tokens — verified via `Http::assertSentCount(1)` in tests), resolves
*which* provider via `PlanAiModelResolver` (§33 — the audit owner's plan,
not always `AI_DEFAULT_PROVIDER`), logs provider failures to the `ai`
channel and records an error row, then returns `null` on failure —
callers fall back to deterministic-only results. A `null` from
`PlanAiModelResolver` (no plan-allowed model is usable) short-circuits
before a provider is even chosen, and deliberately writes no
`api_usage_logs` row — there's no provider to attribute one to (unlike
the pre-existing `exceedsConfiguredLimits()` check just below it, which
already has a specific provider in hand).

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
endpoints (§8) and consumed by the real admin frontend described in §24
(this section predates that frontend and originally described a
backend-only surface — kept for the aggregation/CRUD design notes below,
which are still accurate).

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

**Public site**: homepage (URL-submission hero, six-audit-category
explainer, free-vs-paid explainer, consultant CTA, CMS-driven hero
text with a hardcoded fallback when no CMS row exists yet), Services,
How It Works, Pricing (real data from `GET /api/plans`, §8 — not
hardcoded tiers; includes a feature-comparison table built from the
union of each returned plan's `audit_features`, §33), FAQ, Contact
(feeds `POST /api/leads`).

**Auth**: Login, Signup (client-side mirrors the backend's password
policy as a hint, real validation still happens server-side), Forgot
Password. The last one is **deliberately read-only/informational** —
there is no password-reset backend (§9/§29), so this page says so and
points to Contact instead of submitting to an endpoint that doesn't
exist.

**Audit flow**: a submitted URL (from the homepage hero, the dedicated
`/audit` page, or the dashboard overview — all three use the same
`AuditUrlForm` component) navigates to `/audits/{id}`, one adaptive
page (`AuditStatusPage`) that **serves progress, preview report, and
full report as one continuous view, not three separate pages** —
the backend already unifies "status" and "report, filtered by
access" into two endpoints, and the frontend mirrors that rather than
fragmenting one continuous experience into artificial page boundaries.
It polls `GET /api/audits/{id}` every 7s (deliberately under the
`throttle:10,1` ceiling those routes share — see the known-bug note in
§28 this fixes) until `completed`/`failed`, then fetches the report,
retrying a few times if `GenerateReportJob` hasn't finished yet. A 429
during polling triggers a longer backoff rather than a hard error.
Before submitting, `SubmitAuditPage` shows `PlanFeatureChecklist`
(`GET /api/me/features`, §33): which audit types will run automatically
under the current plan (✓ Included) versus which are locked (🔒 with an
"Upgrade to X" link) — informational only, since audit creation has no
per-request module-selection input; the backend derives which modules
actually run from the plan automatically (`AuditModuleRegistry::modulesFor()`).
`ReportView` renders the full spec'd section list (overview scores,
critical/high issues, other issues gated by `is_full_access`,
recommendations, AI analysis, page-level findings) plus a PDF-download
button that streams the file via `axios` (a plain `<a href>` can't
carry the bearer token) and surfaces the backend's real 403 message
when the viewer's plan lacks `pdf_export`.

**User dashboard** (`RequireAuth`): Overview (latest audit's scores,
website count, today's usage, quick audit form), My Websites
(`GET /api/websites`, §8 — one card per domain via `WebsiteCard`; adding
`?website=<domain>` to the same route swaps the grid for that website's
detail via `WebsiteDetail`, which is where audit history, score/issue
trends, pages, recommendations, the editable profile form and the
consultation CTA live, §37), Audits (`GET /api/audits`, §8 — **this one
page deliberately serves the spec's "Audits," "Audit History," and
"Reports" nav concepts**, since the backend has one audit-history
dataset, not three; clicking a row goes straight to its report),
Scheduled Audits (full CRUD against the existing `/api/audit-schedules`
from §22), Usage (today's count vs. the plan's daily limit, computed
client-side from the audits list — there's no dedicated "my usage
today" endpoint), Profile (**read-only** — no profile-update endpoint
exists) and Subscription (plan summary is read-only — no self-service
plan-change endpoint — but now also renders two live, non-static
panels: "Audit types on your plan" and "AI models available to you"
from `/api/me/features`/`/api/me/ai-models`, §33, each with an upgrade
link for anything locked).

**Admin dashboard** (`RequireAdmin`): Dashboard (every card and chart
from `AdminDashboardService::summary()`, §21/§23, including the new
`provider_usage_today` block), Users, Websites, Audits, Leads (status
management + notes, full CRUD against the Phase 7 endpoints), AI & API
Providers (§23's centerpiece — masked-key display, configure, enable/
disable, real test-connection with a live provider request, plus a
per-provider `AiModelManager` sub-panel — §33 — for the model catalog
CRUD, and the "Active model" field is a `<select>` sourced from that
catalog rather than free text once at least one model is registered),
API Usage (**serves both "API Usage" and "Logs"** — filterable/paginated
`api_usage_logs` table plus the summary/daily/by-provider charts),
AI Usage (the separate `ai_analyses`-based view, §23), Plans (**serves
both "Plans" and "Usage Limits"**, plus §33's feature/AI-model access
management — a table with Users/Features/AI Models/Status columns and
Edit/Duplicate/Enable/Disable/Delete actions; "Edit" opens a tabbed
editor — General, Pricing, Audit Features, AI Models, Report Access,
Usage Limits, Status — covering every field across both systems, plus a
"Global feature registry" panel above the table for the 7 features'
system-wide on/off switch), CMS (page + section management, including the free-text
section-key upsert from §7), Settings (a hub linking to the other
config pages rather than a fourth place to configure the same things),
System Health (real DB/cache round-trip checks via a new
`GET /api/admin/system-health` endpoint, §8 — queue/Redis/Horizon
status is reported as "configured connection name" only, not a live
heartbeat, because nothing in this stack currently exposes one — see
the honesty note in `AdminSystemHealthController`), Failed Jobs
(list + delete, matching the backend's deliberate no-retry design).

**Consolidations, stated plainly**: three places in the spec's nav
list name more pages than the backend has distinct datasets for
(Audits/Audit History/Reports; API Usage/Logs; Plans/Usage Limits).
Each is one real page/dataset wired to multiple nav labels rather than
two or three pages rendering the same query — this was a deliberate
choice to avoid literal duplication for its own sake, not a shortcut
that dropped functionality; every named capability is reachable.

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

### The audit entry point

Every URL a user submits passes through three stages before anything is
fetched, in this order, and **the string that comes out of stage 1 is the
string stages 2 and 3 use** — validating one spelling and then storing or
fetching another is the gap every SSRF filter bypass lives in:

1. **Normalize** (`App\Services\Crawler\UrlNormalizer`). Lowercases the
   scheme and host, applies the implied `https://` to a bare hostname,
   strips the fragment and any default port, collapses an empty path to
   `/`, removes the DNS root's trailing dot, and converts an IDN host to
   punycode. Rejects — never rewrites — anything it can't reduce to an
   unambiguous absolute http(s) URL: a non-http(s) scheme, embedded
   credentials, control characters or raw CR/LF, an over-long URL or host.
   A scheme it doesn't recognize is left intact so the allowlist gets to
   reject it; quietly prefixing `javascript:alert(1)` with `https://`
   would turn a rejected value into an accepted one.
2. **Validate** (`App\Services\Crawler\UrlSecurityValidator`) — below.
3. **Store and crawl the normalized URL**, and derive `websites.domain`
   from it, so the crawler's same-domain comparisons are plain string
   equality.

`CreateAuditRequest` applies `url:http,https` and a length cap first, but
the controller re-runs normalization and validation regardless: nothing
downstream may assume form-request validation ran.

### SSRF protection

Layered, not single-point, because DNS can rebind between a check and the
actual connection and redirects can retarget anywhere:

- **PHP layer** (`UrlSecurityValidator`): scheme (http/https only), port
  (80/443 only), no embedded credentials, bounded URL/query/host lengths,
  no control characters, and a host that is either a valid IP literal or
  a syntactically valid domain name. Every resolved address is checked
  against PHP's `FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE`
  **and** against `crawler.blocked_cidrs`, because those flags miss
  several ranges that are internal in practice — carrier-grade NAT
  (100.64/10), IETF protocol assignments (192.0.0.0/24), the benchmark
  range (198.18/15), and NAT64 (`64:ff9b::/96`, which embeds an arbitrary
  IPv4 destination). IPv4-mapped IPv6 is judged by the IPv4 rules, in
  both its dotted and hex spellings. `assertSafe()` returns the resolved
  addresses so a caller can pin rather than re-resolve.
- **A host that fails the IP check is not thereby a domain.** Alternate
  integer spellings — `2130706433`, `0177.0.0.1`, `0x7f.0.0.1`, `127.1` —
  fail `filter_var()` as IPs but resolve to loopback in Chromium and
  curl. A real TLD is never numeric, so a non-numeric last label is
  required. The same check rejects shell/SQL/XSS payloads in the host
  position before they reach a resolver, a subprocess argument, or a log
  line.
- **Node layer** (`crawler/lib/ssrf.mjs`): independently re-resolves and
  re-checks **every** request Playwright makes — the initial navigation,
  every redirect hop, every subresource, and every iframe — via
  `page.route()`. Subresources are checked too because a hostile page can
  point an `<img>` or an iframe at an internal address; the crawler never
  reads those bodies back, but the request still reaches the internal
  service. Blind SSRF is SSRF. Verdicts are memoized per hostname for the
  lifetime of one page load, so hundreds of subresources across a handful
  of hosts don't cost a DNS lookup each.
- The Node blocked ranges mirror `config/crawler.php`'s `blocked_cidrs`.
  The two lists can't share code across the language boundary, so they
  are kept deliberately in sync — a range added to one belongs in the
  other.

### Redirect validation

`App\Services\Crawler\SafeHttpClient` is the only way PHP makes an HTTP
request to a user-supplied address (robots.txt and sitemap.xml).
Validating a URL and handing it to a client that follows redirects itself
is not SSRF protection: the check applies to the first hop, and the
redirect chain is where the request actually ends up. So redirects are
disabled at the transport (`allow_redirects => false`) and followed by
hand — re-validating each hop, resolving relative and scheme-relative
`Location` headers against their origin before checking them, and
abandoning any chain longer than `crawler.max_redirects`. The concrete
attack this closes: a site that passes every up-front check answering
`/robots.txt` with a 302 into `169.254.169.254`.

`SafeHttpClient` throws on an unsafe address but returns `null` for an
unsuccessful, over-long, or over-budget response — so a caller can never
mistake a blocked SSRF attempt for a missing robots.txt.

### Limits

All config-driven (`config/crawler.php`), never hardcoded:

- **Response size**: `max_response_bytes` for PHP-side fetches, checked
  against `Content-Length` *and* enforced while reading, because
  Content-Length is absent on chunked responses and describes the
  compressed size on encoded ones. `max_file_size_mb` bounds the crawled
  document the same way — header first, then the transferred size CDP
  actually measured.
- **Redirects**: `max_redirects`, enforced in both `SafeHttpClient` and
  `crawl.mjs` (Chromium's own ~20-hop default is far looser than the
  crawler's budget).
- **Time**: `request_timeout` per PHP fetch, `page_timeout` per page
  navigation, and `crawl_timeout` as a wall-clock ceiling on a whole
  crawl, enforced in `CrawlPageJob`. The per-request timeouts only bound
  one fetch each — a site that answers every request slowly but
  successfully, or that keeps yielding new links, would otherwise hold a
  worker for hours. On timeout the crawl is *abandoned but still
  analyzed*: the pages already fetched are valid findings, and a user who
  waited out the timeout should not get nothing. The transition out of
  `Running` is a conditional update, so of the several in-flight page
  jobs that all reach the same conclusion, exactly one finalizes.
- **Concurrency**: `max_concurrent_crawls`
  (`App\Jobs\Middleware\LimitConcurrentCrawls` on `CrawlWebsiteJob`) caps
  how many audits crawl at once. The `crawler` rate limiter bounds how
  *fast* pages are fetched and says nothing about how many crawls run in
  parallel — without this, a burst of submissions starts a Chromium
  subprocess per audit and the box runs out of memory long before any
  rate limit is reached. Counting `crawl_jobs` rows rather than using a
  Redis semaphore keeps it working on every queue driver; an over-cap job
  is *released*, not failed.

### Sitemap parsing

`simplexml_load_string` is called with `LIBXML_NONET` (so the parser
can't become its own SSRF vector by fetching an external DTD) and
deliberately **without** `LIBXML_NOENT` — leaving entity substitution off
is what keeps a hostile sitemap from reading local files (XXE) or
expanding into gigabytes (billion laughs). Sitemap seeds from a
`Sitemap:` line in robots.txt are filtered by domain like every nested
entry, not trusted because of where they came from: robots.txt is
attacker-controlled content on the audited site and can name any host it
likes.

### Frontend validation

`frontend/src/lib/url.ts` (`checkAuditUrl`) exists for the error message,
not for the security — it decides what the user is told, never what the
server does. Anyone can skip it by calling the API directly, and the
backend re-normalizes and re-validates every submission regardless. Its
internal-host list is deliberately short and obvious rather than a copy
of the server's ranges: a partial copy that drifts is worse than none.

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

**Guest abuse protection**: the anonymous limit is a *lifetime* limit
keyed on IP (§19), on top of the throttle above. Page limits, `user_id`
and status are all server-derived — `AuditController::store()` reads only
`url` from the payload, so a guest cannot raise its own page cap, claim
an account, or pre-set an audit's status by adding fields to the request.
A refused audit creates no `websites`, `audits` or `usage_logs` row, so a
rejection leaves nothing behind to be picked up later and does not burn
the caller's free audit.

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
Two pieces of out-of-phase-order work also landed after Phase 9: the
DB-backed AI/API provider config + usage tracking system (§23) and the
full frontend (§5/§24) — the original 10-phase plan never allocated a
phase to the frontend at all.

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
| — Provider config + usage tracking, full frontend (out of phase order) | — | ✅ done — §5, §23, §24 |
| 10. Production | 59-60 | ⬜ not started |

## 28. Known bugs

None currently open.

**The failure path could not record a failure** (§49, found in
production). `audits.error_message` was `varchar(191)` — a width every
`string()` column inherits from `Builder::defaultStringLength(191)`, which
exists so utf8mb4 *indexes* fit MySQL 5.7's 767-byte key limit and has
nothing to do with prose. `AuditLifecycle::markFailed()` truncated to
**250**.

So any failure whose message ran past 191 characters made the write that
*records* the failure throw. The job died mid-transition, and the audit
was stranded in `crawling` holding its owner's concurrent slot forever —
precisely the state §38 exists to make impossible, defeated by a column
width. Worse, the message explaining the original failure was destroyed
along with it, so the actual fault was invisible.

Both columns and limit are fixed: `audits`, `crawl_urls` and `crawl_jobs`
now use TEXT, and the truncation limit is a single named constant
(`AuditLifecycle::MAX_ERROR_MESSAGE`) shared by both write sites, so the
two can no longer drift from each other or from the schema. The limit is
now editorial — a 50KB stack trace helps nobody reading a report — rather
than structural.

**Operational note, not a code bug:** the crawl failure this exposed was a
**stale `queue:work` worker**. A worker boots the application once and
holds it: it does not see code changes, and its environment is frozen at
launch. The running worker could not resolve Playwright's browser while
the identical crawl succeeded from a fresh process. `php artisan
queue:restart` fixed it. Any deploy or dependency change needs the same.

**Found and fixed in the Phase 1-11 review pass**, which for the first
time ran the whole stack end to end — every migration applied to a fresh
database, a seeded audit served over HTTP, and the report driven in a real
browser:

- **Screenshots were rationed like audit creation.** The signed screenshot
  route sat inside the audit group's `throttle:10,1`. One report view
  loads two screenshots per crawled page — fifty requests on a 25-page
  audit — so all but the first ten returned 429 and rendered as
  "Screenshot unavailable". The limit exists to bound audit *creation*;
  serving a stored PNG is nothing like it. Now its own throttle, outside
  that group.
- **The site classification never reached a reader.** §46's classification
  was added to `ReportOverviewCard`, which only the legacy combined view
  uses. The report page users actually land on builds its own header, so
  the thing that explains *why* Ecommerce and Product were skipped was
  invisible on the page listing them as skipped.
- **`ServiceKey` was missing `product_auditor`.** The API had returned
  seven services since §36 and the TypeScript union listed six, so the
  type could not catch anything that forgot the product report.
- **New environment variables were undocumented.** Everything added in
  §43-§48 — screenshot disk and URL TTL, branding disk and logo cap,
  backlink driver and cache TTL, accessibility evidence limits — has
  sensible defaults, so nothing broke, but a production deployment had no
  way to discover them. Now in `.env.example`.
- **The og:image preview loaded a third-party image bare.** It is the
  audited site's own asset and a plain `<img>` is right, but it now sends
  no referrer (keeping the reader's report URL out of that host's logs)
  and hides on error, since an `http:` og:image on an https page is common
  and would otherwise show a broken-image icon.

**Found and fixed in the Phase 1-7 cross-phase review pass** (each pinned
by a test that fails against the old code):

- **A lapsed subscriber kept their paid plan's feature access, forever.**
  `FeatureAccessResolver::planFor()` read `$user->plan()` — a bare
  relation that says nothing about whether the subscription is still
  active or the plan still on offer. That resolver decides which audit
  modules run and which AI models an account reaches (§33), so an expired
  subscription kept every paid capability indefinitely. Now resolved
  through `EntitlementResolver` (§40), which is the whole reason that
  class exists. Guests and users with no subscription still fall back to
  the default plan, exactly as before.
- **Scheduled audits crawled at the lapsed plan's page limit.** Same root
  cause, in `AuditSchedulerService::runOne()`: `$user->plan()?->max_pages`.
  A schedule fires forever, so this handed out paid crawl budget
  indefinitely in a background job nobody watches. Now reads
  `EntitlementResolver`, like the audit-creation endpoint already did.
- **Every per-service PDF shipped with a blank site URL.**
  `service-pdf.blade.php` rendered `$website->url`, a column renamed to
  `primary_url` in §37. Blade renders a missing attribute as an empty
  string, so nothing failed and the existing PDF test — which asserted
  only that the bytes were a PDF — could not see it. The replacement
  assertion targets the header block specifically, because the audited
  domain appears throughout the body and a plain substring check passes
  even when the header is empty.
- **Eighteen of Phase 7's SEO issue codes fell into "Other SEO".**
  `IssueTaxonomy` was not extended alongside the new checks — the exact
  regression that had already happened once with the performance codes
  (TEST_CASES item 19). Every SEO code is now mapped and covered by a
  data-provider test, and schema codes (whose tail is the schema.org
  type) are matched by prefix so adding a type to `config/seo_schema.php`
  cannot silently drop its findings.
- **Two mappings of axe rules had drifted apart.** `IssueTaxonomy` kept
  its own ~22-rule copy of what `AccessibilityCheck` (§43) maps with ~90.
  A rule known to one and not the other was grouped as "Contrast" in the
  accessibility rollup and "Other Accessibility" in the issue list *of
  the same report*. The taxonomy now delegates to `AccessibilityCheck`,
  so there is one mapping.
- **The backlink provider was called on every report render.**
  `ServiceReportBuilder` asks for backlinks each time the SEO service
  report is built — the web view, every PDF export, every refresh — which
  with a real provider is one billed third-party call per page view.
  `BacklinkService` now caches successful answers for
  `config('backlinks.cache_ttl')`. Failures are deliberately **not**
  cached: an outage must be able to clear the moment the provider
  recovers, or "we could not find out" gets pinned in place for an hour.
- **`AuditScheduleController::store()` looked a website up by bare id**
  and checked ownership afterwards. Not exploitable — the check was
  correct — but it violates §37's rule and confirmed the row existed to
  someone who did not own it. Now scoped to `$user->websites()`.

**Found and fixed in the Phase 1-3 review pass** (each pinned by a test in
`AuditPipelineIntegrityTest`; several were cross-phase, where the §38
lifecycle rework broke an assumption older code relied on):

- **Terminal audits leaked the global crawl slot.** Nothing closed the
  `crawl_jobs` row when an audit failed, was cancelled, or was recovered
  as stuck, so each one stayed `running` forever. `LimitConcurrentCrawls`
  counts exactly those rows, so after `max_concurrent_crawls` such audits
  (default 5) **crawling would stop system-wide, permanently, with
  nothing to explain why**. Now closed in `AuditLifecycle::transition()`,
  the one place terminal transitions happen. Only *running* rows are
  touched — a crawl that finished normally keeps its real `completed_at`.
- **Every audit module could run twice.** `CrawlPageJob::finalizeIfComplete()`
  updated the crawl job unconditionally, so two workers finishing the last
  two pages at the same moment both saw "nothing pending" and each
  dispatched a full round of module jobs. Now uses the same conditional
  claim `abandon()` already had.
- **Reports shipped `audit_date: null`.** §38 moved the completion stamp
  to *after* report generation (so `completed` means "there is a report"),
  but `ReportBuilder` read `completed_at`, which was still null at that
  moment. Now falls back to `now()`, which on the first build is the same
  instant.
- **Scheduled audits bypassed the concurrent-audit limit.**
  `AuditSchedulerService::runOne()` created audits directly, so a daily
  schedule firing while yesterday's audit still ran would stack past the
  plan's limit. Now goes through `ActiveAuditLimiter::withSlot()`. A
  skipped run still advances `next_run_at` (or the scheduler would retry
  it on every tick for as long as the blocking audit runs) but does not
  set `last_run_at`, so the skip stays visible as a gap.
- **Anyone could cancel an anonymous audit** by guessing its ID. Reading
  one is an accepted gap (§28/§20) because reading changes nothing;
  cancelling destroys a running audit. Anonymous cancellation is now
  restricted to the IP that created it (recorded in `usage_logs`). Weak
  identity, but it turns "anyone" into "the same network".
- **`RunAuditModuleJob`'s `$tries = 2` was dead code.** The handler caught
  the first throw and wrote the category off immediately, so a transient
  failure permanently marked a category unassessable. It now rethrows
  while attempts remain. Note `hasAttemptsLeft()` also has to exclude the
  `sync` connection: sync runs jobs inline and **never retries**, yet
  still reports `attempts() = 1`, so counting attempts alone would lose
  the graceful degradation entirely under that driver. Three were found and fixed during development
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

- Encrypting an empty string still produces a non-null ciphertext
  blob — `ApiProvider::isConfigured()` originally checked raw
  ciphertext presence (`! empty($this->attributes['api_key'])`), so a
  provider whose `.env` variable was set-but-empty (e.g. `GEMINI_API_KEY=`
  with nothing after it) got seeded with `api_key = ''`, which then
  read as "configured" even though there's no usable key. Caught during
  manual browser verification of the admin Providers page (Gemini
  showed "Configured" with no key visible). Fixed in three places:
  `isConfigured()` now decrypts and checks the real value;
  `ProviderSeeder` normalizes an empty env value to `null` before
  seeding; `AdminProviderController::update()` normalizes an empty
  submitted `api_key` to `null` too. (Provider config work)
- The audit status/report polling loop (`AuditStatusPage`, §24) started
  at a 3s interval, exceeding the `throttle:10,1` (10/min) limit those
  routes share — any audit taking longer than ~30s to complete
  triggered a real 429 that the page surfaced as a hard error. Found
  by actually running a live audit against `example.com` during manual
  verification. Fixed by slowing the interval to 7s (safely under the
  6s/request ceiling) and treating a 429 during polling as "wait
  longer," not "something is wrong" — it backs off and retries instead
  of failing. (Provider config work / frontend)
- `AuthController::register()`/`login()` returned the bare `$user`
  model with no `subscription`/`plan` relation loaded, unlike `me()`
  (which already had `.load('subscription.plan')` from Phase 9's audit-
  ID-ownership work). The dashboard's "next available audit"/daily-limit
  hints silently showed nothing until the next full page load re-fetched
  `/api/user`. Found the same way — running the real signup flow in a
  browser and noticing the plan-dependent UI was blank. Fixed by adding
  the same eager-load to both. (Frontend)

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

- **Redis is not installed** on this dev machine. `.env` now declares the
  `database` queue and cache drivers outright rather than overriding
  Redis per command, and `audit:crawl` reports the configured connection
  instead of a hardcoded guess (§56). `.env.example` still shows Redis as
  the intended production driver.
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
- **No "promote user to admin" endpoint** — deliberate (§9): an HTTP
  route that grants admin is a route that can be found. Bootstrapping is
  now `php artisan make:admin <email>` rather than a hand-written DB row
  (§59), which also rotates a password via `--password` — the only way to
  change one, since there is still no self-service password change.
- **No failed-job retry endpoint** — list and delete only (§21).
- **No plans are seeded except the free default.** Paid tiers don't
  exist until an admin creates them via `POST /api/admin/plans` — no
  concrete pricing/limits were invented since the spec doesn't specify
  them and "complex billing" is an MVP exclusion (see Phase 6's
  `PlanSeeder` comment).
- **No automated frontend test suite** (no Vitest/React Testing
  Library). Verification for the frontend work was `tsc -b && vite
  build` (zero errors, `noUnusedLocals`/`noUnusedParameters` on),
  `oxlint`, and manual exercise of every flow against the real running
  backend in a browser — not a substitute for real component/unit
  tests, but real functional verification, not a claim based on reading
  the code. Add a test runner before this frontend grows much further.
- ~~A failed "Test Connection" doesn't record a response time~~ —
  **resolved in §56.** `AiProviderException` now carries
  `responseTimeMs`, set on both failure paths (transport error and API
  error) in all three providers, so the admin average-response-time
  figure no longer silently excludes the calls most worth timing: a
  provider that is timing out is slow before it is broken.
- **PageSpeed's "Test Connection"** runs a real PageSpeed Insights
  call against a fixed test URL (`https://example.com`), which is slow
  (10-30s, per §14) — the admin UI has no separate longer timeout or
  "this may take a while" messaging for that one provider's test
  button specifically.
- ~~`product_auditor` has no backing `AuditModule`~~ — **resolved.**
  `ProductAuditModule` exists, is registered in `AuditModuleRegistry`,
  and produces real `product_auditor` scores (verified against live
  audits in §56). The feature flag now gates something.
- ~~A plan can only allow a provider's one currently-active model~~ —
  **resolved in §53.** `AiProvider::analyze()` takes a model override,
  threaded through all three provider classes, and `PlanAiModelResolver`
  picks the cheapest plan-allowed model whose tier meets the task. The
  old "model_id must match the provider's live `$model`" rule survives
  only as the fallback for when nothing meets the required tier.
- ~~No per-user website-count cap~~ — **resolved in §40.**
  `plans.max_websites` exists and is enforced by
  `UsageLimitService::websiteRefusalReason()` via `WebsiteResolver`,
  covered by `EntitlementTest`.

## 30. Pending tasks

Phase 9's hardening pass closed the audit-ID-enumeration gap and the
unthrottled-auth-endpoints gap; the remaining §29 items
(IP-based-anonymous-tracking, no "promote to admin" endpoint, no email
verification/password reset, Sanctum tokens never expiring) were
deliberately left as accepted limitations or deferred product decisions
rather than being fixed speculatively — revisit only if real usage
shows they're actually load-bearing problems, not preemptively.

**Out-of-spec-order work, now done**: the DB-backed AI/API provider
configuration and usage-tracking system (§23), the full frontend
(public site, user dashboard, audit flow, admin dashboard,
provider-config UI, usage analytics — see §5/§24), and the plan-based
audit-feature/AI-model access-control system (§33) were requested
directly, ahead of Phase 10, since the original 10-phase plan never
allocated a phase to the frontend, or to plan-level feature/model
governance, at all.

**Next up in spec order: Phase 10: Production** — the last phase.
Candidates for that pass, not yet started: no automated frontend test
suite exists yet (§29/§32); real AI provider keys have never been
exercised against a live provider from this codebase (§29); `.env`'s
`APP_DEBUG` needs to be confirmed `false` for a real deployment (this
dev environment intentionally runs with it on); production queue
worker/Horizon setup (this dev box can't run either — no `pcntl`/
`posix` on Windows, §25).

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
- **Tailwind CSS v4 + plain `axios`/`useEffect`, no data-fetching
  library** (§5) — Tailwind for fast, consistent styling without
  hand-rolled CSS at this scale; skipping TanStack Query/SWR because
  every page's fetch need (load on mount, refetch on an explicit
  action) is simple enough that the library's caching/dedup machinery
  isn't earning its cost yet — revisit if the app grows enough shared-
  data-across-pages need to justify it.
- **`AuditStatusPage` is one adaptive page for progress + preview
  report + full report, not three routes** (§24) — the backend already
  collapses "status" and "report, filtered by `is_full_access`" into
  two endpoints; three separate frontend pages would either duplicate
  the polling/fetch logic three times or awkwardly share it across
  route boundaries for no real benefit, since it's genuinely one
  continuous user experience (submit → wait → see results).
- **Three nav concepts each collapse onto one real page/dataset**
  (§24: Audits/Audit History/Reports; API Usage/Logs; Plans/Usage
  Limits) — the backend has one dataset for each pair/triple, not
  several; building visually-identical pages against the same query
  would be duplication with no functional gain. Every named capability
  in the original nav list is still reachable, just not fragmented into
  more page loads than the data supports.
- **Profile and Subscription pages are read-only** (§24) — there is no
  profile-update or self-service plan-change endpoint on the backend.
  An editable form with nothing real to submit to would either silently
  no-op or call an endpoint that doesn't exist; showing accurate
  read-only data was judged more honest than either.
- **PDF download goes through `axios` as a blob, not a plain
  `<a href>`** (§24) — the endpoint requires the Sanctum bearer token,
  which a plain link has no way to attach; a blob fetch keeps the
  existing bearer-token interceptor in the request path.
- **A new `features` + `plan_features` pivot, not a reuse of the
  existing `plans.features` JSON column** (§33) — the JSON column already
  has an established, different meaning (pdf_export/scheduled_audits/
  audit_history boolean perks) and, being per-plan-only, can't represent
  a *global* enable/disable independent of any one plan, which the spec
  explicitly required ("if a feature is disabled globally, it must
  become unavailable to every plan"). Renaming or overloading the
  existing column would have broken every existing caller of
  `Plan::hasFeature()`/`Plan::$features` for no benefit; a same-named-but-
  different relationship (`auditFeatures()`) was judged less confusing
  than colliding two concepts under one column.
- **Category-to-feature-key mapping is a small static array in
  `FeatureAccessResolver`, not a DB column on `features`** (§33) — which
  `AuditCategory` values a feature key gates is application logic fixed
  by real code (which `AuditModule` classes exist), not admin-editable
  data; a DB column would imply an admin could remap it to something with
  no backing module.
- **`features` rows are seeded once and not admin-creatable** (§33,
  mirrors the existing `api_providers` design in §23) — each of the 7
  keys corresponds to a real `AuditCategory`/module (or, for
  `product_auditor`, a deliberately reserved future one); an
  admin-invented feature key would have no audit logic to gate, the same
  reasoning that keeps provider slugs fixed to the 4 this app actually
  knows how to call.
- **`ai_models` is a new catalog table, not new columns on
  `api_providers`** (§33) — a provider can expose many models with
  independent cost/context-window/capability data (Claude Haiku vs.
  Opus pricing, for instance), which a single-row-per-provider table
  can't represent; `api_providers.model` keeps its existing meaning
  (the one model that provider is *currently* actively calling).
- **`PlanAiModelResolver` requires an allowed model's `model_id` to
  match its provider's live `$model`, rather than trusting "the plan
  allows it" alone** (§33/§29) — no provider class supports being told
  which model to call per-request, so "allowed by the plan" is only
  actually true right now for whichever model a provider happens to be
  actively configured for. This is a real architectural constraint, not
  an oversight, and is documented as a known limitation (§29) rather
  than silently pretending full multi-model selection works.
- **Feature/AI-model "no plan exists at all" resolves to unrestricted,
  not denied** (§33) — a fresh/test DB with no `PlanSeeder`/`FeatureSeeder`
  run (or, for `PlanAiModelResolver`, no plan at all) falls back to the
  pre-existing "every module runs, `AI_DEFAULT_PROVIDER` is used"
  behavior rather than blocking every audit module outright. Without this,
  every pre-existing test and CLI flow that never bothered to set up a
  Plan fixture (because it never mattered before this feature existed)
  would have started failing/skipping AI analysis — verified by running
  the full pre-existing suite unmodified after adding the gate and
  confirming all 299 prior tests still passed before any new tests were
  written.
- **A plan's audit-feature toggles gate which `RunAuditModuleJob`s get
  dispatched, not a new field on the `POST /api/audits` request** (§33)
  — the product spec's "audit_selection" UI example reads as informational
  (what will run), not a per-submission opt-out of otherwise-allowed
  modules; adding a module-selection input to audit creation would be a
  larger, separately-scoped change to an existing, well-tested contract
  for a capability nothing in `definition_of_done` actually required.
- **No new `audits_per_month`/website-count-limit columns** (§29) — the
  product spec's example plan limits included them, but the feature/
  AI-model access-control task's own `definition_of_done` never
  mentioned them; inventing new enforced limit dimensions outside what
  was actually asked for risked scope creep unrelated to the task's
  actual goal (feature/model *access*, not new usage-limit types).
- **A plan gets an `is_active` boolean distinct from `is_default`**
  (§33) — the spec explicitly named a "Status" column and Enable/Disable
  actions in the admin plan table, which `is_default` (single-plan,
  "which plan new signups join") can't express; `is_active = false`
  hides a plan from `/api/plans` and blocks new default-plan selection
  while preserving it (and its subscribers' history) rather than
  requiring deletion.

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
decoded JSON path), and real daily/date-range aggregation queries. Two
small self-service endpoints (`GET /api/websites`, `GET /api/audits`)
and one public one (`GET /api/plans`) were added directly for the
frontend to consume, each with real ownership-scoping tests
(`AuditApiTest`). The plan-feature/AI-model access system (§33) drove
41 more: `FeatureAccessResolverTest`/`PlanAiModelResolverTest` (unit,
covering the "no plan exists at all → unrestricted" fallback that keeps
every pre-existing plan-agnostic test passing unmodified), a dedicated
`AuditModuleRegistryFeatureGatingTest` that reuses
`CrawlAndAnalyzePipelineTest`'s proven "flawless page" fixture to prove
a plan's feature toggles actually change which modules run for a real
audit (not just what the API reports), `AdminAiModelApiTest`, `MeApiTest`,
new `AdminApiTest`/`CascadeDeleteTest` cases, and the empty-string-key-
style regression discipline extended to `plan_ai_models` duplicate-
assignment rejection. 340 PHP tests currently, all passing.

Crawler: `crawler/lib/*.test.mjs`, run via `npm test` (Node's built-in
`node --test`, no added dependency). Covers the SSRF logic
(`isPrivateAddress`, `assertHostnameIsPublic`) directly — the same
defense-in-depth layer described in §26, tested independently of the PHP
side since the two can't share code across the language boundary. 62
Node tests currently, all passing.

**760 tests total** (698 PHP + 62 Node). See `tests/TEST_CASES.md` at the
repo root for the full registry mapped to test-requirement categories,
coverage status, and any remaining gaps.

Frontend: no automated test suite (§5/§29) — verification was
`tsc -b && vite build` (zero errors), `oxlint`, and manual exercise of
every page against the real backend in a browser: signup → dashboard
(plan data correctly present, per the register/login bug fix in §28) →
submitting a real audit against `example.com` → watching it process
through a real queue worker → viewing the completed report (all six
category scores, expandable issues, recommendations) → a PDF-download
attempt correctly blocked with the real 403 message (free plan lacks
`pdf_export`) → the admin dashboard (real cards/charts reflecting that
same audit and its AI-call attempts) → configuring a provider with a
fake key → Test Connection making a real request to the real Anthropic
API and surfacing the real 401 safely, with no credential leakage
anywhere in the response → the mobile public-nav menu. This caught three
real bugs (§28), which is the point of actually running the app instead
of only reading the code — but it is not a substitute for an automated
suite, which this project still doesn't have for the frontend.

E2E: none exist yet — no Cypress/Playwright browser-automation suite
is wired into this repo's test commands. The manual verification pass
above exercised the equivalent flows by hand, once, in this session;
it is not regression-tested and will not catch a future break.

**§33's manual verification** (this chunk): admin login → Pricing Plans
page (global feature registry panel + plan table with real Users/
Features/AI Models/Status columns) → opening the tabbed plan editor and
confirming the AI Models tab lists all 3 seeded models grouped by real
provider → the Providers page's new per-provider "Model catalog" panel →
adding a real "Claude Opus 5" model through the UI, confirming it
appeared in both the catalog list and the "Active model" dropdown, then
deleting it → toggling "AI Product Auditor" off in the global registry
and confirming it disappeared entirely from both the public Pricing
page's comparison table and `SubmitAuditPage`'s `PlanFeatureChecklist`
(not shown as locked — genuinely absent, since a globally-disabled
feature isn't advertised anywhere), then toggling it back on → the
dashboard Subscription page correctly reporting zero available AI
models for a real account, because in this dev environment every AI
provider is currently disabled (proving the "provider must also be
enabled" leg of the access-check chain, not a bug — see the Providers
page's own status badges).

## 33. Plan-based audit-feature and AI-model access control

Extends the existing pricing-plan system (§7/§19/§21/§24, previously
just page/audit-count limits and unrelated boolean perks in
`plans.features`) so an admin can independently enable/disable each of 7
audit types per plan, and independently choose which AI models a plan's
users may have audits analyzed with — enforced server-side, not just
hidden in the frontend, per the product spec's explicit requirement.

**The 7-feature registry** (`features` table, `App\Models\Feature`,
seeded once by `FeatureSeeder`, not admin-creatable — same reasoning as
`api_providers`' fixed 4 slugs, §23): `performance`, `accessibility`,
`cro`, `seo`, `aio`, `ecommerce` (covers *both* `AuditCategory::Ecommerce`
and `::LandingPage` — the spec names this one feature "AI Ecommerce /
Landing Auditor," combining what are two separate audit modules
internally), and `product_auditor` (reserved — no backing module exists
yet, §29). Each has a global `is_enabled` (admin toggle in the Plans
page's "Global feature registry" panel) *and* a per-plan `plan_features`
pivot `is_enabled` (the Plan editor's "Audit Features" tab) — **both**
must be true for a feature to actually be usable; disabling a feature
globally removes it from every plan immediately regardless of any
individual plan's own setting, per the spec's explicit rule.

**`App\Services\Plan\FeatureAccessResolver`** is the single place that
answers "is this category/feature allowed for this user (or anonymous
visitor, resolved to the active default plan)":
`categoryAllowed(?User, AuditCategory)`, `featureAllowed(?User, string)`,
`allowedCategories(?User)`. A static `CATEGORY_FEATURE_MAP` const (not a
DB column, §31) maps each `AuditCategory` to its feature key.
**Deliberate fallback**: if no plan exists in the system at all
(`planFor()` returns `null` — no `PlanSeeder`/`FeatureSeeder` ever run,
or a registered user with no active subscription, which
`UsageLimitService::canCreateAudit()` already blocks at audit creation,
making that sub-case unreachable in practice), every feature resolves as
*allowed* rather than denied — see §31's rationale; this is what kept
every pre-existing plan-agnostic test passing unmodified.

**Audit execution enforcement**: `AuditModuleRegistry::modulesFor(Audit)`
filters `modules()` down to only the categories the audit's owner's plan
(or the default plan, for an anonymous audit) allows, via
`FeatureAccessResolver`. `dispatchAnalysis()` uses this filtered list
when deciding which `RunAuditModuleJob`s to dispatch — and dispatches
`CalculateScoreJob` directly if the filtered list is empty (every
feature disabled for this plan), since no module job would otherwise
ever run to trigger it. `RunAuditModuleJob::dispatchScoreCalculationIfReady()`
was changed to check `modulesFor($this->audit)` (this specific audit's
allowed set) rather than the full static `modules()` list — using the
unfiltered list here would leave an audit stuck in `Analyzing` forever
whenever its plan disables any category, since the "all done" check
would wait for a score row that will never be written. There is
deliberately **no per-request module-selection input** on
`POST /api/audits` (§31) — which modules run is fully determined by the
plan automatically.

**AI model access** (`ai_models` table, `App\Models\AiModel` — a catalog
of individually addressable models per provider: model ID, display
name, description, enable/disable, input/output cost per 1k tokens,
context window, capabilities, distinct from `api_providers.model`, the
one model a provider is *currently actively calling*). Plans reference a
subset via `plan_ai_models` (`Plan::allowedAiModels()`,
`Plan::isModelAllowed()`). **`App\Services\AI\PlanAiModelResolver`**
picks which provider `AiAnalysisService::analyze()` actually calls for a
given audit: loads the audit's plan's allowed models, filters to ones
that are enabled *and* whose owning provider is enabled+configured *and*
— critically — whose `model_id` matches that provider's live `$model`
(§29/§31: no provider class supports being told which model to call
per-request, so a plan-allowed model that isn't also the provider's
current active one has nothing to actually call), then prefers whichever
matches `config('ai.default')`, falling back to any other allowed+usable
one. Returns `null` (AI analysis skipped for that audit, exactly like
the pre-existing "provider not configured" graceful-degradation path — no
`api_usage_logs` row is written, since no provider was ever chosen to
attribute one to) when nothing qualifies. Same "no plan exists at all"
fallback as `FeatureAccessResolver`: falls back to
`AiProviderFactory::make()` (the pre-existing `AI_DEFAULT_PROVIDER`
behavior) rather than blocking AI outright.

**Admin API** (§8): `PUT /admin/plans/{plan}/features` (bulk `sync()`
of the whole per-plan feature-enable set — not a per-key PATCH, so the
admin UI's checkbox grid submits atomically), `PUT
/admin/plans/{plan}/ai-models` (bulk-replace the allowed-model-ID list),
`GET /admin/features` + `PATCH /admin/features/{feature}/status`
(global enable/disable only), full CRUD on `GET/POST /admin/ai/models`,
`PUT/DELETE /admin/ai/models/{aiModel}` (delete is blocked with 409
while any plan still allows the model — historical `api_usage_logs` rows
store the model as a plain string, §23, so they're never at risk from a
catalog deletion; the 409 is a UX safety net, not a data-integrity
requirement). `AdminPlanController::index/store/update` now eager-load
`subscriptions_count`, `auditFeatures`, `allowedAiModels.apiProvider` for
the admin table/editor.

**Self-service API**: `GET /api/me/features` (optional auth, §8) returns
every *globally*-enabled feature with `enabled: bool` for the caller's
plan and, when locked, `plans_with_access` (which other active plans
include it, for the upgrade CTA) — a globally-disabled feature is
omitted entirely, not shown as locked, since it isn't actually available
on any plan right now. `GET /api/me/ai-models` returns the caller's
currently-usable models with no cost fields (admin-only data). No
`/api/me/plan` — `/api/user` already eager-loads `subscription.plan`
(§8), so reusing it avoided a duplicate endpoint per the spec's own
"reuse existing endpoints" instruction.

**Frontend**: the public Pricing page (§24) renders a feature-comparison
table from the union of every returned plan's `audit_features`;
`SubmitAuditPage` shows `PlanFeatureChecklist` (✓ Included / 🔒 Upgrade
to X) before the URL form; `DashboardSubscriptionPage` gained two live
panels ("Audit types on your plan," "AI models available to you"); the
admin Plans page became a table (Plan/Price/Users/Features/AI Models/
Daily Limit/Status/Actions) plus a tabbed editor (General, Pricing,
Audit Features, AI Models, Report Access, Usage Limits, Status) and a
"Global feature registry" toggle panel; `ProviderCard` gained an
embedded `AiModelManager` sub-panel (per-provider model catalog CRUD)
and its free-text "Model" field became a `<select>` sourced from that
catalog once at least one model is registered for that provider — per
the spec's "load dynamically from the registry, don't hardcode a model
list" instruction.

**Scope deliberately not covered here** (§29/§31): no new
`audits_per_month`/website-count-limit columns (not in this task's
`definition_of_done`, unlike the feature/model system); no backing audit
module for `product_auditor` (a new audit engine is separate scope from
plan/feature *governance*); no true concurrent multi-model dispatch per
provider (an AI-infrastructure change beyond this task, see §29's
architectural-constraint note).

## 34. Audit scoring accuracy, ecommerce detection, and page-level issue/screenshot architecture

A real audit run against a live, real-world site
(`beautifulashesministries.org`, a nonprofit ministry — not the kind of
synthetic fixture the existing unit tests used) surfaced bugs the unit
suite's flawless-page/single-page fixtures never exercised: `seo`/
`performance` scoring at 0 on a perfectly normal multi-page site, and a
false-positive Ecommerce score on a site that sells nothing. Fixed by
inspecting real production data first (per this task's own "accuracy
over score" instruction), not by trusting the bug report's claims at
face value — one of the two claimed ecommerce false positives was
initially "fixed" incompletely and only caught by re-running the same
real audit again after the fix, see below.

**Root cause of the SEO/Performance zero-score bug**: `SeoAuditModule`
and `PerformanceAuditModule` raised one `AuditIssue` *per page* for a
structural, site-wide problem (e.g. "missing meta description" on every
page of a 25-page site) — 25 near-identical "high" severity deductions
(250 points) floors a 100-point score at 0 regardless of how the site
actually looks. `AccessibilityAuditModule` already had this right
(aggregates by axe rule ID, `config('audit_scoring.accessibility.max_scored_violations')`
caps deductions) — Seo/Performance just never got the same treatment.

**`App\Services\Audit\PerPageIssueAggregator`** is the fix, extracted so
both modules (and any future one) share it rather than duplicating the
aggregation logic: given an array of per-page `AuditIssueData` findings
(one per page, codes may repeat), it collapses same-code findings into
one issue. A code with exactly one occurrence passes through unchanged
(keeps its own `audit_page_id` and `meta`); two or more become one
issue with `audit_page_id = null` and `meta.affected_pages`/
`affected_page_count`, mirroring the pattern `AccessibilityAuditModule`
already used for axe violations. Each check method in
`SeoAuditModule`/`PerformanceAuditModule` was changed to move any
page-varying number (length, count, hops, broken-link list) out of its
message string and into `meta`, so a message reads the same whether
aggregated or not (`PerPageIssueAggregator::withPageCount()` just strips
the trailing `": {$url}"` convention every check method's message
follows and appends `"(N pages affected)"`).

**Ecommerce false-positive detection** (`App\Services\Audit\EcommerceDetector`):
replaces a single loose regex (`/\b(buy|add to cart|checkout|shop now)\b/i`
against any CTA text) with a weighted, multi-signal confidence score —
Product structured data (3, strong), Shopify/WooCommerce platform (3),
an "add to cart"-specific CTA (3), price/SKU in structured data (2), a
cart/checkout-shaped *link path* (1, weak), 2+ pages matching a
product/shop URL pattern (1, weak), a generic transaction-adjacent CTA
word like "buy"/"checkout" alone (1, weak). `EcommerceAuditModule` only
runs its deterministic/AI checks when the weighted score clears
`config('audit_scoring.ecommerce.min_confidence_score')` (default 3);
below that, it returns `isApplicable: false, score: null` — Not
Applicable, never a fabricated 100 *or* a fabricated positive score.
**The cart/checkout-link signal was originally weighted 2 (medium), not
1** — a first pass at this fix still produced a false positive on the
same real ministry site, because its event-ticket order flow has both a
`/tickets/checkout/`-shaped link (weight 2) and a generic "Checkout" CTA
(weight 1), summing to 3 with zero actual product evidence anywhere on
the site. Caught only by re-running the *same* real audit after the
first fix and inspecting the actual detection signals
(`EcommerceDetector::detect()->signals`) — lowered to weight 1 (as
ambiguous as the generic-CTA-text signal it sits next to) after that.
Both false positives are now regression tests
(`EcommerceAuditModuleTest::test_a_lone_generic_checkout_cta_does_not_trigger_ecommerce_detection`
and `test_a_checkout_link_plus_a_generic_cta_alone_does_not_trigger_ecommerce_detection`).

**"Not Applicable" as a first-class state, not a fabricated score**:
`audit_scores.score` is nullable, with a new `is_applicable` boolean
(default true). `AuditModuleResult` gained `?int $score` (nullable),
`bool $isApplicable = true`, `?string $notApplicableReason`, and
`string[] $detectionEvidence` (the signals behind an applicability
decision, for report transparency). `ScoreCalculator::calculateOverall()`
excludes any `is_applicable = false` (or `score === null`) row from
both the weighted sum and the denominator — a Not Applicable category
never silently counts as 0 or drags down the overall score, per this
task's explicit "do not include Not Applicable categories in the
denominator" rule. `RunAuditModuleJob`'s "are all modules done yet"
check needed no change: a Not Applicable category still gets an
`audit_scores` row (just with `score = null`), so it still counts as
"this category finished" — only the *value* changed, not whether a row
exists.

**AI-unavailable visibility** (a regression introduced by §33's own
`PlanAiModelResolver` work, caught while re-verifying against this
task's "AI provider unavailable" requirement): when no plan-allowed AI
model is usable, `AiAnalysisService::analyze()` previously returned
`null` with **no record anywhere** — indistinguishable from "nothing
was ever attempted," which could read as an AI score having quietly
been fabricated from nothing rather than an explicit unavailable state.
Now writes an `ai_analyses` row with `provider: 'unavailable'` and a
descriptive `error`, same as every other failure path — still writes
**no** `api_usage_logs` row, since no provider was ever chosen and that
table's `provider` column means "a specific provider was actually
called."

**AI cannot invent a numeric score, architecturally, not just by
convention**: every AI-driven module (`Ecommerce`/`LandingPage`/
`Conversion`/`Aio`) only ever lets AI contribute *issues* (via
`AiIssueParser`, each with a severity), which then feed
`ScoreCalculator::deductPoints()` — the same deterministic
severity-points formula every other module uses. There is no code path
where an AI response's text is parsed as or mapped directly to a
numeric score. `ConversionAuditModule`/`LandingPageAuditModule` have
deliberately narrow deterministic checks (form-friction count; CTA/
headline presence) — with AI unavailable, a site with none of those
specific problems legitimately scores 100 with zero issues, which is
accurate for what was actually checked, not fabricated; it is a
*narrow* audit, not a *fake* one. Building out a richer deterministic
CRO/AIO checklist (the product spec names ~15 more signals per
category) was explicitly scoped out of this pass by the user.

**Screenshot capture** (`crawler/crawl.mjs`, `App\Enums\ScreenshotViewport`,
`App\Models\Screenshot`): the crawler already took one desktop-viewport
screenshot per page (`--screenshot-path`, 1366×900) — extended to
capture **both** desktop and mobile (390×844) from the *same* page load
by resizing the Playwright viewport in place after the desktop
screenshot rather than re-navigating (`page.setViewportSize()` + a
second `page.screenshot()`), avoiding doubling crawl time/cost per
page. `--screenshot-dir` replaces `--screenshot-path`; the subprocess
writes `{dir}/desktop.png` and `{dir}/mobile.png`. Each viewport capture
is independently wrapped in try/catch (`captureScreenshot()`) — a
failure on one (or both) doesn't fail the page crawl, matching the same
graceful-degradation contract as the accessibility scan. `CrawlOrchestrator::storeScreenshot()`
only creates a `Screenshot` row when a path came back non-null, so a
failed capture is simply absent rather than a row pointing at a
nonexistent file. The old `audit_pages.screenshot_path` column was
dropped (verified nothing read it — no report/frontend code had been
built against it yet) in favor of the dedicated `screenshots` table,
which is the right shape for "up to two rows per page" the way a single
column column isn't.

**Real bug found only by inspecting a live audit's actual stored data**
(not by any test, since every existing test fakes the storage disk with
a consistent path separator throughout): `CrawlOrchestrator::toPublicPath()`
normalized backslashes to forward slashes *before* attempting to strip
the disk's base path — on Windows, the base path (still
backslash-separated) then no longer matched anywhere in the
already-normalized string, so the full absolute filesystem path
(`C:\Users\...\storage\app\public\screenshots\...`) was stored in
`screenshots.storage_path` instead of the intended relative one
(`screenshots/9/homepage/desktop.png`). Fixed by stripping the base path
first, then normalizing separators. Now covered by
`CrawlAndAnalyzePipelineTest::test_screenshot_storage_paths_are_stored_relative_to_the_public_disk_not_as_absolute_paths`,
which asserts the exact relative path rather than merely "some string
came back."

**Issue↔page relational model** (`issue_pages` pivot,
`AuditIssue::pages()`, `AuditPage::multiPageIssues()`,
`AuditPage::allIssues()`): supersedes an earlier decision from earlier
the same day to extend the JSON-blob (`meta.affected_pages`) approach
instead of building a relational table — reopened once this task's
explicit requirement ("issues must be queryable by page and pages must
be queryable by issue") made clear that a JSON blob can't do
bidirectional queries without a full table scan. `PerPageIssueAggregator`
and `AccessibilityAuditModule` now populate `AuditIssueData::$affectedPageIds`
for a multi-page issue, and `RunAuditModuleJob` syncs those into
`issue_pages` after creating the `AuditIssue` row. **The
`meta.affected_pages`/`affected_page_count` JSON fields were kept, not
removed** — they're a cheap, already-tested display convenience (no
join needed to render "5 pages affected" in a list), while the pivot is
now the canonical source for anything that needs to actually query by
page or by issue (the page explorer's per-page issue list, filtering,
search — deferred to a later pass, see below). A single-page issue
still just uses `audit_page_id` directly, as before — no pivot row for
that case.

**Scope deliberately not covered here** (per explicit user sequencing —
"start with data model + screenshots, report back before building the 7
report UIs"): the seven per-service report UIs, the GTmetrix/PageSpeed-
style performance report (Core Web Vitals, mobile-first opportunities/
diagnostics), per-service PDF generation, the page explorer, and the
frontend dashboard redesign are all still open — this pass only
establishes the data model and capture pipeline they'll be built on.
**Largely closed by §35 below**, except the deep performance report.

---

## 35. Per-service report architecture

Builds the normalized per-service reporting model on top of §34's data
layer: one audit produces N *independently viewable* service reports
rather than a single combined blob.

**The one data model, two renderers.** `ServiceReportBuilder::buildAll()`
is the single source both the web report and the per-service PDFs render
from, so the two cannot drift apart — the spec's core consistency
requirement. `ReportBuilder` (the older flat report) is unchanged and
still backs `GET /api/audits/{id}/report` and the combined PDF; the new
model is additive, not a replacement.

**Taxonomy** (`IssueTaxonomy`). Two levels resolved from two different
sources on purpose:
- *Service* comes from the issue's stored `AuditCategory` column, which
  the producing module always writes — never from parsing the code
  string, which would break for AI-generated codes (the model picks the
  suffix) and axe rule ids.
- *Category* (Metadata, Headings, ARIA, Core Web Vitals, …) comes from
  the code, since that's the only thing separating "missing title" from
  "missing H1" inside one service.
Unknown codes fall back to a per-service general bucket rather than
being dropped, so a new axe rule or AI-invented code still appears.

`landing_page` and `conversion` both map to the **CRO** service, and the
CRO score is the *mean* of whichever of the two ran (a plain lookup would
arbitrarily pick one and discard the other). **Known inconsistency**:
this differs from `FeatureAccessResolver`'s plan-gating map (§33), where
`landing_page` is gated by the `ecommerce` feature key — so disabling a
plan's ecommerce feature also silently removes CRO's value-proposition
findings. Reporting under CRO is correct (the codes are literally CTA and
headline findings); the gating map is the side that looks wrong, but it
is tested and documented behavior and was not changed without being
asked.

**Partial coverage — the anti-manufactured-score rule.** A service whose
assessment depends on AI, where the AI did not actually run, is marked
`confidence: 'partial'` and `status: 'partial'`, with a `coverage_note`
naming what was skipped. Its real deterministic score is *kept* (throwing
away a genuine measurement would lose information) but is never presented
as a pass. This was found against a real audit: CRO reported "100 —
good" with zero issues while its provider was unavailable, on the
strength of a single form-friction check. `confidence`/`coverage_note`
are shown to anonymous viewers too — withholding that a score is
incomplete would misrepresent the audit, which is different from
withholding paid detail.

**Visibility** (`ServiceReportVisibilityFilter`), mirroring §20's rules:
free/anonymous keeps every service's score, status, severity counts and
category *names and counts* — enough to prove the audit is real — but
withholds full issue lists, recommendations' fix text, evidence, the
affected-URL list, per-page findings and screenshots. Hidden counts
(`hidden_issue_count`, `hidden_page_count`, …) are always returned so the
UI can say "12 more issues" honestly.

**Endpoints** (§8): `GET /audits/{audit}/report/services` (all, plus the
shared overview), `GET /audits/{audit}/report/services/{service}` (one —
404, never an empty shell, when that service didn't run),
`GET /audits/{audit}/pages` (page explorer, full-access only), and
`GET /audits/{audit}/report/services/{service}/pdf` (per-service PDF,
same full-access + `pdf_export` plan gate as the combined PDF).

**Frontend**: `AuditReportPage` is the shell — service navigation listing
only services that actually ran, score cards, and the page explorer.
Each service renders through one shared `ServiceReportView` (score →
what it means → coverage warning → severity tiles → quick wins →
category breakdown → filterable issues → AI analysis → page-by-page), so
the reading experience is identical across services while the findings
stay service-specific. `IssueCard` leads with plain English and hides raw
evidence behind a "Technical details" toggle. `ScreenshotViewer` defaults
to **mobile** (the primary audit priority) and renders "no screenshot was
captured" rather than a broken image when capture failed.

**Screenshot serving.** Paths are stored relative to the `public` disk
and served at `/storage/{path}`; the frontend derives that origin from
`VITE_API_URL` so a deployment sets one URL, not two. A local checkout
needs `php artisan storage:link` once, or every screenshot 403s.

**Known limitations / not covered:**
- ~~The deep performance report~~ and ~~`product_auditor` has no audit
  module~~ — both **closed by §36 below**.
- Ecommerce detection depends on `PlatformDetector::isWooCommerce()`,
  which only inspects the **homepage** HTML. A WordPress site whose
  WooCommerce assets load only on `/shop/` is classified plain
  `wordpress`, losing the +3 platform signal. Observed on a real audit
  that has `/cart/`, `/checkout/`, `/my-account/` and `/shop/` pages yet
  scores 2 against a threshold of 3, so it reports Not Applicable. Whether
  that site *should* count as ecommerce is a genuine product judgment
  (a ministry selling one book), so the tuning from §34 was left alone
  rather than flip-flopped a second time.

---

## 36. Performance measurement and the product audit

Closes the two gaps §35 left open, by collecting the data first rather
than building UI against numbers that did not exist.

### Measurement collection

**The crawler now records every response.** A `page.on('response')`
listener attached *before* `goto()` captures each resource's type, status
and real transferred bytes via `request.sizes()` (CDP), not the
`content-length` header — which is absent on chunked responses and wrong
on compressed ones. Aggregated into per-type buckets (js/css/image/font/
document/media/other), plus third-party counts (host ≠ page host), DOM
node count, and navigation timings (TTFB from `responseStart -
requestStart`, FCP from the paint entry). Anything the browser does not
report stays `null`, never 0.

**PageSpeed now runs both strategies.** `PageSpeedService::analyzeBoth()`
attempts mobile and desktop as two independent calls, each honouring its
own `mobile_enabled`/`desktop_enabled` provider flag. Previously a single
ternary picked one strategy, so desktop numbers could never coexist with
mobile ones. Also newly extracted: TTFB, Speed Index, and Lighthouse
"opportunities" (audits carrying a measured `overallSavingsMs`/`Bytes`;
anything without one is a diagnostic, and passing audits are skipped —
we never invent a saving Lighthouse did not compute).

### Storage: `page_performance_metrics`

One row per `(audit_page_id, source, strategy)`, with that triple as a
**unique key**. This is what makes "never show a desktop number as
mobile" a schema guarantee rather than a convention — a metric physically
belongs to exactly one page, one source (`crawler`|`pagespeed`) and one
strategy (`mobile`|`desktop`). Every metric column is nullable: a value
the tool did not report stays null and renders as "unavailable", because
defaulting to 0 is indistinguishable from a real measurement of zero.
Lighthouse's category score is likewise `null` (not 0) when absent.

Crawler metrics are stored under the **desktop** strategy, because
desktop is the viewport the page was actually loaded at — the mobile
screenshot is a resize of that same load, not a second navigation, so
labelling those numbers "mobile" would attribute them to a device profile
never measured.

### Deterministic performance issues

`PerformanceAuditModule::checkResourceBreakdown()` raises findings from
the measured breakdown against configurable budgets
(`audit_scoring.performance.*`): oversized JS/CSS/images, too many fonts
or requests, excessive page weight, heavy third-party payload, slow TTFB,
excessive DOM size. Each issue's `meta` carries **both** the measured
value and the budget it was compared against, so the evidence shows why
it fired. Issues are driven by the **mobile** Lighthouse result when
available (mobile is the priority); desktop is stored and displayed but
does not drive the score twice.

### `PerformanceReportBuilder`

Sits alongside the standard service-report shape as `report.performance`.
Produces `mobile` and `desktop` blocks (each with score + Core Web Vitals
carrying value, threshold, status and **source**), site-wide `resources`
totals, and per-page rows. Pages with no measurement are excluded from
sums rather than counted as zero, and `measured_pages` is returned so the
figure can be read honestly.

**INP is deliberately absent.** It is a field metric requiring real user
data; Lighthouse lab runs do not produce it. TBT is shown instead as the
lab-measurable proxy, labelled as what it actually is rather than being
passed off as INP.

Free/anonymous viewers keep the headline scores and Core Web Vitals (the
proof something real was measured) but lose the per-page metric table and
resource breakdown, consistent with §35's split.

### Product audit (`product_auditor`)

Now a real `AuditCategory` and module. `ProductPageDetector` is stricter
than `EcommerceDetector`: that one asks "is this a store", this asks "is
*this page* a product page". Product schema alone is definitive; otherwise
a product-shaped URL **plus** a corroborating buy signal is required —
either alone is too weak, since `/shop/` is often a listing page and an
add-to-cart link can sit in a site-wide nav.

`ProductAuditModule` checks schema, price, availability, image count,
description depth, review data and a purchase action. All deterministic,
no AI. A site with no detected product page reports **Not Applicable**
rather than a score derived from pages that sell nothing. Missing schema
is reported once rather than cascading into separate
price/availability/review findings for the same root cause.

**Known limitations:**
- ~~Lighthouse opportunities are extracted but not surfaced as an
  "Opportunities vs Diagnostics" section.~~ **Closed by §45**, which also
  found that diagnostics were being discarded entirely rather than merely
  not displayed.
- PageSpeed still runs against a capped page subset (default: homepage),
  so most pages show crawler-measured metrics only and `—` for Lighthouse
  scores. That is a cost/rate-limit tradeoff, and the report shows the
  dash rather than borrowing another page's numbers.
- `add to basket`/`add to bag` were missing from the crawler's CTA
  keyword list, so UK/EU product pages yielded no purchase CTA at all and
  could not be detected. Added to both the crawler and the detectors, but
  **not yet verified against a live basket button** — the test store used
  for real-audit validation has no working cart element to exercise it.

---

## 37. Website records, deduplication, and audit history

A `websites` row used to be a byproduct of submitting an audit:
`AuditController::store()` called `Website::create()` unconditionally, so
auditing the same site twice produced two records and two cards in "My
Websites". A website is now a durable thing a user owns and edits, and
audits accumulate under it as history.

### Identity: `domain` vs `normalized_domain` vs `primary_url`

Three columns that look redundant and are not:

- **`domain`** — the host exactly as the site serves it, `www` and all.
  The crawler compares link and sitemap hosts against this for its
  same-site checks, so normalizing it would break crawling.
- **`normalized_domain`** — the dedup key: lowercased, punycode, DNS root
  dot stripped, **and a leading `www.` removed**. `www.example.com` and
  `example.com` are one website to a person deciding whether they have
  already audited it. Produced by
  `UrlNormalizer::normalizedDomain()`, which is deliberately more
  aggressive than `normalize()` for exactly this reason.
- **`primary_url`** — the URL the next crawl starts from (renamed from
  `url`, which said nothing about which of the three it was). A repeat
  audit updates it: re-auditing `example.com/pricing` after `example.com`
  is the user saying that is the page they care about.

### `WebsiteResolver` — one record per domain per user

`App\Services\Website\WebsiteResolver` is the only place a website row is
created or found for a submission. Two paths:

- **Registered user**: find by `(user_id, normalized_domain)`, else
  create. A `UniqueConstraintViolationException` on the insert is caught
  and re-read rather than surfaced — two audits of the same new domain
  submitted at once both see no existing row, and the unique index (not
  the read-then-write) is what actually prevents the duplicate.
- **Anonymous**: always a fresh row. There is no identity to attach a
  durable record to, so the only thing a shared row could key on is the
  domain — which would hand every anonymous visitor the audit history of
  every other anonymous visitor who audited that site. Duplicate
  anonymous rows are the correct trade: they are invisible (anonymous
  users have no "My Websites" listing) and they leak nothing.

The uniqueness is scoped to the user, not global. Two users auditing the
same domain each get their own record, with their own profile fields and
their own history.

A repeat audit updates `primary_url` and `domain` but never the profile
fields — a re-audit is not an edit of the record.

### The migration is destructive, and tested as such

`2026_08_31_000000_add_profile_and_normalized_domain_to_websites_table`
renames `url` → `primary_url`, adds the profile fields, backfills
`normalized_domain` and `name`, **merges pre-existing duplicates**, and
only then adds the unique index. The merge collapses each
`(user_id, normalized_domain)` group onto its earliest row — earliest so
the website's `created_at` stays honest as "when the user first audited
this domain" — repointing `audits` and `usage_logs` at the survivor.

`audit_schedules` needs its own handling: it carries a unique
`(user_id, website_id)`, so blindly repointing a duplicate's schedule
would collide with the survivor's own. One schedule is moved only if the
survivor has none; the rest are dropped, since one website has one
schedule by definition and they were all for the same site.

Anonymous rows are skipped by the merge for the same reason
`WebsiteResolver` does not dedupe them.

This is the only code in the project that deletes rows from a live
database, and it is the only code the rest of the suite structurally
cannot exercise — every other test starts from a fresh schema with no
duplicates, so the merge path runs zero times.
`WebsiteDeduplicationMigrationTest` therefore rolls the migration back,
recreates the pre-migration state, and runs it forward again.

### Reading the data back

- `Website::latestCompletedAudit()` uses `ofMany(['id' => 'max'], …)`
  with a constraint closure, **not** `where(...)->latestOfMany()`. The
  latter filters the outer join but leaves the subquery picking MAX(id)
  across all of the website's audits, so a website whose newest audit is
  still running resolves to nothing at all.
- `WebsiteCardBuilder` reads every number from the latest *completed*
  audit and reports any in-flight or failed run separately as
  `latest_audit`. A card must not blank out its scores the moment a
  re-audit starts, and a failed run's partial scores are not a result.
- `WebsiteDetailBuilder` composes the detail sections. `audit_history`
  includes failed and in-flight runs; the trend sections exclude them,
  because a failed run plotted on a score line reads as a real cliff. A
  category no audit ever scored is **omitted** from `score_trends`
  entirely rather than emitted as zeros — a flat line at zero reads as
  "we measured this and it is terrible", not "this check never ran".

**Enum-cast comparison trap** (bit this twice, once found only in the
browser): `audit_issues.severity` and `audit_scores.category` are
enum-cast, so `$score->category === 'overall'` is *always false* and
`$rows->pluck('total', 'severity')` throws "Illegal offset type" on the
enum key. Aggregates go through the query builder (`DB::table(...)`), and
category comparisons go through `WebsiteDetailBuilder::categoryOf()`.
Note that Collection `firstWhere('category', 'overall')` *does* match —
its loose `==` coerces the backed enum — which is exactly why the strict
`===` bug survived to the browser: most of the reads kept working.

### Authorization: the query parameter is not the check

The frontend addresses a website by domain
(`/dashboard/websites?website=example.com`), and the API accepts a domain
in the path (`GET /api/websites/example.com`). A domain is public
knowledge, so it identifies **which** of the caller's websites to show and
never grants access to it.

Every lookup in `WebsiteController` starts from
`$request->user()->websites()`. A website belonging to someone else is not
"forbidden", it is simply not in the set being queried — so it 404s
exactly as a domain the caller has never audited would. That is
deliberate: a 403 would confirm the record exists, turning the endpoint
into a way to discover which domains other users have audited.

`UpdateWebsiteRequest` accepts only the profile fields. `domain`,
`normalized_domain`, `primary_url` and `user_id` are absent from its
rules by design: a writable `normalized_domain` would let one record claim
another's identity and collide with a record the user already owns.

## 38. Audit lifecycle, concurrency limits and snapshots

### The lifecycle

`App\Enums\AuditStatus`: `queued` → `validating` → `crawling` →
`analyzing` → `generating_report` → `completed`, with `failed` and
`cancelled` reachable from any of them. (`pending` was renamed to
`queued` — it said nothing about what it was pending *on*, and the
lifecycle now needs a name for "accepted, waiting for a worker" that is
distinct from "a worker has it and is validating".)

The split that matters is **active vs terminal**:

- Active: `queued`, `validating`, `crawling`, `analyzing`,
  `generating_report`.
- Terminal: `completed`, `failed`, `cancelled`.

`generating_report` exists because `CalculateScoreJob` used to mark an
audit `completed` and *then* dispatch the report job — telling the user
the audit was done while the thing they were about to open did not exist
yet. `completed` now means "there is something to look at".

### `AuditLifecycle` — the only place status changes

Two rules have to hold on every path, and both were previously the
caller's job to remember:

1. A terminal transition stamps `terminated_at`, or the audit keeps its
   concurrent slot forever in every query that reads it. (`completed_at`
   keeps its narrower meaning of "finished successfully", so reports can
   go on trusting it.)
2. A terminal audit is never moved again. Every transition is a
   *conditional* update (`WHERE status IN (active…)`), so a worker
   finishing after a cancellation cannot resurrect the audit and
   overwrite a real user decision. `transition()` returns false in that
   case and the caller stops.

`progress_at` is bumped by every job that does real work. It exists
separately from `updated_at` because any unrelated write to the row
refreshes `updated_at`, which would make an abandoned audit look alive.

### Concurrent-audit limits (`ActiveAuditLimiter`)

"In flight" is **derived from `audits.status`**, never tracked in a
counter. That is the whole reason completed, failed and cancelled audits
release their slot without a release step that some new failure path
could forget to call — there is nothing to decrement.

- Registered users: `plans.concurrent_audit_limit` (DB-configurable per
  tier, editable through admin plan CRUD; the free plan seeds to 1). A
  user with no subscription gets 0, consistent with `UsageLimitService`.
- Guests: `audit_limits.anonymous_active_limit`. A guest's audits are
  associated with them through `usage_logs.ip_address`, since the audit
  row carries no IP.

Distinct from `daily_audit_limit`, which caps volume over a day. This
caps what is in flight at any instant, and is what stops one account
queueing a thousand crawls.

**The race.** Count-then-insert is a textbook race: two requests both
count N-1 active audits, both decide there is room. `withSlot()` runs the
check and the insert together under two guards:

- A named `Cache::lock` per owner, acquired **non-blocking**. This is the
  guard that matters in production (Redis, atomic cluster-wide) where two
  PHP-FPM workers handle two requests at once. Non-blocking because
  whoever holds the lock is about to consume the very slot a waiter would
  be waiting for, so the honest answer is "you already have one starting",
  returned immediately. It also sidesteps a real trap: **`Lock::block()`'s
  timeout is not honoured by the array driver** — it waits out the
  holder's full TTL instead of the timeout passed to it (measured: a
  `block(1)` against a 30s lock returned after 30 seconds). A blocking
  acquire would therefore hang requests for the lock TTL in any
  environment not running Redis.
- A `DB::transaction` with `lockForUpdate()` on the owning user row, so
  the limit still holds if the cache is flushed or misconfigured. This is
  a no-op on SQLite, which is exactly why the named lock is not optional.

A guest has no row to lock, so the named lock is the only guard there — a
deliberate, acceptable gap, since the anonymous limit is already
best-effort (keyed on an IP address that one person can trivially change).

### Cancellation

`POST /api/audits/{audit}/cancel`, owner-only (404 for anyone else, so
the endpoint cannot confirm which IDs exist). The slot frees immediately.

Cancellation is **cooperative**: a job already executing is not killed. It
finds the terminal status at its next checkpoint (`shouldStop()`, called
at the top of every queued job) and stops. So cancellation takes effect at
the next stage boundary, and an in-flight page fetch may take a few more
seconds to wind down.

### Reliability

Every failure mode now has an owner:

| Failure | Handled by |
|---|---|
| Crawler throws | `CrawlWebsiteJob`'s catch → `markFailed(stage: crawling)` |
| One page fails | `CrawlOrchestrator` records the per-URL failure; the crawl continues. `CrawlPageJob::failed()` re-checks crawl completion, so a failure on the *last* outstanding page doesn't stall the pipeline |
| One module throws | `RunAuditModuleJob` records the category as unassessable (`score: null, is_applicable: false, breakdown.error`) and continues. **A partial failure is not an audit failure** — six categories of real findings still ship |
| A job exhausts `tries`, or the worker dies mid-job | Each job's `failed()` handler marks the audit failed (or, for a module, records the unassessable score). Without these the audit sits active forever, holding a slot nothing releases |
| Worker OOM-killed / deploy mid-job (`failed()` never runs) | `audits:recover-stuck`, scheduled every five minutes |
| Crawl runs too long | `crawler.crawl_timeout` in `CrawlPageJob` (§26) — abandons but still analyzes what was gathered |

The unassessable-score shape is deliberate: it matches a genuinely
Not Applicable category (§34) except for `breakdown.error`, so nothing
downstream mistakes a crashed module for a clean result and no score is
invented to fill the gap. It also has to exist at all — without a score
row, `dispatchScoreCalculationIfReady()` waits forever for a category
that will never report.

`audits:recover-stuck` runs every five minutes rather than hourly because
a stuck audit holds a slot, so until it runs the user simply cannot start
another audit. It is a single indexed query when nothing is stuck. It
takes `--minutes` and `--dry-run`, and is deliberately conservative:
`stuck_after_minutes` (default 30) must stay well above
`crawler.crawl_timeout` or recovery will kill audits that are merely slow.

### The immutable snapshot (`audit_snapshots`)

Distinct from `reports`, which is a *presentation* of current data and is
regenerated whenever the report format changes. A snapshot is **evidence**:
written once when an audit completes, never changed.

It records the website, timestamp, crawler/scoring/prompt versions, AI
provider and model, pages analyzed, service results, scores, issues,
recommendations, screenshots and performance data — everything
denormalized into `content` rather than referenced by id. That is the
point: it has to keep meaning after the website is renamed, the scoring
weights change, the AI model is swapped, or a re-audit re-derives the
issue rows. A snapshot full of foreign keys would silently start telling
a different story.

- **Versions** (`config/audit_versions.php`) are what make an old score
  comparable to a new one. Without them, a change in scoring weights is
  indistinguishable from a change in the audited site. The scoring
  *weights themselves* are stored too, so reading a two-year-old snapshot
  does not require digging a matching config out of git history.
- **AI provenance** is read from the audit's `ai_analyses` rows, not from
  current config — config says what would be used *now*, which is not
  what produced this result. A failed call is not reported as the
  answering provider.
- **Service results** include services that did *not* run. A snapshot
  that silently omitted a disabled category would read as "this site had
  no accessibility problems".
- **Immutability is enforced in the model**, not left as convention:
  `updating` and `deleting` throw `AuditSnapshotIsImmutable`. Deleting
  matters as much as editing — drop the snapshot, re-run the audit, write
  a new one is a subtler way to rewrite history. The FK cascade does not
  fire model events, so snapshots still go when their audit does.
  `AuditSnapshotBuilder::build()` returns the existing row untouched
  rather than rewriting, because `GenerateReportJob` retries.

A snapshot failure is logged loudly but does **not** fail the audit: the
report is already saved and is what the user reads.

## 40. Plan entitlements

Everything a tier grants, in one place: `App\Services\Plan\Entitlements`,
an immutable value object resolved by `EntitlementResolver` and read by
every gate. Nothing should reach for `$user->plan()->max_pages` again.

The indirection earns its keep because three cases have to be right
everywhere and previously were not: a guest has no plan row, an expired
subscriber's plan is not the plan they are entitled to, and "unlimited"
has to be expressible. The website cap and monthly cap simply did not
exist, and the guest branch was re-derived from config in three places.

**Null means unlimited** in every numeric field. Zero is a real limit of
none; the two are never interchangeable, from the admin request through
to the column.

### Where the numbers live

| Control | Source |
|---|---|
| Max websites, pages per audit, daily/monthly audits, concurrent audits, audit frequency, report visibility %, issues per service, AI analyses per audit, AIO prompts | `plans` columns — admin-editable via `PUT /api/admin/plans/{plan}` |
| PDF export, scheduled audits, audit history, report sharing, white label, custom AIO prompts, competitor discovery | `plans.features` (JSON), keyed by `App\Enums\PlanFeature` |
| The whole guest tier | `config/entitlements.php` |

`PlanFeature` exists so the JSON column is not free-form: `PlanRequest`
rejects an unknown key, which used to save silently and disable nothing.

The guest tier is deliberately *not* a `plans` row — a plans row is
admin-editable and shows up in pricing listings, and neither is right for
anonymous visitors. `config/audit_limits.php` used to hold some of these;
they moved here, and its `anonymous_active_limit` was already dead (one
key fed the resolver, the other fed nothing).

### Which plan applies

1. **Anonymous** → the guest tier.
2. **Active subscription** → that plan. `Subscription::isActive()` checks
   status *and* `ends_at`: a row can be marked Active but past its end
   date (nothing sweeps them), or Cancelled but still inside a paid-up
   period.
3. **Lapsed subscription** — cancelled, expired, past `ends_at`, or
   pointing at a plan an admin has deactivated → the **default plan**,
   flagged `is_downgraded`. A subscription ending is a normal lifecycle
   event; locking the account out of the product would be a worse answer
   than dropping it to free.
4. **No subscription row at all** → null, and callers deny. An anomaly,
   not a lifecycle state: registration always creates one, so an account
   without it came from a script or a partial failure, and the system
   refuses to guess what they paid for. (Pre-existing behaviour, kept.)

### Not cached, deliberately

An earlier version memoized by user id, and it was wrong — provably so. A
resolver instance outlived the state it cached: the same object served two
requests and answered the second with the plan the user had during the
first, so **a plan upgrade did not take effect**. The same hazard exists
in any long-lived process (a queue worker, Octane) and for a resolver
handed two different users.

For the same reason `resolve()` queries `subscriptions` and `plans` by key
rather than reading `$user->subscription->plan`: Eloquent caches a loaded
relation on the model instance, so a `User` handed in after its plan
changed answers with the old plan's limits, and the caller has no way to
know its object is stale.

Entitlements gate limits, so a stale answer is a wrong answer to a
security question. Two small indexed queries per resolution is the right
trade.

### Report visibility

`report_visibility_percent` scales how many findings survive the
restricted view, and `issues_per_service` caps them per service. The
*shape* of the split is still field-based (§20) — a percentage short of
100 never reveals AI analysis or page-level findings, however high it is.
Counts round **up**, so a plan showing "10%" of a single finding shows
that finding: a restricted report exists to prove the audit found
something real.

**Ownership decides whose entitlements apply; the entitlements decide how
much.** `AuditController::viewerEntitlements()` resolves that: an owner is
filtered by their own plan, everyone else by the guest tier. Passing the
*viewer's* entitlements unconditionally was a real hole caught in
testing — a non-owner on a 100%-visibility plan read someone else's full
report. An owner with no resolvable plan falls back to the guest tier
rather than being denied, so an owner never sees *less* of their own audit
than a passing stranger would.

The seeded free plan is at 100%: "sign up to unlock the full report" is
existing behaviour, and this phase adds the control without re-pricing the
product. An admin can lower it per tier.

### Enforcement points

Every limit is checked server-side where it applies, never trusted because
the client was told about it:

| Limit | Enforced in |
|---|---|
| Max websites | `WebsiteResolver::resolve()` → `PlanLimitExceeded` → 403. Applies only to *adding* a site: re-auditing one already owned works at (or over) the cap, so lowering a plan's limit does not lock subscribers out of sites they already have |
| Daily / monthly audits | `UsageLimitService::auditRefusalReason()` → 429, carrying `errors.limit` so the UI can say *which* allowance ran out |
| Concurrent audits | `ActiveAuditLimiter` (§39), now reading its number from here |
| Pages per audit | `AuditController::store()` — server-derived, never from the payload |
| Audit frequency | `AuditScheduleController::store()` → 403, separate from the "no scheduled audits at all" refusal because the two need different actions |
| Report visibility, issues per service | `ReportVisibilityFilter`, `ServiceReportVisibilityFilter` |
| PDF export | `AuditController::reportPdf()` / `serviceReportPdf()` |
| AI analyses, AIO prompts | `AiAnalysisService::exceedsPlanAiBudget()`, checked *after* the cache lookup so re-reading a result already paid for is free. A refusal is recorded as an `ai_analyses` row with an error, exactly as an unavailable provider is, so the report says the category was not analyzed rather than silently omitting AI findings |

The monthly limit uses a **calendar** month, not a rolling 30 days: a plan
sold as "20 audits a month" is understood to refill on the 1st, and a
rolling window is impossible to reason about from a dashboard.

### Entitlements for capabilities that do not exist

White label and competitor analysis are explicit MVP exclusions
(CLAUDE.md). Their flags are configurable and reported by the API so tiers
can be priced ahead of the build, but `Entitlements::allows()` returns
false for them **however the plan is configured** — answering true would
make callers render a white-label report that no renderer produces.
`isConfiguredFor()` still reports what the admin set, and the API exposes
`available: false` alongside `enabled`, so the UI can distinguish "not in
your plan" from "nobody has this yet". Phase 4 built the entitlement
system; it did not build the excluded features.

### API

`GET /api/me/entitlements` (optional auth) returns the whole set — limits,
features, and whether the caller is a guest or downgraded. It is
*descriptive*: hiding a control based on it is a courtesy, never a
security boundary.

## 41. Database health: engine, indexes, snapshot backfill

Findings from auditing the live MySQL database after Phases 1-4 landed,
and what was done about each. Referential integrity, charset and collation
were already clean — every issue below was either latent or a gap the new
phases opened.

### The storage engine was not pinned

`config/database.php` had `'engine' => null` for both MySQL connections,
which means **the server's default wins** — and this box's MySQL defaults
to `MyISAM`. Every table happened to be InnoDB only because each migration
sets `$table->engine = 'InnoDB'` by hand, a convention nothing enforced.
One table had already slipped through (Laravel's own `migrations`).

The consequence of forgetting that line in a future migration is not a
visible error: MyISAM silently accepts a foreign key and ignores it, and
silently ignores transactions. Every `cascadeOnDelete` and every
`DB::transaction()` guard in the app would quietly do nothing on that
table.

Now pinned: `'engine' => env('DB_ENGINE', 'InnoDB')`. Individual
migrations no longer depend on remembering, and `migrations` was converted
to InnoDB so the schema is uniform.

### Missing composite indexes on the new hot paths

Phases 2-4 added query shapes that only had single-column indexes to work
with, so MySQL picked one and filtered the rest by hand. Invisible on a
dev database with twenty audits; not invisible later.

| Query | Added index |
|---|---|
| `ActiveAuditLimiter::activeCount()` — `user_id = ? AND status IN (…)`, run on **every** audit creation inside the lock that serializes creation | `audits (user_id, status)` |
| `Website::latestCompletedAudit()` — `website_id = ? AND status = 'completed'`, run per website on every "My Websites" listing | `audits (website_id, status)` |
| Severity rollups in `WebsiteCardBuilder`/`WebsiteDetailBuilder` — `audit_id IN (…) GROUP BY audit_id, severity` | `audit_issues (audit_id, severity)` |

`usage_logs` already had the composite indexes the daily/monthly counts
need. Verified with `EXPLAIN`: all four hot queries now resolve through an
index rather than a scan.

### Audits that finished before snapshots existed

`audit_snapshots` arrived in §38, so every audit completed before it had
no provenance record — 19 on this database.

`php artisan audits:backfill-snapshots` (with `--dry-run`) writes them. A
**command, not a migration**, deliberately: a snapshot is evidence, and
manufacturing evidence is an operational decision someone should make on
purpose rather than something that happens while deploying a schema
change. It is also re-runnable and never rewrites an existing snapshot.

**What a backfilled snapshot can honestly claim.** The scores, issues,
pages, screenshots and performance data are real — stored when the audit
ran, merely collected now. The *version* stamps are read from current
config, which is only truthful while no version has ever been bumped;
every historical audit here ran under the same `1.0.0` the config still
names. Each backfilled row carries `content.backfilled = true` and
`content.backfilled_at`, so it cannot later be mistaken for a
contemporaneous record once a version does move.

One snapshot on this database (audit #23, written by the live pipeline
before the marker existed) has no `backfilled` key at all. Absent reads as
false, which is correct; it is immutable, so it stays that way.

### The CMS had no seeded content

`cms_pages` was empty on every fresh install, so `GET /api/cms/homepage`
404'd on every homepage load and the admin CMS opened onto nothing to
edit. It went unnoticed because `fetchCmsPage()` catches the failure and
`sectionContent()` falls back to hardcoded copy — the page renders
correctly either way, and the only symptom was a 404 in the console.

`CmsSeeder` now creates the homepage and its `hero` section using those
same fallback strings verbatim. Seeding must not change what a visitor
sees, only make it editable; the frontend fallbacks stay as the answer to
"what if the row is deleted", which is a different question from "what
does this site say".

**Note on running the stack:** `php artisan serve` is the API only — its
root URL serves Laravel's default welcome page, not Audivio. The UI is the
Vite dev server (`npm --prefix frontend run dev`, port 5173), which is
what to open. Vite binds `localhost`, so `http://127.0.0.1:5173` does not
answer even though `http://localhost:5173` does.

## 42. Business onboarding, AIO context and competitor intelligence

**Scope note:** CLAUDE.md listed "competitor analysis" among the MVP
exclusions. Phase 5 explicitly commissioned it, so it is built and the
exclusion is lifted. White-label reports remain excluded.

### Onboarding

The eight onboarding fields live on `websites`. Seven arrived with §37;
`aio_goal` is the last, and the one that matters most — "is this page
clear to a machine reader" is unanswerable without knowing what it is
meant to be clear *about*.

`AioContextBuilder` assembles the fields the owner actually filled in.
Empty fields are **omitted**, never sent as null or `''`: a model handed
`category: ""` will reason about it.

### The AIO prompt set

Ten default prompts in `config/aio_prompts.php`, not a table — they are
part of what the product is, they change with the code that parses their
output, and `audit_versions.prompt` already stamps which set produced a
result.

Ten narrow prompts beat one broad prompt: the model answers a specific
question well and a sprawling one vaguely, and a failure isolates to one
check instead of losing the category. `AioAuditModule` now runs the set
and stops early if a provider refuses, rather than firing the rest at a
provider that just said no.

Three prompts are marked `needs_context`. Without an `aio_goal` they are
**skipped, not asked blindly** — asking "does the content serve the stated
goal" with no stated goal invites the model to invent one and then grade
the site against its own invention. Which were skipped and why is recorded
in the score `breakdown.context`, so a report says "3 checks need your
business profile" rather than quietly running seven and presenting it as
complete.

Custom prompts (`website_aio_prompts`) are appended after the defaults and
truncated **last**: a tight execution budget drops the owner's extra
questions before the baseline, because the baseline is what makes one
audit comparable to the next.

Two limits, deliberately separate:

| Column | Caps | Why separate |
|---|---|---|
| `aio_prompt_limit` | prompts one audit *executes* | cost control (§40) |
| `custom_aio_prompt_limit` | prompts a website may *define* | a product feature |

Overloading one column with both would make "give the free tier another
custom prompt" also raise everyone's AI bill. The define-limit is enforced
when saving, so a user is told at the point they add one rather than
having it silently ignored at audit time.

### Competitor intelligence

`website_competitors` holds the analyzable set. It is distinct from the
`websites.competitors` JSON column, which stays as the owner's raw
declared list from the profile form: one is what they told us, the other
is what we analyzed. `AnalyzeCompetitorsJob` syncs the former into the
latter, skipping entries that cannot be reduced to a domain (they stay in
the JSON — nothing the owner typed is lost, it simply is not something we
can go and look at).

**Discovery** runs only when all three hold: the plan includes it, the
owner named *none* themselves, and there is business context to reason
from. The middle condition is the point — discovery fills a gap, it does
not second-guess the owner. The third matters just as much: a model asked
to guess competitors for an unknown business in an unknown market returns
plausible names with no basis, which is worse than no answer.

Discovery only ever writes `source = discovered`. A row the owner named is
never overwritten or re-labelled; doing so would misattribute their input
to a model. Every suggested domain goes through `UrlNormalizer`, so a
hallucinated "domain" is rejected at the door rather than becoming a row
that later fails to crawl.

**Comparison** (`CompetitorAnalysisService`) crawls at most three pages per
competitor through the same `UrlSecurityValidator` and
`PlaywrightCrawlerClient` a normal audit uses — a model-suggested domain
is user-influenced input and gets exactly the SSRF protection a
user-submitted one does (§26). The small budget is not a performance
compromise: this crawls a third party who did not ask to be crawled, and a
handful of pages is enough to judge structural clarity.

**Null is not zero.** Every analysis column stays null until a run fills
it, and a failed run clears them rather than leaving last week's numbers
looking like today's. Reporting an unreachable competitor as scoring zero
would be a false claim about a real company, made in the one place a
reader would never think to question it. The UI renders a dash.

The job runs **after** the audit completes and outside its lifecycle:
competitor work is the slowest and most failure-prone part of the product,
and an unreachable competitor must never turn a finished audit of the
customer's own site into a failed one.

### API

- `GET|POST /api/websites/{website}/aio-prompts`,
  `DELETE .../{prompt}` — scoped to the caller's own websites, same rule
  as every other website route (§37).
- The website detail payload gains `onboarding` (what is filled in, what
  is missing, whether the context prompts are unlocked) and `competitors`.

## 43. Deep accessibility and the ADA assessment

The accessibility module was previously a thin wrapper over axe-core: it
turned each violated rule into an issue with a message and a link. That is
enough to score a site and nothing like enough to act on one, and — more
seriously — it produced a number labelled "accessibility" with nothing
attached saying what that number is not.

### What the crawler now captures

`crawl.mjs` records, per axe violation: the rule's `tags`, the total node
count, and for the first 8 nodes the `target` selector, up to 400
characters of the element's own `html`, and axe's `failureSummary` — its
explanation of why *that element* failed. It also records the rule ids
axe **passed** and the ones it returned as **incomplete**.

Passes and incompletes are stored in two new `audit_pages` columns
(`accessibility_passes`, `accessibility_incomplete`, both nullable — null
means "not recorded", which is every audit from before this change).
Without them, "how many checks passed" is unanswerable, and a page axe
never managed to scan is indistinguishable from a page with no problems.
Incomplete results are kept apart from passes on purpose: "a human needs
to look at this" is not "fine".

### The sixteen check areas

`App\Enums\AccessibilityCheck` groups roughly 90 axe rules into the
sixteen areas the product reports on — alt text, labels, buttons, links,
ARIA, heading hierarchy, contrast, keyboard, forms, landmarks, semantic
HTML, tables, iframes, language, skip links, focus — plus an `Other` case
so an axe upgrade that adds a rule surfaces the finding instead of
silently dropping it. Each area carries a human-authored
`whyItMatters()`, written for a site owner rather than as a restatement of
the rule id.

### WCAG citations

`WcagReference::fromAxeTags()` parses axe's tags: `wcag143` becomes 1.4.3,
`wcag21aa` contributes conformance level AA, and the strictest level seen
wins (satisfying a rule tagged both A and AA requires AA). A rule tagged
only `best-practice` maps to **no** criterion, and the report says so
rather than inventing a citation — a fabricated standards reference is a
worse failure here than a missing one.

### What a finding now carries

Per issue: the check area and its label, why it matters, the description,
the WCAG reference and display label, whether it is a best practice, the
help URL, the affected page URLs and their count, the affected element
count, and up to ten failing elements each with page URL, selector,
markup and axe's own evidence. Aggregation is still by rule across pages
(§34's rule, unchanged): one site-wide contrast problem is one issue, not
one per page.

### The report

`AccessibilityReportBuilder` produces the accessibility service's detail
block, the counterpart to `PerformanceReportBuilder`: the eight summary
fields (score, critical/high/medium/low counts, passed checks, affected
pages, affected elements), scan coverage, the per-area rollup including
areas with no findings, and the WCAG criteria with failures.

Two counting decisions worth keeping: `passed_checks` counts axe rules
that actually ran and passed, never check areas with no finding (an area
never tested is not an area that passed); and `affected_pages` is
distinct pages across issues, floored at the largest single issue's own
count, because the per-issue URL list is capped and would otherwise
undercount a large crawl.

`coverage.is_partial` is true whenever fewer pages were scanned than
crawled. A partial scan presented as a complete one is the quietest way
this product could mislead someone.

### The disclaimer

`config/accessibility.php` holds the wording, and it travels with every
accessibility result the product emits: the module's score breakdown (so
it is captured in the immutable snapshot), the per-service report, the
combined report's `disclaimers` key, both PDF templates, and the web UI
directly beneath the score.

**It is not paid detail.** `ServiceReportVisibilityFilter` and
`ReportVisibilityFilter` both keep it at every tier. The free view — a
bare score and little else — is exactly where a number is most likely to
be screenshotted as proof of compliance.

The wording deliberately avoids "compliant", "compliance" as a claim,
"certified", and any reference to legal standing. It states what was
tested, that automated testing covers only a machine-checkable subset,
that passing every automated check is not evidence of conformance with
WCAG or the ADA, and that a conclusive answer requires manual testing and
testing with assistive technology by people who use it. A test asserts
the forbidden phrasings stay out of it. Areas with no findings are
labelled "no automated issues found", never "accessible".

## 44. Deep SEO audit

The SEO module checked seven things: title, meta description, canonical,
H1, image alt, redirect-chain length, and broken links to pages the crawl
had already fetched. Phase 7 takes it to twenty-four checks, four
dedicated report views, and a backlink abstraction that answers "no data"
rather than guessing.

### What the crawler now captures

`lib/extract.mjs` additionally records `meta[name=robots]` and
`meta[name=googlebot]` (kept apart — a page can be indexable generally and
excluded for Google specifically), the full `og:*` and `twitter:*` maps as
served, and each image's *declared* `width`/`height` attributes alongside
the natural size already captured.

`crawl.mjs` now records each redirect hop as `{url, status}` instead of a
bare URL. A 301 and a 302 look identical to a browser and mean opposite
things to a search engine, so a redirect report without the status is not
a redirect report. Hops recorded before this are still read, and their
type reads `unknown` rather than being guessed.

`CrawlOrchestrator` persists the three response headers the audit reads —
`x-robots-tag`, `content-type`, `link` — and nothing else. Storing the
whole header set would persist cookies and auth-adjacent values for no
purpose.

### New schema

`audit_pages` gains `meta_robots`, `meta_googlebot`, `open_graph`,
`twitter_card`, `response_headers`, all nullable, all meaning "not
recorded" when null.

`audit_site_signals` is a new one-row-per-audit table holding what the
crawl learned about robots.txt and the sitemap. The crawler had always
fetched both to decide what to crawl and then thrown the result away.
Re-fetching at analysis time would answer a different question — the site
as it is now, not as it was when audited.

Every `*_found` column is a **nullable boolean**, and this is the load-
bearing decision in the whole phase: `false` means the fetch succeeded and
there was nothing there; `null` means the fetch itself failed. Telling an
owner they have no robots.txt when the request merely timed out sends them
to fix something that is not broken.

### The checks

`PageSeoChecks` and `SiteSeoChecks` split by the only distinction that
matters to them — questions about one page versus questions that only
exist across pages. Per-page findings go through `PerPageIssueAggregator`
(§34); site-wide ones carry their own affected pages and bypass it.

Four collaborators do the analysis:

- `IndexabilityResolver` — noindex in markup, noindex in `X-Robots-Tag`,
  an error status, or a canonical pointing elsewhere. A robots.txt
  `Disallow` is deliberately **not** folded in: it blocks crawling, not
  indexing, and a disallowed URL can still be indexed from external links.
  Self-referencing canonicals are correct and common, so only a canonical
  naming a *different* page counts.
- `SchemaAnalyzer` — flattens JSON-LD through `@graph`, nested arrays and
  nested entities, resolves `@type` (including arrays, subtypes and full
  schema.org URLs), and validates against `config/seo_schema.php`. Only
  the six documented types are validated; an unrecognized type is neither
  checked nor complained about, because inventing requirements for a type
  we have no reference for produces confident nonsense.
- `LinkGraph` — built once per audit and shared. Broken links, orphans,
  nofollow and links-into-redirects are four questions about one
  structure. `www.example.com` and `example.com` are the same site, and
  the scheme is dropped entirely, so a site linking to itself over both
  http and https is not reported as having an orphan and a duplicate.
- `RedirectAnalyzer` — chains, loops, and permanent-vs-temporary.

**A real bug this phase found:** the loop detector originally shared the
link graph's normalization, which drops the scheme. That makes
`http://example.com/x → https://example.com/x` — the single most common
legitimate redirect on the web — look like a URL redirecting to itself,
and would have reported a *critical* redirect loop on nearly every HTTPS
site. `RedirectAnalyzer` keeps the scheme; `LinkGraph` drops it. The two
normalizations answer different questions and must not be shared.

### Coverage

`SiteSeoCoverage` reports, for each of the twenty-four checks, whether it
ran. A check that never ran — because the crawl predates the field it
reads — produces no findings and therefore looks exactly like a check that
passed. The breakdown says which, and why not.

### The four report views

`SeoReportBuilder` is the counterpart to `PerformanceReportBuilder` and
`AccessibilityReportBuilder`:

- **Redirect report** — every redirected URL with its hops, types, and
  chain/loop status, plus a scope note: it covers redirects the crawl
  encountered, and does not claim there are no others.
- **Open Graph preview** — what a share of each page is expected to
  render, and *where each field came from*. `is_fallback` marks a value
  borrowed from a tag never written for sharing (`<title>`, meta
  description, the page URL). `og:title` standing in for an absent
  `twitter:title` is **not** a fallback: it is the tag the author wrote
  for that purpose, and flagging it would send owners to duplicate every
  Open Graph tag under a `twitter:` prefix for nothing. The preview is a
  prediction from the tags, not a capture, and says so.
- **Schema report** — which types are present and where, what properties
  are missing, and the standing caveat that only JSON-LD is read.
  Microdata and RDFa are not extracted, so a site using those would
  otherwise read this as "you have no structured data".
- **Page-by-page report** — one row per crawled page with its core
  signals, so the site can be scanned rather than read issue by issue.

### Backlinks

Backlinks live on other people's websites and cannot be measured by
crawling the audited site. `App\Contracts\BacklinkProvider` is the
abstraction; `NullBacklinkProvider` is the default binding, and
`config/backlinks.php` ships with `driver => null`.

`BacklinkService` always returns one of three states —
`not_configured`, `unavailable`, `available` — and **never synthesizes a
figure**. There is no estimate, no model, and no zero standing in for
unknown: "this domain has no backlinks" and "we could not find out" are
opposite claims, and a plausible invented number is indistinguishable
from a measured one to the reader. A provider outage reports
`unavailable`; an unknown driver name in the env falls back to the null
provider rather than throwing, because a typo must not take audits down.
Real data is attributed to the provider by name and stamped with when it
was retrieved, since backlink indexes disagree with each other
substantially.

## 45. Professional performance and PageSpeed audit

§36 collected the measurements. This turns them into a report: the
opportunity/diagnostic split it explicitly left open, the two categories
that had no checks behind them, and the one metric the product could not
honestly produce.

### Opportunities vs diagnostics

`PageSpeedService::splitFindings()` divides Lighthouse's failing audits
using Lighthouse's own distinction. An audit carrying a measured
`overallSavingsMs`/`Bytes` is an **opportunity** — fixing it saves a
quantified amount. Every other failing audit is a **diagnostic**: a real
problem with no number attached.

Keeping them apart is not presentation. A "potential saving" shown against
a finding Lighthouse never quantified would be the one figure a reader
acts on, invented by us. Diagnostics therefore have no savings keys at
all — not zeroed ones — so there is nowhere for a fabricated number to
appear, in the API, the UI or the PDF.

Passing audits (score 1) and informative ones (no score) are neither.
Opportunities sort by measured time saving; diagnostics, having nothing to
rank by, sort worst-score-first.

### Affected resources

Each finding carries the resources Lighthouse blamed, from its own details
table: URL, total bytes, wasted bytes, wasted ms. Rows without a URL are
summary rows and are dropped. Without these, a finding says "your images
are too big" and leaves the owner to work out which ones.

### INP, and why it is only ever field data

INP is measured from real interactions. No lab run produces one, so
§36 omitted it — but silently omitting a metric the report promises reads
as "no INP problem here".

`extractFieldMetrics()` now reads the CrUX block PageSpeed returns
alongside the Lighthouse run: real Chrome users, trailing 28 days, INP
included. When Google has too little traffic for a URL there is no CrUX
record, and the report says exactly that, with the reason. It is never
backfilled from the lab run, which measures a different thing — the two
will not match and are not interchangeable.

This is the only metric in the product sourced from outside the audit
itself, so it is labelled as such wherever it appears.

### Caching and compression

Two of the twelve reported categories previously had no checks behind
them. Both are decided entirely by response headers, so the crawler now
records `cache-control` and `content-encoding` per response and
summarizes them.

- **Caching** counts static assets (js/css/image/font/media, 2xx only)
  with no usable cache lifetime — no header, `no-store`, `no-cache`, or
  `max-age=0`. HTML documents are excluded: they are usually meant not to
  be cached, and flagging them would be advice to break the site.
- **Compression** counts text resources (js/css/document) served with no
  `content-encoding`, above 1KB. Images, fonts and media are excluded —
  already-compressed formats, where gzip costs CPU to save nothing.

Both findings name the actual files, largest first, with the header each
one sent. Both are silent when the crawl recorded no summary at all,
because an audit from before this was captured is not a site with no
caching.

### The twelve categories

`config/performance_categories.php` maps every Lighthouse audit id onto
one of the twelve, and `PerformanceReportBuilder::categoryRollup()` groups
findings under them. An unrecognised audit id falls to `other` and is
still reported — a Lighthouse release adding an audit surfaces it,
ungrouped, which shows up as a category to extend rather than a finding
that vanished.

The rollup is built from the **mobile** run wherever one exists, falling
back to desktop only when there is no mobile result at all, and it states
which strategy it used. Grouping that silently mixed the two would be the
same error the schema was shaped to prevent.

### Mobile and desktop stay separate

The `(audit_page_id, source, strategy)` unique key from §36 already made
that a schema guarantee for metrics. The new columns —`opportunities`,
`diagnostics`, `field_metrics` — hang off the same table for the same
reason: mobile and desktop produce different findings with different
savings, and storing them per page would collapse the distinction.

Per-page rows nest findings as `findings.mobile` / `findings.desktop`,
each **null** when that strategy was never measured. Null, not an empty
list: an empty list says "measured, nothing found".

### What the free view keeps

Scores, Core Web Vitals, the finding *counts* and the savings *total*
stay at every tier; the itemised lists are full-access detail. The total
stays deliberately — withholding it would make the free report understate
the problem, which is a different thing from withholding paid detail. The
field block stays too, because a reader who cannot see why INP is absent
reads the gap as an absence of the problem.

## 46. Website classification, CRO and ecommerce signals

### Classifying the site

`SiteClassifier` decides which of eight types a site is — ecommerce,
service business, SaaS, blog, portfolio, landing page, local business, or
`Other` — from measured signals only. Deliberately deterministic and
AI-free: this decides which services get reported at all, so it has to be
repeatable and explainable, and the same crawl must always reach the same
answer.

Each type accumulates points from independent signals, weighted 3
(near-conclusive alone), 2 (strong corroboration) or 1 (suggestive only,
never enough to win by itself). The winner must hold a **strict majority**
of the total evidence. Exactly 50% is a two-way tie — equal evidence for a
shop and for a blog — and that is precisely the case where picking one is
guessing, so it reports `Other`.

`Other` is a real answer here, not a failure. A confident wrong label is
worse than an honest "we could not tell", because the label silences whole
services.

Two rules worth keeping:

- **A landing page needs more than a one-page crawl.** A single-page
  result on a site with an unreachable sitemap is a crawl limitation, not
  a one-page site, so the type also requires the *shape* of a landing
  page: calls to action and almost no internal navigation.
- **A pricing page is not a SaaS signal on its own.** Shops have them
  too. The SaaS signal is a pricing page *plus* recurring-billing wording.

Stored on the **audit**, not only the website: it decides what that audit
reported, and a site that adds a shop next month must not retroactively
change what last month's report says it skipped. The website row keeps a
mirror as the latest known type, for listings.

### Never scoring a service that does not apply

`SiteType::canSellOnline()` vetoes the ecommerce and product modules for
types where a store score would be a category error rather than a low
score. A blog with a stray "checkout" link in its footer can clear the
detector's confidence threshold; it still has no store.

Local businesses and `Other` are **not** vetoed — plenty of local
businesses sell online, so the type alone does not decide and the
detector's own evidence still applies.

### Explaining the skip — a bug this closed

`AuditModuleResult` has carried `notApplicableReason` and
`detectionEvidence` since §34, and **nothing ever stored them**. The
report substituted one generic sentence for every skipped service. So a
classifier that works out a site is a blog, and says so in terms its owner
would recognise, had that explanation discarded on the way to the page it
was written for.

`audit_scores` now stores both, `ServiceReportBuilder` prefers the stored
reason over its generic fallback, and both survive the visibility filter
at every tier — a service that silently vanishes from a free report reads
as one the site passed.

### The countable half of CRO

`ConversionSignalDetector` measures; `ConversionSignalChecks` decides what
is worth reporting. They are separate so the report can show every CRO and
ecommerce area — whether or not it produced a finding — without that
display being tangled up in scoring.

These are deterministic, which means they still land when the AI provider
is unavailable, and that is exactly when a conversion report is otherwise
at its thinnest. The AI keeps the judgement calls it is actually needed
for; anything countable is answered here for free.

Details worth keeping:

- **Vague CTA matching is exact, not substring.** "Learn more" is vague;
  "Learn more about pricing" is not. A substring match would flag both and
  train readers to ignore the check.
- **Hidden inputs are not form friction.** CSRF tokens cost a visitor
  nothing.
- **Navigation counts distinct destinations**, not links. A nav repeated
  in a header and a footer is one route, and counting it twice would
  report tidy navigation as sprawl.
- **Policy pages are matched by URL *and* link text.** A returns policy
  linked from the footer as `/p/17` is still a returns policy, and a
  URL-only check would report that shop as having none.
- **Mobile checks are type-aware.** A missing tap-to-call link is a real
  omission for a local or service business and meaningless on a blog;
  raising it everywhere would be advice about a business the site does not
  run.
- **Every "not found" finding says `scope: crawled pages only`.** A
  returns policy on page 40 of a 25-page crawl is not absent, and the
  finding must not claim it is.

## 47. The report interface

Most of what this phase asked for already existed — seven independently
viewable service reports (§35), a page explorer with search, filter and
sort, per-page screenshots, grouped categories and severity styling. Three
things did not.

### A page-level score

The explorer could sort by issue count and by severity, but had no score,
so "which pages are worst" could only be answered by counting. Pages now
carry one, derived from that page's own issues using
`audit_scoring.severity_points` — **the same weights the category scores
use**, so a page scoring 68 means the same kind of thing as a category
scoring 68.

Explicitly **not** a Lighthouse score and never presented as one. It
answers "how much is wrong with this page relative to the others", which
is the question the explorer exists to sort by. It floors at zero: a
negative score would sort a badly-broken page below one with nothing
measured at all.

### "Why does this matter?" for every finding

The issue detail is specified as six parts — what is wrong, why it
matters, the affected page, the evidence, how to fix it, and the technical
detail. Five of them existed. The second only existed for accessibility
(§43), so every other service handed the reader a complaint and an
instruction with nothing in between.

`IssueExplanations` supplies one per issue code, written in consequences
rather than rules: what a visitor or a search engine does differently
because of this. Accessibility **delegates** to
`AccessibilityCheck::whyItMatters()` rather than duplicating those
strings, so the issue list and the accessibility rollup cannot disagree.
An unmapped code falls back to its category's general explanation, never
to silence — a finding with no stated reason invites the reader to dismiss
it.

It survives the visibility filter at every tier. The fix is paid content;
the reason is not. A free viewer who can see a finding but not why it
matters has been handed a complaint.

### Light, dark, and browser preference

Tailwind v4 resolves `dark:` from `prefers-color-scheme` by default, which
can express two states and not three. The site followed the browser and
offered no override at all.

`ThemeContext` holds `light | dark | system`, defaulting to `system`, and
`index.css` switches Tailwind to a class variant so all three can be
expressed. `system` is not "guess light": it reads the media query *and
keeps listening*, so a visitor whose OS switches to dark at sunset sees
the page follow without a reload. The root element also gets
`color-scheme`, which classes alone do not cover — that is what themes
scrollbars and form controls.

Every storage access is wrapped: `localStorage` throws outright in some
private-browsing modes, and a preference that cannot be saved should still
apply for the session rather than take the page down.

**Verified in a browser**, not by inspection: the browser preference
resolves to dark and sets the class, `color-scheme` and body background
together; an explicit light override survives a reload; and at 375px the
header does not overflow, with the toggle collapsing to icons.

### The consultation request

Docked bottom-right on the report, always reachable. A report is long —
seven services, a page explorer, dozens of findings — and the moment
someone decides they want help is not predictable, so a CTA that only
exists at the bottom is one most readers never reach. Deliberately a dock
rather than a modal on arrival: always available, never in the way of the
thing the visitor came to read. It posts to the existing `/api/leads`
endpoint with the audit id attached, so the enquiry arrives with the
report it is about.

## 48. Screenshots, PDF visibility and white label

### The screenshot hole

Screenshots lived on Laravel's `public` disk and were served from
`/storage/screenshots/{auditId}/{page}/desktop.png`. The audit id is a
sequential integer, so that path is walkable: anyone could enumerate it
and pull back rendered captures of other people's sites — pages that are
not linked publicly, staging sites the crawler was given access to, or
simply things not meant to be advertised. Nothing about the file said who
it belonged to.

Screenshots are now on a private disk and served through
`ScreenshotController` behind **two independent gates**:

1. **The signature** proves we issued the URL and that it has not expired.
2. **The authorization check** proves this viewer may see that audit.

The second is the one that matters. Report links get forwarded, and a
signature alone would make a leaked URL a working credential for anyone
holding it — time-limited, but a credential. With the ownership check, a
forwarded URL is simply useless. It answers **404, not 403**: confirming a
screenshot exists for an audit id is itself information about someone
else's account.

Captures taken before the move are still on `public`, and the controller
reads from either — switching the disk did not break every screenshot in
every existing report.

**The frontend consequence, which is easy to miss:** an `<img src>` cannot
send an Authorization header. Keeping the ownership check therefore meant
screenshots could no longer be loaded by a plain image tag, so
`AuthenticatedImage` fetches through the authenticated client and renders
an object URL (revoked on unmount — otherwise browsing the page explorer
leaks a blob per screenshot). The alternative was weakening the check to
signature-only, which is exactly the property that makes a forwarded
report link safe.

### PDFs respect plan visibility

`ReportPdfService` and `ServiceReportPdfService` rendered the **full**
report unconditionally, on the reasoning that export was already behind a
full-access check. But full access means "this is your audit", not "your
plan shows you all of it". An owner on a plan that reveals 30% of a report
on screen could download 100% of it as a PDF — the visibility limit was
one button away from being bypassed.

Both now apply the same visibility filter as the web report, with the
owner's own entitlements: **ownership decides whose plan applies, the plan
decides how much** (§40). The PDF also states how many findings are
withheld, rather than quietly shipping a shorter document.

A bug fell straight out of this and was caught by the first test:
`pdf.blade.php` read `$content['pages']` unguarded, and the restricted
payload has a *different shape* — page findings are full-access content,
so the key is absent rather than empty. The export crashed the moment it
started honouring visibility. Both report shapes are now handled.

### White label

`PlanFeature::WhiteLabel` had been settable and non-functional since §40,
listed in `notYetBuilt()`. It is now built, and that list is empty.

`branding_profiles` holds one row per user: brand name, logo, contact
details, footer. Per user rather than per audit or per website, because it
is the agency's own identity.

**The row exists independently of the plan.** Branding is data the user
entered; entitlement is a question asked at render time by
`BrandingResolver`, never cached — a plan that lapsed yesterday must not
still be re-branding reports today. Deleting someone's logo because they
downgraded, and losing it if they upgrade again, would be destroying their
input to enforce a limit that has a much cheaper enforcement point. The
settings form says plainly when branding is saved but not active.

`BrandingResolver` asks three questions in order — is there an owner, does
their current plan allow it, have they actually filled anything in — and a
"no" at any step falls back to the product's own branding. There is no
state in which a report renders with no identity on it, and an *empty*
profile counts as no profile: applying it would replace our branding with
nothing.

The logo is inlined into PDFs as a base64 data URI. dompdf cannot fetch
remote assets, and the file sits on a private disk behind a signed route
it has no session for — a URL would render as a broken image in the one
place the branding matters most. A logo that is missing or oversized is
dropped rather than failing the export: a missing image is cosmetic, a
failed download is not.

## 50. Password-protected report sharing

A share hands a URL to someone with no account, so the design question is
never "does it work" but "what else does it let them do".

### Two secrets, two hashes, two reasons

**The token** (in the URL) is 32 bytes of `random_bytes()` — a CSPRNG,
hex-encoded to 64 characters — and stored as a **SHA-256 hash**. A read of
`report_shares` therefore cannot be turned back into working links, which
matters because a bearer credential in a database is a credential in every
backup and every over-broad query. SHA-256 rather than bcrypt because
lookup is an exact match on every request and must stay one indexed query;
the input already carries 256 bits of entropy, so there is no dictionary
to precompute and nothing a slow hash would defend.

**The password** is bcrypt, because a human chose it. Opposite problem,
opposite tool.

The raw token exists in exactly one response — the one that created or
regenerated it. Nothing can display it again, and the UI says so, because
a system that can re-display a token can be made to.

### The URL carries no audit id

`/shared-report/{64-hex}`. A numeric id in a shared URL is an invitation
to change it. The share view also uses its own score card rather than the
owner's `ServiceScoreCard`, which renders `<Link to={/audits/${auditId}/…}>`
— reusing it would have put the id into the DOM of the one page whose
premise is that the id is not in it.

### Token, then password, then permission — in that order

`show()` returns only what a lock screen needs: that a password is wanted,
and whose branding to wear. Not the domain, not the score. A URL that
leaks the audited domain before authentication has already told a stranger
who the owner's client is.

After the password, the server issues an **encrypted, expiring access
token** — encrypted rather than signed so the share id is not even
readable by the browser holding it. It is stateless, but every request
that presents one **re-loads the share and re-checks that it is active**.
That is what makes revocation immediate: verified live, a revoked link
kills an already-open session on the next request rather than when the
token happens to expire.

The access token is bound to the URL's own token. Without that binding, an
access token minted for one share would unlock any other share whose URL
the holder knew — a straightforward IDOR through a credential that looks
entirely legitimate.

### Lockout, and its honest limit

Two limits at different heights: **per IP and token** (5) does the real
work; **per token across all callers** (50) is the backstop against a
distributed attempt.

A determined attacker *can* trip the token-wide limit and lock the real
recipient out for the window. Every global counter has that property, and
the alternative is no defence against distributed guessing at all. It is
mitigated rather than solved: the ceiling takes real effort to reach, the
owner sees the failed-attempt count on the share, and revoking or
regenerating is one click.

A successful unlock clears the counters, so someone who mistyped twice is
not carrying those toward a lockout for the rest of the window.

### A share narrows, never widens

Filtering runs in a fixed order: build → **the owner's plan visibility**
(§40/§48) → **the share's permissions**. Running share permissions alone
would let a link expose more of a report than the account that created it
is entitled to see. The same rule holds for PDF: `allow_pdf` can only
permit an export the owner's own plan already allows.

Withheld content is **emptied, not removed** — a missing key crashes a
consumer, an empty one renders as "nothing here" — and the count of what
was withheld is kept so the page can say so.

### Everything is 404

Unknown token, revoked token, expired token, a screenshot belonging to
another audit: one status, one message. Telling a holder of a leaked URL
that their URL was *right* is the one thing token secrecy exists to
withhold.

### The audit log

`report_share_events` records created, revoked, regenerated, password
changed, permissions changed, viewed, PDF downloaded, password failed and
locked out, with IP and user agent. `report_share_id` is **nullable on
delete**: the events are the audit trail, and deleting the subject must
not erase the record of what it did.

Logging never throws. Losing an audit entry is bad; refusing a legitimate
recipient because the log write failed is worse.

### noindex

Every shared response carries `X-Robots-Tag: noindex, nofollow`, the PDF
included. An unguessable URL is no protection at all once a crawler has
followed it out of an email preview or a chat unfurl.

## 51. Audit progress, skeleton UI and estimated duration

One rule shapes all of it: **never show fake progress.** No timer standing
in for work, no bar creeping toward 90% because someone is waiting, no
estimate before there is anything to estimate from. Where the honest
answer is "not yet known", the API returns null and the UI says why — a
missing estimate is a smaller betrayal than a wrong one, because a wrong
one is still believed at the five-minute mark.

### Stages are not statuses

`AuditStage` has twelve cases; `AuditStatus` has eight. They are
deliberately different sets: the states worth *enforcing* in a lifecycle
(§38) and the states worth *showing someone waiting* are not the same
question. One status covers several stages — `crawling` spans discovery,
fetching and screenshots.

Every stage is derived from something that happened, never from elapsed
time:

- **Discovering** vs **Crawling**: `crawl_jobs.total_urls > 1`. One URL is
  the seed; calling that "1 of 1" would promise a finish line the crawler
  has not found.
- **Capturing screenshots**: a `screenshots` row exists. Screenshots are
  taken during the same page load as the crawl, so this is not a later
  phase — it is the part worth naming once it is visibly happening.
- **The analysis stages**: which `audit_scores` rows have been written. A
  service is done because its result exists.

### The estimate is measured, or absent

Seconds elapsed divided by pages completed is a real rate; remaining pages
give a real projection. Before the first page finishes there is no rate,
so `is_available` is false and the reason is shown instead of a number.

The analysis tail is projected per outstanding service rather than from
the page rate, because analysis is a roughly fixed cost per module, not a
per-page one. That single number lives in `config/audit_progress.php`
rather than a per-module table: the AI-calling modules vary far more with
provider latency than with anything we could tabulate, and a table would
look more precise than it is.

`pages_discovered` is what the crawler actually found, never the plan's
page cap — showing the cap would promise pages that may not exist on the
site. The scale note tells a large-site owner plainly that it will take
longer, rather than leaving them to infer it from a bar that is not
moving.

### The skeleton shows shape, never values

The waiting view renders the report's layout as inert grey blocks. It
never renders placeholder numbers: a skeleton showing "87" while the audit
runs would be read as a result.

### A quiet failure this made visible

Building the progress panel surfaced an existing hazard: a plan with no
`features` rows attached makes `FeatureAccessResolver` deny **every**
category, so the audit runs zero modules and completes with an empty
report — silently. `featureAllowed()` guards the "no plan at all" case but
not "a plan with nothing ticked", which an admin can create through the
plan UI.

The gating policy is out of scope here and is a product decision (should a
plan with nothing configured run everything, or nothing?). What §51 does
is stop it being silent: an empty service list is stated on the progress
panel — "no audit services are enabled for this plan, so this audit will
not produce any findings" — rather than the card being hidden, which would
be the same fake-progress failure in a different guise.

## 52. Audit comparison, score trends and improvement tracking

The output is one sentence — "Performance improved from 48 to 72 (+24
points)." — and almost all of `AuditComparison` exists to refuse to say it
when it would not be true. Two audits of the same site are not
automatically comparable, and a diff that assumes they are produces
confident, quotable, wrong numbers.

### The number that must never be wrong

**A finding whose page was not crawled again has not been fixed.** If the
first audit crawled 25 pages and the second reached 5, every finding on
the 20 pages nobody looked at simply vanishes from the second report. A
naive diff calls those *resolved* — and "12 issues fixed" is exactly the
figure someone forwards to their manager.

So findings that disappeared split into two lists, never one:

- `resolved` — the page **was** re-crawled and the finding is gone.
- `not_rechecked` — the page was not re-crawled, with the reason attached.

A site-wide finding (no page attached) can never fall into the second
list: it was not tied to any page that could go missing, so its absence is
real.

Findings are keyed by issue **code**, not by row: the same code on a
different page is one kind of problem recurring, and counting each page
separately would report a site-wide fault as dozens of independent fixes.

### Not Applicable is not a score

An ecommerce assessment that stopped running because the site was
reclassified (§46) has not "declined to zero". Every score row therefore
carries `is_comparable`, and when it is false it carries the *reason* —
the stored `not_applicable_reason` where there is one — in the place the
delta would have gone. A dash alone would read as "no change", which is
the one thing such a row does not mean. Incomparable services never become
insight sentences.

A service neither run scored is omitted from the table entirely rather
than rendered as two dashes: it is not part of this site's story.

### Like-for-like means the same pages, in both directions

Dropped pages inflate `resolved`; added pages inflate `new` just as badly
— a finding on a page the first run never visited is new to the
*comparison*, not new to the *site*. Both make `is_like_for_like` false,
and the resulting note is **prepended** to the insights rather than
appended: a caveat that changes how every other line should be read
belongs above them.

Coverage is compared by URL set, not by count. Two 10-page crawls that
happened to reach different pages are no more comparable than a 25-page
and a 5-page one. URLs are normalized the same way the link graph does it
(§44), so `/about` and `/about/` are one page here too.

### Choosing what to compare against

`previousFor()` returns the previous **completed** run of the same
website. Failed and cancelled runs are skipped because they have no
scores: comparing against one would report every service as incomparable
and every finding as new — a report of total collapse produced entirely by
the comparison's own choice of baseline.

The endpoint's optional `previous` parameter is held to the same
standard, scoped to the same `website_id` and to completed audits. An id
that fails those checks is a **404**, not a silent fall-back to a
different audit: quietly comparing against something other than what was
asked for is the same class of unannounced substitution this whole class
exists to prevent. (It was originally worse — it answered "this is the
first completed audit for this website", which is false whenever earlier
audits exist.)

The endpoint is owner-only, like the page explorer: a comparison names
which pages were and were not crawled, which is exactly the detail the
preview view withholds.

### Four things only real data showed

The unit tests were green and the class still had four defects, every one
of them found by running it against three real audit pairs in the
development database:

- **"did not apply to the site" was said about services nobody ran.**
  An absent `audit_scores` row (an older audit, a plan without the
  service, a module not yet registered) was reported with the same
  sentence as a row the auditor deliberately marked Not Applicable. The
  first states a finding about the site that was never made. Now: no row
  at all reads "did not run", a row carrying `not_applicable_reason`
  quotes it verbatim, a row with `breakdown.error` (§35) reads "could not
  be assessed", and only a genuinely inapplicable row says so.
- **Overall had no display name.** It maps to no service, so the taxonomy
  handed back the raw enum value and the headline sentence read "overall
  declined from 64 to 53" — lowercase, mid-sentence, in customer-facing
  copy.
- **Landing Page and Product were missing entirely.** Both were absent
  from the comparison's category list, so two services that really scored
  never appeared in the table. `LandingPage` also shares the CRO service
  key with `Conversion`, so it needs its own label or the two rows come
  out identical.
- **A stored Not Applicable reason was discarded when neither run
  scored.** Both sides N/A fell to a generic "neither audit produced a
  score" instead of the auditor's own words ("There is no store to
  assess").

### Trends

`GET /api/audits/{audit}/comparison` returns the two-run comparison.
Longitudinal trends stay where they already were on the website detail
payload (§37): `score_trends` per category and `issue_trends` per audit.
Two changes here:

- Overall score gets its own chart rather than a sparkline, with the
  y-axis pinned to 0-100. Auto-fitting the axis to the data would turn a
  3-point wobble into a cliff.
- Each issue-trend point now carries `pages_analyzed`. An issue count only
  means something next to how much of the site was crawled to find them:
  40 issues across 25 pages and 40 across 5 are not the same result, and
  the table said nothing about which one it was showing.

## 53. AI provider management, usage tracking and token optimization

Most of what Phase 15 asked for already existed — three providers, enable/
disable, keys, model catalogue, per-provider daily and monthly request
limits (config JSON keys, enforced in `AiAnalysisService` and
`PageSpeedService`), token counts, estimated cost, failed requests,
average response time, response caching, and structured-context extraction
instead of raw HTML. What follows is only what was actually missing.

### Tiering: the rule that could not previously be followed

"Use smaller models for simple tasks, stronger models for complex
analysis" was unimplementable, not merely unimplemented. `AiProvider::analyze()`
took no model, and every provider called whatever single model its
`api_providers` row was set to, so classifying a task had nothing to act
on. `PlanAiModelResolver` even documented the consequence: a plan-allowed
model only mattered if it happened to be the provider's live model.

Three changes make the rule real:

- `AiTaskComplexity` (Simple / Standard / Complex) describes the *task*;
  `ai_models.tier` describes what a model is *fit for*.
- `analyze()` takes an optional model override, threaded through all three
  providers.
- Resolution picks the **cheapest allowed model whose tier meets the
  task**, and the check is one-directional: a Complex task is never handed
  a Simple model. Saving tokens on open-ended reasoning buys a worse answer
  at the same price.

Two defaults matter. `ai_models.tier` defaults to **Standard**, not
Simple — an admin who adds a model without thinking about tiers must not
thereby volunteer it for every cheap task in the system. And a model with
no cost rates recorded sorts **last**, not first: an unknown price is not
a low one.

The plan gate is unchanged and absolute. Tiering chooses *among* the models
a plan already permits; it never widens the set. When nothing meets the
required tier (every model Standard on an upgraded install, facing a
Complex task) resolution falls back to the pre-§53 rule rather than
returning null, so upgrading does not silently switch AI off.

Which category needs which tier lives in `config/ai.php`, because it is a
judgement about the work rather than about any provider's line-up.

### Attribution: recording the call that happened

Usage logging recorded `$provider->model` — the *configured* model. Once a
request can override it, that would describe requests that never happened,
so `logUsage()` now records the model actually called, and
`ApiProvider::estimateCost()` takes the model id so a cheap call is priced
at the cheap model's rate. Costing a Haiku call at Opus rates would corrupt
the single number the dashboard exists to report.

`prompt_version` is now stored on both `ai_analyses` and `api_usage_logs`.
`audit_snapshots` already carried one per audit (§38), but an audit spans
many calls and a cost report is per call. Old rows are deliberately not
backfilled: they were produced by a prompt version nobody recorded, and
inventing one would make guesses indistinguishable from measurements.

New: `GET /admin/usage/by-model`, plus `requests_today` and
`requests_this_month` on the summary — deliberately outside the dashboard's
date filter, since "today" is a question about now and answering it from
whatever range was selected gives a number that reads as absolute and isn't.

### §49's bug, found twice more

The switch to MySQL testing (§54) surfaced two more instances of the exact
defect §49 fixed — `string()` columns capped at varchar(191) written by
code truncating to 250:

    AiAnalysisService   substr($e->getMessage(), 0, 250) -> ai_analyses.error
    ProviderResolver    substr($message, 0, 250)         -> api_providers.last_error

This server runs `STRICT_ALL_TABLES`, so the over-length write throws
rather than truncating — and both sit inside the handler recording a
provider failure. A provider returning a long error would have had its real
error replaced by a database exception: the failure record destroyed by the
act of writing it. Both columns are now TEXT and both writers use
`AuditLifecycle::MAX_ERROR_MESSAGE`.

**The lesson is about the test engine, not the columns.** This class of bug
is structurally invisible on SQLite, which ignores varchar lengths. §49
fixed the three columns someone happened to notice; these two sat there for
another four phases.

## 54. The test suite runs on MySQL

Tests ran on in-memory SQLite: 1002 tests in 37 seconds, no running
database required. They now run on MySQL 5.7 against `audivio_test`, and
take about 22 minutes. That is a real cost, taken deliberately.

SQLite silently ignores varchar lengths. Every column-width bug is
therefore invisible to it — which is how §49's `error_message` overflow
passed a green suite and then threw against the real database, killing the
job that was recording an audit failure. The first MySQL run found two more
instances of the same defect (§53) that had survived four further phases.

Three SQLite fallbacks were also removed from the application config
(`config/database.php`, and twice in `config/queue.php`). Laravel's stock
`env('DB_CONNECTION', 'sqlite')` meant a process starting without a
readable `.env` connected to a SQLite file instead of failing — which is
exactly what happened: a dev server ran for an hour answering 500s against
a scratch database path that no longer existed. **A wrong database is worse
than no database, because the first looks like it works.**

### What the switch immediately caught

`migrate:reset` **failed on MySQL** with error 1553, and had presumably
been failing since §41. Two migrations' `down()` methods dropped indexes
that InnoDB was using to satisfy foreign keys —
`audits_user_id_status_index` and `websites_user_id_normalized_domain_unique`
are the only indexes covering those FK columns. Both now drop the
constraint, drop the index, and put the constraint back, MySQL-only.

The knock-on effect was worse than the broken rollback itself.
`DatabaseMigrationTest` runs a reset mid-suite; it aborted half-way, and
every later test inherited a schema missing `report_shares`,
`branding_profiles` and `audit_site_signals`. That single defect accounted
for most of the 62 failures in the first MySQL run — all 1002 pass once it
is fixed.

### What this costs, and what is lost

A test run now needs MySQL up, so it cannot run in a sandbox with no
database — including cloud agents. `DatabaseMigrationTest` also no longer
double-checks that migrations work on SQLite, which is what caught a
MySQL-only `LEFT()` in a `down()` during §49; that cross-engine check is
gone, traded for catching the engine-specific bugs that actually reach
production.

## 55. Consultation requests and the sales workflow

The lead table, the public endpoint, the admin screen, activity notes and
a docked CTA all existed. Six things did not, and each is something a
salesperson needs before the first call.

### The association that was only ever a string

`leads.website_url` was text somebody typed. It could not be joined, so
"show me every lead about this site" was unanswerable. Leads now carry a
real `website_id` — **taken from the audit, never from the request**. The
endpoint is public and unauthenticated, so a `website_id` it accepted on
trust is one anyone could forge, filing leads against a competitor's site.
The URL column stays, because a request from the footer of a page nobody
audited has nothing else to say which site it concerns.

The same reasoning governs `issue_codes`. A request can name the findings
the visitor was looking at, and those are filtered against the audit before
storage: keeping the raw claim would let anyone hand the sales team a
fabricated list of problems attributed to a real report. Codes that are not
on that audit are dropped; with no audit named, all of them are.
`requested_services` is filtered the same way against `IssueTaxonomy::SERVICES`.

### The pipeline gained a stage and lost a metric

`proposal` was missing. And `converted` became **`won`**: every other case
names a stage the lead is sitting in, and `converted` was the one named
after a number someone wanted to count — it read oddly beside `lost`. The
migration renames existing rows both ways; rolling back puts `proposal`
leads at `qualified`, the stage they passed through, rather than inventing
a position or dropping them to `new`.

### Notifying sales without risking the lead

There was no mail or notification infrastructure at all. `NewConsultationRequest`
is queued and sent to `config('leads.sales_recipients')`, and the send is
wrapped and happens **after** the row is written. A mail transport that is
misconfigured, throttled or down must never lose the request: losing the
notification costs a delay, losing the row costs the customer. No
recipients configured is a supported state — the lead still lands in the
admin screen.

### Four placements, and knowing which one worked

The CTA was one dock. It is now one dialog with several triggers — audit
header, score summary, critical issues (only when there are any), report
footer — plus the dock, which still follows the reader down a long page.
Each trigger reports its own `source`, so which placement actually earns
requests is measurable rather than a matter of opinion. The trigger beside
the critical findings passes those codes along, so the request arrives
saying what the visitor was looking at when they asked.

`useConsultation()` throws outside its provider rather than returning a
no-op: a trigger that renders as a working button and silently does nothing
is worse than one that is not there.

## 56. QA, regression, security and code-quality verification

A verification pass rather than a feature phase, so what follows is what
it *found*. Most categories came back clean; the defects are listed with
what each would have cost.

### Defects found and fixed

**Five unauthenticated endpoints had no rate limit** — `/cms/{slug}`,
`/plans`, `/me/features`, `/me/ai-models`, `/me/entitlements`. The old
comment justified this as "cheap, read-only, like static content", and
none of that was true: each runs real queries per request, and
`/me/entitlements` runs `EntitlementResolver`, which is deliberately never
cached because a stale entitlement is a wrong answer to a security
question (§40). They were the cheapest endpoints in the product to abuse —
no account, no token, no cost to the caller. All five now sit in a
`throttle:60,1` group; **verified live at 39 × 200 then 41 × 429**.

`PublicSurfaceTest` guards this structurally rather than by example: it
walks the router and asserts *every* unauthenticated route carries a
throttle, plus a pinned list of the public surface so it cannot grow
without someone noticing in a diff. A test of one endpoint would not have
caught the five that were wrong.

**Notes could be added to a lead but were never displayed.** `AdminLeadsPage`
posted to the activity endpoint and rendered nothing, so a salesperson had
no way to tell a saved note from a lost one. It also showed none of §55's
data — the associated website, the linked audit, the requested services,
the findings the visitor referenced. All of it was being stored and none
of it was reachable. Now behind a per-lead Details panel.

**The share event log had no viewer.** §50 records six event types per
share, including failed password attempts and IP addresses. Nothing
displayed them, which makes an audit log that exists and cannot be read.
Now shown per share, with owner actions and recipient actions visually
distinguished — reading those as the same thing is how a real intrusion
gets lost in routine noise.

**`audit:crawl` told the operator to run the wrong worker.** The hint was
hardcoded to `queue:work redis` while the app runs the database driver, so
anyone following it started a worker on a queue that would never receive
the job. Now reads `config('queue.default')`.

**Dead code**: `UsageLimitService::canCreateWebsite()`, a wrapper over
`websiteRefusalReason()` that nothing called — two entry points to one
limit is how the two drift apart. Plus two unused imports.

### The flagged items, closed

A second pass went through every "known limitation", "not fixed here" and
"product decision" left in this document. Five of them were **stale** —
already fixed by later phases, and still described as open, which is worse
than no note at all: someone reads "`product_auditor` has no backing
module" and builds a second one. Corrected in §29 with what actually
resolved each (`ProductAuditModule` exists and scores; per-request model
override landed in §53; `max_websites` is enforced; the queue driver is
declared outright).

Three were real and are now fixed:

**An audit that could assess nothing reported a score of 0.** §51 flagged
that a plan with no `features` rows denies every category, and left the
gating policy as a product decision. The part that was never a product
decision is what happened next: `dispatchAnalysis()` dispatched scoring
anyway, `calculateOverall()` returns 0 for an empty set, and the audit
**completed** with an overall score of **0** — a site nobody looked at,
presented as catastrophically bad, in a report indistinguishable from a
real one. That is the same fabrication the scoring rules forbid
everywhere else, arrived at by omission.

It now fails through `AuditLifecycle` with a message naming the cause,
because `completed` means "there is something to look at" (§38) and here
there is not. Failing through the lifecycle is also what releases the
owner's concurrent slot. The test that asserted the old behaviour
(`..._still_completes_the_audit_with_a_zero_overall_score`) asserted a
bug; it now asserts the failure and that no score row is written at all.

**A failed provider "Test Connection" discarded its own timing.** The
elapsed time was measured and then thrown away, so the admin
average-response-time figure silently excluded exactly the calls worth
timing — a provider that is timing out is slow before it is broken.
`AiProviderException` now carries `responseTimeMs` on both failure paths
in all three providers.

**There was no database backup and nothing to make one.** `db:backup`
writes a timestamped `mysqldump` to `storage/backups`, prunes to the last
N, and **deletes a failed or empty dump rather than leaving it** — a
partial dump restores cleanly and silently loses everything after the
point it stopped, which is worse than having none. `storage/backups` was
added to `.gitignore` in the same change: a dump carries hashed
passwords, encrypted provider keys and customer leads, so committing one
would be a breach, not an untidy repository.

Deliberately not built: scheduling, remote upload and encryption are
deployment decisions, and a backup command that pretends to more than it
does is worse than none.

**A later review found three defects in it**, all fixed:

- The database password was passed as `--password=` on mysqldump's
  argument list, publishing it to every local process for the life of the
  dump. It now goes through `MYSQL_PWD`. Verified by passing a deliberately
  wrong value and confirming the dump is refused — a blank dev password
  would otherwise have made the change untestable.
- No `--default-character-set=utf8mb4`, so mysqldump 5.7 negotiated
  three-byte `utf8` and would silently replace every 4-byte character
  (emoji, some CJK) with `?`. A backup that restores cleanly having
  quietly mangled rows is precisely the failure the command's own error
  handling exists to prevent.
- `fopen()`'s return was unchecked, so an unwritable backups directory
  reached `fwrite(false, ...)` — a PHP 8 TypeError instead of a message.

### Verified clean

No TODO/FIXME markers, no `dd`/`dump`/`console.log`, no hardcoded secrets
or score literals, no abandoned components, no broken class references, no
raw SQL with interpolation, no shell execution (the crawler uses array-form
`Symfony\Process`, so there is no shell to inject into), no
`dangerouslySetInnerHTML`. Every `*_id` column has a foreign key except
three that correctly cannot have one (a string model identifier, Sanctum's
polymorphic `tokenable_id`, Laravel's `sessions.user_id`); 43 tables, 46
foreign keys, all InnoDB. Zero light-only class strings missing a `dark:`
variant.

The project's own rules hold: no `$user->plan()->x` bypassing
`EntitlementResolver`, no `audits.status` write outside `AuditLifecycle`,
no `Http` facade in crawler paths, `failed()` on every queued job, and
every website-scoped controller resolving from `$request->user()->websites()`.

### Verified against reality, not fixtures

Two real sites audited end to end. The scores **differentiate**, which is
the point: wordpress.org scored 58 on performance (3.19 MB, 52 requests)
against example.com's 95 (474 bytes); SEO 91 against 64. Ecommerce and
Product came back Not Applicable on both, each carrying the stored reason
rather than a zero, and the AI-dependent services reported "Incomplete —
AI checks did not run" rather than presenting a clean score, because no
provider is enabled. Performance rows carry a null Lighthouse score with
real request and byte counts, because no PageSpeed key is configured and
the rule is never to invent one.

The full pipeline was driven through the **real database queue worker**
(crawl → modules → score → report → competitors, zero failed jobs), not
only the synchronous test driver. PDF rendered from real audit data at
7.5 KB with the site URL, service names and overall score present. Live
API checks: SSRF refused on both a disallowed port and the cloud-metadata
address, admin 401 unauthenticated, comparison and page explorer 403 for a
stranger, unsigned screenshot 403. Browser: dark mode, light mode, 375px
with no horizontal overflow, and no console errors on any page visited.

### A development database was lost

Every `audivio` table was recreated at 13:20:28 on 2026-09-05 — a
`migrate:fresh`, not a row deletion — taking 26 audits and their users and
websites with it. No dump existed. The cause was not established: both
mechanisms that looked responsible were tested and cleared (phpunit.xml's
`<env>` overrides shell exports, and artisan honours them correctly), but
the work in that window was mine. Only development data was lost; the
schema was intact and every migration applied. It was regenerated by
running the real audits above.

**There is still no backup of this database and nothing that would detect
the next occurrence.** That is the standing risk, not the lost rows.

## 57. Public site: homepage and the required standing pages

### Homepage

Rebuilt with more of the substance a visitor needs before trusting an
audit: how a run actually works, what the six services cover, what lands
in the report, and the guarantees behind the numbers. The hero stays
CMS-backed with a hardcoded fallback (§5's pattern — the fallback answers
"what if the row is deleted", not "what does this site say").

**No testimonials, customer counts, or client logos**, and none should be
added until they are real. The entire pitch of this product is that it
does not make numbers up; inventing social proof on the way in would
undercut it more than an empty page ever could. The trust section instead
describes behaviour that has tests behind it — N/A carries a reason, an
unassessed service is never scored (§56), a missing measurement is never
invented (§44/§45), and a finding on a page that was not re-crawled is
never called resolved (§52).

### Privacy, Terms, Accessibility

Three standing pages a product that collects emails, IP addresses and
crawled page content needs, reachable from the footer.

The privacy policy was written **from the actual data flows** — read off
the schema and services — rather than from a template: what the four data
groups are, that AI providers receive extracted structured summaries and
never raw HTML, that PageSpeed receives the URL, that share tokens and
passwords are stored only as hashes, that screenshots are served through
signed links. A policy describing a generic product is worse than none: it
tells the reader nothing true and gives the operator false comfort.

The terms state the limits the product really enforces (plan caps,
concurrency, rate limits) and, more importantly, the boundary of what an
automated audit can claim — that a clean accessibility score is not a
statement of ADA or WCAG compliance. That boundary is the same one §43
draws inside the report.

The accessibility statement lists what has been done **and what has
not** — no independent audit, no assistive-technology user testing,
undescribed charts, untested PDF tagging. A conformance claim with no
audit behind it is precisely the overclaim this product exists to catch,
so making one here would be self-defeating.

All three carry `[bracketed placeholders]` for company name, address and
jurisdiction, and **need review by someone qualified before launch**.
They are accurate about the software; they are not legal advice.

### Where the content lives

Only each page's `heading` (title, intro, last-updated) is seeded into the
CMS, so an operator can change a policy date or company name without a
deploy. The body prose stays in the frontend rather than being duplicated
into the seeder: legal text kept in two places drifts, and legal text is
exactly where drift does damage — a policy in the repository and a
different one on the site, with nothing saying which is in force. An admin
who wants to override a body can add a `body` section through the CMS and
`sectionContent()` prefers it.

Sections render as plain strings, never HTML, so CMS-editable content
cannot inject markup into a public page.

## 58. Visual design system

A UI/UX pass. No business logic changed; the audit pipeline, auth, plan
gating and report data paths are untouched.

### Tokens, not `dark:` variants

`src/index.css` now declares every semantic colour once — canvas, surface,
surface-2, line, line-strong, ink, muted, faint, brand, brand-strong,
brand-soft, on-brand — swapped wholesale under `.dark`, plus radius and
shadow tokens. Components say `bg-surface` / `text-muted` instead of
`bg-white dark:bg-slate-900`. That is the difference between a theme
change being one edit and being a hunt through a hundred `dark:` variants,
which is how two themes drift apart.

527 utility pairs across 69 files were migrated mechanically (exact-pair
matching only, so nothing ended up half-converted). **Some raw
`bg-white dark:bg-slate-900` pairs remain** in report and dashboard
internals where the classes were not adjacent; they render identically to
the token and are an inconsistency, not a defect.

`--color-on-brand` is the token worth naming: a brand-filled surface
always has a legible foreground. Light mode puts white on indigo (6.29:1);
dark mode **lightens** the brand so it reads as a colour against a dark
page, which made white fail at 3.45:1 — so dark mode uses dark ink on it
instead (5.49:1).

### Two real defects contrast measurement caught

**Every link ignored its text colour.** `a { color: inherit }` sat
*unlayered*, and unlayered CSS outranks every Tailwind layer regardless of
specificity — so `text-white` lost on every anchor, and the primary CTA
was rendering near-black text on indigo at **3.01:1**. Moving the rule
into `@layer base` fixed it. This was pre-existing, not introduced here.

**The hero's score colours were unreadable in light mode.** Tailwind's
500 steps measured 2.1-2.7:1 on a light card. They now use 700 (text) and
600 (rings, which are graphics at a 3:1 floor) in light, 400 in dark.

Both were found by measuring computed contrast in the browser rather than
looking. Worth recording: the first three measuring attempts produced
**false failures** — Tailwind v4 emits `oklch()` colours, which a naive RGB
parser mangles into nonsense ratios, and gradients have no background
*colour* to compare against. Converting through a canvas gave true values.
Both themes now measure **zero** contrast failures on the homepage,
pricing and login.

### Chrome

The header is sticky, frosted on scroll, and condenses (16px → 10px
padding) once the page moves. Primary navigation is deliberately short —
Pricing and Contact plus the audit CTA. Services, How It Works and FAQ
moved to the footer; they remain routed and linked, because a header that
lists everything makes the one action that matters compete with five links
that do not.

The footer is a five-group SaaS footer (Product, Services, Resources,
Company, Legal) with a brand area and its own audit CTA.

### Theme

Light is now the default for a first-time visitor. `system` remains
selectable — removing it would be a downgrade for anyone whose OS switches
at sunset — but it is chosen, not assumed. An inline script in
`index.html` applies the stored preference before first paint, using the
same light-default rule as `ThemeContext`; if the two disagreed the page
would repaint on mount, which is the flash the script exists to prevent.

### Restraint

Glass is used on the scrolled header, the mobile menu and the hero's
floating cards — surfaces that actually sit over something. Blur is capped
at 14px, with an opaque `@supports` fallback: past ~16px the content
behind turns to soup and the foreground fights it, which is what makes
glass look gimmicky rather than premium. Animation is two keyframes
(a rise-in and a slow backdrop drift), both transform/opacity only, and a
global `prefers-reduced-motion` block disables them for everyone who asked
not to be moved. No chart library, no icon package, **no new
dependencies** — the hero mock-up is inline SVG. CSS grew 2.6 kB gzipped.

The hero mock-up is labelled "Example" and uses `example.com`, for the
same reason §57 refuses testimonials: a product whose pitch is that it
does not invent numbers cannot open with invented ones dressed as real.

## 59. Admin bootstrapping

`php artisan make:admin <email>` creates an admin, or grants admin to an
existing account. Promotion never touches the password unless
`--password` is passed explicitly — promoting someone must not lock them
out of their own account. Since there is no self-service password change
yet (§29), that flag is also the only way to rotate an admin password.

There is still deliberately no HTTP endpoint for this (§9).

**A bug worth remembering:** the first version printed the generated
password with `$this->line()`, and Symfony Console parses `<...>` as
formatting tags — so a password containing `<` was displayed with a chunk
silently missing. The command reported a password that could not log
anyone in, and the failure looked like bad hashing rather than bad
output. Generated secrets printed to a console must go through
`OutputFormatter::escape()`.

Found because the credentials were tested against the real login endpoint
instead of being handed over on the assumption they worked.

**Passwords do not go on the command line.** `--ask-password` prompts
without echoing; `--password` remains for scripted use and prints a
warning at the point of use, because an argument is visible in shell
history and in the process list to every other local process. The same
reasoning moved the database password off `mysqldump`'s argument list
(§56) — a review caught that one had been fixed and the other had not.

The generated-password path also had to change its own advice: it told
the operator to rotate with `--password`, which is the exact exposure the
neighbouring branch warns about.

## Maintenance rule

Whenever you change architecture, database schema, API behavior,
business logic, project status, known bugs, or a technical decision:
**update this file in the same change**, not as a follow-up. Keep
`CLAUDE.md` for working rules and the phase timeline; keep this file as
the technical/status reference so the two don't duplicate or drift.
