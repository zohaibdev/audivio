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
white-label reports, a public API, an agency dashboard, team management,
complex billing.

(Competitor analysis left this list in Phase 5 and white-label reports in
Phase 11, both of which commissioned and built them — see CONTEXT.md §42
and §48.)

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
- Index new query shapes as you add them, especially anything on the audit
  creation or website-listing path (CONTEXT.md §41). The engine is pinned
  to InnoDB in `config/database.php`, so migrations no longer need
  `$table->engine` — but never set it to anything else: MyISAM silently
  ignores foreign keys and transactions.
- A shared-report token is a bearer credential: generate it with
  `random_bytes()`, store only its SHA-256 hash, and return it raw exactly
  once (CONTEXT.md §50). Never put an audit id in a shared URL, never
  reveal whether an unknown token differs from a revoked one, and re-check
  `isActive()` on every request rather than trusting an issued session.
- Backlink data comes from `App\Contracts\BacklinkProvider` or nowhere.
  Never estimate, model, or substitute zero for a missing figure — an
  invented backlink count is indistinguishable from a measured one
  (CONTEXT.md §44). The same rule applies to any third-party index added
  later.
- Read every plan limit and feature flag through
  `App\Services\Plan\EntitlementResolver` — never `$user->plan()->x`. It is
  what makes guests, active subscribers and lapsed ones all resolve
  correctly (CONTEXT.md §40). Do not cache its result: a stale entitlement
  is a wrong answer to a security question.
- Change an audit's status only through `App\Services\Audit\AuditLifecycle`
  — it stamps `terminated_at` and refuses to move an already-terminal
  audit (CONTEXT.md §38). Every queued job must call `shouldStop()` first
  and define a `failed()` handler; an audit stranded in an active state
  holds its owner's concurrent slot forever.
- Gate audit creation with `ActiveAuditLimiter::withSlot()`, never a
  bare count-then-create.
- One `websites` row per normalized domain per user — never create a
  website row directly from a controller; go through
  `App\Services\Website\WebsiteResolver` (CONTEXT.md §37).
- Resolve a website from the caller's own records
  (`$request->user()->websites()`), never from a bare id or a query
  parameter — a domain in a URL selects, it never authorizes.
- On public, unauthenticated endpoints, derive every association instead
  of accepting it: a lead's website comes from its audit, and any findings
  it claims are filtered against that audit before storage (CONTEXT.md
  §55). An association taken on trust is one anyone can forge.
- Notifications never gate the write they describe. Record the row first,
  then send, wrapped — losing a notification costs a delay, losing the row
  costs the customer (CONTEXT.md §55).
- Normalize every user-supplied URL before validating it, and store and
  crawl the normalized string — never validate one spelling and use
  another. Protect every crawled URL against SSRF at both the PHP and
  Node layers, and re-validate every redirect hop — see CONTEXT.md §26.
  All PHP-side fetches of a user-supplied address go through
  `App\Services\Crawler\SafeHttpClient`, never the `Http` facade.
- Optimize AI token usage: extract structured context first
  (`PageContextSummarizer`), cache via `ai_analyses`, skip the AI call
  entirely when a module's deterministic checks already show it doesn't
  apply (see Ecommerce in CONTEXT.md §18), never send raw HTML. Classify
  new AI work in `config/ai.php` so it runs on the cheapest model fit for
  it — but never downgrade open-ended reasoning to save tokens, and never
  let a task run below its tier (CONTEXT.md §53).
- Record what actually happened, not what was configured: usage logging
  stores the model a request really used and prices it at that model's
  rate. A dashboard built on configured values describes requests that
  never happened (CONTEXT.md §53).
- Error columns are prose and must be TEXT, never `string()`. Two rounds
  of the same bug (§49, §53) came from varchar(191) columns written by
  code truncating to 250, under `STRICT_ALL_TABLES`, inside the handler
  recording a failure — destroying the record by writing it. Truncate with
  `AuditLifecycle::MAX_ERROR_MESSAGE`.
- Tests run against MySQL (`audivio_test`), not SQLite, so column widths
  and engine-specific SQL are real (CONTEXT.md §54). The suite takes ~22
  minutes and needs MySQL running. Every migration must have a `down()`
  that actually runs on MySQL — dropping an index InnoDB is using for a
  foreign key fails with error 1553. **Never run two test processes at
  once, or tests alongside a queue worker or live audits, against this
  MySQL instance** — it produces deadlocks on `ALTER TABLE` that look
  exactly like code defects (§56).
- An audit that can assess nothing must fail, never complete. A score of
  0 for a site no module looked at is a fabricated result, and `completed`
  means "there is something to look at" (§38, §56).
- Back up before anything destructive: `php artisan db:backup`. A dev
  database was lost to a `migrate:fresh` with no dump in existence (§56).
  Dumps live in `storage/backups` and are git-ignored — they carry hashed
  passwords and encrypted provider keys.
- Every unauthenticated route carries a `throttle:` — no exceptions, and
  "it's only a read" is not one. `PublicSurfaceTest` enforces this by
  walking the router, and pins the list of public URIs; if that list
  changes in a diff, re-reason about the new entry rather than updating
  the expectation (CONTEXT.md §56).
- If it is stored, it must be reachable. §56 found notes written to a lead
  that nothing displayed and a six-event security log with no viewer —
  both are worse than not collecting the data, because they look like
  working features. When adding a column or an event, add the thing that
  shows it in the same change.
- Reusable React components, responsive UI, consistent API response
  shape (`App\Http\Responses\ApiResponse`).
- Style from the semantic tokens in `src/index.css` (`bg-surface`,
  `text-muted`, `border-line`, `text-on-brand`), never raw palette steps
  with `dark:` twins — that pairing is how the two themes drift apart
  (CONTEXT.md §58). A brand-filled surface always uses `text-on-brand`.
- Never put a bare element selector outside `@layer base`. Unlayered CSS
  outranks every Tailwind layer, and a stray `a { color: inherit }` beat
  `text-white` on every anchor for months (§58).
- Measure contrast, do not eyeball it — and convert colours through a
  canvas when you do: Tailwind v4 emits `oklch()`, which naive RGB parsing
  turns into false failures (§58).
- Write tests for critical audit rules; update
  [tests/TEST_CASES.md](tests/TEST_CASES.md) when adding coverage.
- Update [CONTEXT.md](CONTEXT.md) in the same change whenever
  architecture, schema, API behavior, business logic, status, known
  bugs, or a technical decision changes — not as a follow-up.
