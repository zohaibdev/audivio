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

Not built yet (do not build ahead of schedule): crawler, any audit module
logic, AI provider implementations, report generation, PDF export,
subscriptions/plans, admin, CMS, scheduling.

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
