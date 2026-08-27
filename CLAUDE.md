# Audivio — Working Rules

AI Website Audit SaaS. **See [CONTEXT.md](CONTEXT.md) for architecture,
schema, current status, known bugs/limitations, and past decisions —
read it before starting any task.** See [tests/TEST_CASES.md](tests/TEST_CASES.md)
for the test registry.

## Development phases

Work phase by phase — do not build the whole app at once. Do not
implement a later phase's scope unless explicitly asked.

1. Days 1-4: Foundation — ✅ done
2. Days 5-12: Crawler — ✅ done
3. Days 13-21: Core Auditors (performance, accessibility, SEO) — ✅ done
4. Days 22-31: AI Auditors (ecommerce, landing page, conversion, AIO, AI provider implementations) — ✅ done
5. Days 32-36: Report System — next up
6. Days 37-40: User System (full auth, subscriptions, plans)
7. Days 41-46: Admin and CMS
8. Days 47-50: PDF and Scheduling
9. Days 51-58: Testing and Security
10. Days 59-60: Production

## MVP exclusions (do not build unless explicitly requested)

Shopify/WordPress/WooCommerce platform integrations, automated fixes,
competitor analysis, white-label reports, a public API, an agency
dashboard, team management, complex billing.

## Working rules

- Read [CONTEXT.md](CONTEXT.md) first; inspect only the files/modules
  directly relevant to the requested change.
- Architecture before implementation; migrations before dependent models.
- Keep crawler logic out of controllers — service classes for audit
  engines, each audit module independent (`App\Contracts\AuditModule`,
  registered in `AuditModuleRegistry`).
- AI calls only through the `AiProvider` abstraction
  (`App\Services\AI\AiAnalysisService`), never called directly.
- Never crawl synchronously — always via queue jobs.
- Scoring and plan limits configurable (`config/audit_scoring.php`,
  `config/crawler.php`), never hardcoded; CMS content never hardcoded.
- Log crawl failures (`crawler` channel) and AI failures (`ai` channel).
- Protect every crawled URL against SSRF, at both the PHP and Node layers
  — see CONTEXT.md §24.
- Optimize AI token usage: extract structured context first
  (`PageContextSummarizer`), cache via `ai_analyses`, skip the AI call
  entirely when a module's deterministic checks already show it doesn't
  apply (see Ecommerce in CONTEXT.md §18), never send raw HTML.
- Reusable React components, responsive UI, consistent API response
  shape (`App\Http\Responses\ApiResponse`).
- Write tests for critical audit rules; update
  [tests/TEST_CASES.md](tests/TEST_CASES.md) when adding coverage.
- Update [CONTEXT.md](CONTEXT.md) in the same change whenever
  architecture, schema, API behavior, business logic, status, known
  bugs, or a technical decision changes — not as a follow-up.
