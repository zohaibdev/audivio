# Test Case Registry

Central index of test coverage for Audivio. Every meaningful code change
must be checked against the relevant cases here — do not declare a task
complete on visual inspection or manual testing alone. Update this file
whenever coverage is added, moved, or removed.

**Run**: `cd backend && php artisan test` (SQLite in-memory DB, `Http::fake()`
for all external calls — no test hits a real third-party API).

**Current total**: 59 tests, all passing, 0 known failures.

Status legend: ✅ covered · ⬜ not yet covered (gap, not a defect)

---

## Unit tests

| Area | Case | Test | Status |
|---|---|---|---|
| Scoring | Deduct points sums by severity | `ScoreCalculatorTest::test_deduct_points_sums_by_severity` | ✅ |
| Scoring | Deduct points floors at 0 | `ScoreCalculatorTest::test_deduct_points_floors_at_zero` | ✅ |
| Scoring | Deduction cap takes worst issues first | `ScoreCalculatorTest::test_deduct_points_caps_at_max_deductions_worst_first` | ✅ |
| Scoring | Overall score weighted + renormalized over present categories | `ScoreCalculatorTest::test_overall_score_is_weighted_and_renormalized_over_present_categories` | ✅ |
| Audit rules — SEO | Perfect page scores 100 | `SeoAuditModuleTest::test_perfect_page_scores_100` | ✅ |
| Audit rules — SEO | Missing title → critical | `SeoAuditModuleTest::test_missing_title_is_critical` | ✅ |
| Audit rules — SEO | Missing meta description + canonical | `SeoAuditModuleTest::test_missing_meta_description_and_canonical` | ✅ |
| Audit rules — SEO | Missing / multiple H1 | `SeoAuditModuleTest::test_missing_and_multiple_h1` | ✅ |
| Audit rules — SEO | Images missing alt aggregated per page (not per image) | `SeoAuditModuleTest::test_images_missing_alt_are_aggregated_per_page` | ✅ |
| Audit rules — SEO | Duplicate titles flagged once site-wide (not per page) | `SeoAuditModuleTest::test_duplicate_titles_across_pages_flagged_once` | ✅ |
| Audit rules — SEO | Broken internal link only flagged for crawled targets | `SeoAuditModuleTest::test_broken_internal_link_detected_only_for_crawled_targets` | ✅ |
| Audit rules — Accessibility | No violations scores 100 | `AccessibilityAuditModuleTest::test_no_violations_scores_100` | ✅ |
| Audit rules — Accessibility | Same rule across pages raised once, not per page | `AccessibilityAuditModuleTest::test_same_rule_repeated_across_pages_is_raised_once` | ✅ |
| Audit rules — Accessibility | Highest impact seen for a rule wins | `AccessibilityAuditModuleTest::test_highest_impact_seen_for_a_rule_wins` | ✅ |
| Audit rules — Accessibility | Pages with no violation scan ignored, not penalized | `AccessibilityAuditModuleTest::test_pages_with_no_violation_scan_are_ignored_not_penalized` | ✅ |
| Audit rules — Ecommerce | Non-ecommerce site scores 100, zero issues, **zero AI calls** | `EcommerceAuditModuleTest::test_non_ecommerce_site_scores_100_with_no_issues` | ✅ |
| Audit rules — Ecommerce | Purchase CTA without Product schema flagged | `EcommerceAuditModuleTest::test_purchase_cta_without_product_schema_flagged` | ✅ |
| Audit rules — Ecommerce | Product schema without purchase CTA flagged | `EcommerceAuditModuleTest::test_product_schema_without_purchase_cta_flagged` | ✅ |
| Audit rules — Ecommerce | Shopify platform treated as ecommerce even without other signals | `EcommerceAuditModuleTest::test_shopify_platform_treated_as_ecommerce_even_without_signals` | ✅ |
| Audit rules — AIO | Well-structured page scores 100 | `AioAuditModuleTest::test_well_structured_page_scores_100` | ✅ |
| Audit rules — AIO | No structured data anywhere flagged | `AioAuditModuleTest::test_no_structured_data_anywhere_is_flagged` | ✅ |
| Audit rules — AIO | Page with no headings flagged high | `AioAuditModuleTest::test_page_with_no_headings_is_flagged_high` | ✅ |
| Audit rules — Landing Page | Deterministic no-CTA / no-heading checks | — | ⬜ gap: module exists (`LandingPageAuditModule`), no dedicated test yet |
| Audit rules — Conversion | Form-friction deterministic check | — | ⬜ gap: module exists (`ConversionAuditModule`), no dedicated test yet |
| Audit rules — Performance | Load time / page weight / image dimension checks | — | ⬜ gap: module exists (`PerformanceAuditModule`), no dedicated test yet (exercised only via end-to-end manual runs, see below) |
| AI | Parses plain JSON array response | `AiIssueParserTest::test_parses_plain_json_array` | ✅ |
| AI | Strips a markdown code fence | `AiIssueParserTest::test_strips_markdown_code_fence` | ✅ |
| AI | Invalid severity falls back to medium | `AiIssueParserTest::test_invalid_severity_falls_back_to_medium` | ✅ |
| AI | Malformed JSON returns empty array, never throws | `AiIssueParserTest::test_malformed_json_returns_empty_array` | ✅ |
| AI | Empty array response handled | `AiIssueParserTest::test_empty_array_response_returns_empty_array` | ✅ |
| AI | Entries missing `message` skipped | `AiIssueParserTest::test_entries_missing_message_are_skipped` | ✅ |
| AI | Claude: parses response + token usage | `ProvidersTest::test_claude_provider_parses_response_and_usage` | ✅ |
| AI | Claude: appends context as JSON in the message | `ProvidersTest::test_claude_provider_appends_context_as_json` | ✅ |
| AI | Claude: throws without an API key | `ProvidersTest::test_claude_provider_throws_without_api_key` | ✅ |
| AI | Claude: throws on non-2xx status | `ProvidersTest::test_claude_provider_throws_on_error_status` | ✅ |
| AI | OpenAI: parses response + token usage | `ProvidersTest::test_openai_provider_parses_response_and_usage` | ✅ |
| AI | Gemini: parses response + token usage, key sent as query param | `ProvidersTest::test_gemini_provider_parses_response_and_usage` | ✅ |
| AI | OpenAI/Gemini: throws without key / on error status | — | ⬜ gap: only Claude has this pair tested; behavior is identical across providers by construction (shared `AiProviderException` pattern) but not independently verified for the other two |
| AI | Second identical call hits cache, not the provider | `AiAnalysisServiceTest::test_second_identical_call_hits_cache_not_the_provider` | ✅ |
| AI | Different context is not a cache hit | `AiAnalysisServiceTest::test_different_context_is_not_a_cache_hit` | ✅ |
| AI | Provider failure returns null + records an error row | `AiAnalysisServiceTest::test_provider_failure_returns_null_and_logs_an_error_row` | ✅ |
| AI | Caching disabled calls the provider every time | `AiAnalysisServiceTest::test_caching_disabled_calls_provider_every_time` | ✅ |
| Platform detection | Shopify/WordPress/WooCommerce/Webflow/Wix/Custom/Unknown signature matching | — | ⬜ gap: `PlatformDetector` has no dedicated unit test; only exercised via end-to-end manual crawl runs (wordpress.org correctly detected as `wordpress`) |
| URL normalization | Trailing-slash homepage dedup | — | ⬜ gap: fixed as a real bug (see CONTEXT.md §26) but not regression-tested — a sitemap URL matching the seed URL modulo trailing slash should not be double-created as a `CrawlUrl` |
| SSRF / URL security | Loopback (IPv4 `127.0.0.1` + IPv6 `::1`), private ranges (10/8, 172.16/12, 192.168/16), cloud metadata (`169.254.169.254`), IPv6 unique-local (`fc00::/7`) | `UrlSecurityValidatorTest::test_loopback_ipv4_is_blocked` and 6 sibling `test_*_is_blocked` methods | ✅ |
| SSRF / URL security | Public IP literal allowed | `UrlSecurityValidatorTest::test_public_ip_literal_is_allowed` | ✅ |
| SSRF / URL security | `localhost` / case-insensitive / `*.localhost` subdomain blocked | `UrlSecurityValidatorTest::test_localhost_hostname_is_blocked` + 2 siblings | ✅ |
| SSRF / URL security | Disallowed scheme (`ftp://`, `file://`) blocked, `http`/`https` allowed | `UrlSecurityValidatorTest::test_ftp_scheme_is_blocked`, `test_file_scheme_is_blocked` | ✅ |
| SSRF / URL security | Non-standard port blocked, 80/443 allowed | `UrlSecurityValidatorTest::test_non_standard_port_is_blocked`, `test_standard_https_port_is_allowed` | ✅ |
| SSRF / URL security | Embedded credentials blocked | `UrlSecurityValidatorTest::test_embedded_credentials_are_blocked` | ✅ |
| SSRF / URL security | Oversized query string blocked | `UrlSecurityValidatorTest::test_oversized_query_string_is_blocked` | ✅ |
| SSRF / URL security | Malformed URL blocked | `UrlSecurityValidatorTest::test_malformed_url_is_blocked` | ✅ |
| SSRF / URL security | Real public hostname resolves via DNS and is allowed (sanity check against the actual DNS-resolution code path) | `UrlSecurityValidatorTest::test_real_public_hostname_resolves_and_is_allowed` | ✅ |
| SSRF / URL security | Redirect-hop re-validation (PHP: caller responsibility per-hop; Node: `crawl.mjs` `page.route()` re-check) | — | ⬜ gap: the Node-side redirect re-validation was manually verified in Phase 2 (localhost/127.0.0.1/192.168.1.1/169.254.169.254 all correctly rejected via `node crawl.mjs`), but there's no Node test runner in this repo yet, so it isn't automated |
| Usage calculation | Plan limit enforcement | — | ⬜ gap: not built yet (no `plans` table — see CONTEXT.md §19) |
| Report visibility | Anonymous 60% visibility split | — | ⬜ gap: not built yet (see CONTEXT.md §20) |
| Recommendation logic | — | — | ⬜ gap: recommendations are currently just the `recommendation` string on each issue; no separate recommendation-generation logic exists to test yet |
| Severity calculation | Covered indirectly via `ScoreCalculator` and each module's issue tests above | — | ✅ (via above) |

## Feature tests

| Flow | Test | Status |
|---|---|---|
| Signup | — | ⬜ not built (Phase 6) |
| Login | — | ⬜ not built (Phase 6) |
| Website creation | — | ⬜ not built (no API endpoint yet) |
| Audit creation | — | ⬜ not built (no API endpoint yet — CLI-only, see below) |
| Crawler dispatch | Exercised end-to-end manually via `php artisan audit:crawl` against example.com and wordpress.org during Phase 2-4 development; **not an automated test** | ⬜ gap: no `CrawlOrchestrator`/job-level Feature test exists — coverage today is manual runs recorded in CONTEXT.md, not a repeatable assertion-based test |
| Audit completion (full pipeline: crawl → 7 modules → overall score) | Same as above — manually verified end-to-end, scores hand-checked against expected math | ⬜ gap: same, no automated Feature test |
| Report generation | — | ⬜ not built (Phase 5) |
| Usage limits | — | ⬜ not built (Phase 6) |
| Recrawl | — | ⬜ not built (Phase 6) |
| Lead creation | — | ⬜ not built (no `leads` table yet) |
| Admin operations | — | ⬜ not built (Phase 7) |

**The default `Tests\Feature\ExampleTest`** (Laravel scaffold, asserts `/`
returns 200) still exists and passes — not yet replaced with a real
Feature test since there is no real HTTP-facing feature to test.

## API tests

None — no API endpoints exist beyond the Sanctum-scaffolded
`GET /api/user`. All categories (authentication, authorization,
validation, status codes, response schema, error handling, rate limits,
security) are gaps pending Phase 5/6.

## Database tests

| Check | Status |
|---|---|
| Fresh migrations run cleanly | ✅ verified manually every phase (`php artisan migrate:fresh`), not an automated test |
| Every table is InnoDB with enforced FKs | ✅ verified manually via `information_schema` query in Phase 2 after finding the MyISAM default-engine issue; not an automated test |
| Migration rollback (`down()`) | ⬜ gap: `down()` methods exist on every migration but have never been executed/verified |
| Unique constraints (`audit_scores` per audit+category, `ai_analyses` cache key) | ✅ exercised indirectly — `updateOrCreate` calls in `RunAuditModuleJob`/`CalculateScoreJob`/`AiAnalysisService` rely on these and are covered by the Unit tests above, but no test directly asserts the DB rejects a duplicate insert |
| Cascade delete behavior | ⬜ gap: FKs declare `cascadeOnDelete()`/`nullOnDelete()` but no test deletes a parent row and asserts children are removed/nulled |

## Frontend tests

None — no frontend code exists beyond the Vite scaffold (see CONTEXT.md
§22). All categories (components, forms, dashboard, audit progress,
report, locked content, signup, error/loading states) are gaps pending
Phase 5/6 frontend work.

## E2E tests

None — no frontend to drive, no public API to call. All flows (anonymous
submits URL, crawler starts, audit completes, partial report displayed,
signup, recrawl unlocked, full report generated, usage limits enforced,
consultant lead submitted) are gaps pending later phases.

## Manual/exploratory verification (not automated — recorded for traceability)

These were actually run and their results checked by hand during
development; they are not repeatable `php artisan test` assertions and
should be converted to automated tests before Phase 9 (Testing and
Security hardening):

- SSRF blocking: `localhost`, `127.0.0.1`, `192.168.1.1`, `169.254.169.254`
  (cloud metadata), `ftp://`, port `8080` — all correctly rejected at
  both the PHP (`UrlSecurityValidator`) and Node (`crawler/lib/ssrf.mjs`)
  layers (Phase 2).
- Full crawl→7-module pipeline against `https://example.com` and
  `https://wordpress.org` — page limits respected, no duplicate pages,
  sitemap-index discovery worked (459 URLs found on wordpress.org),
  platform correctly detected as `wordpress`, weighted overall scores
  matched hand-computed expected values (Phases 2-4).
- `robots.txt`/`sitemap.xml` fetch failing with a 403 from Guzzle's
  default User-Agent, fixed by sending an explicit UA (Phase 2).
- `ai_analyses` correctly recorded 3 failure rows (landing_page,
  conversion, aio) and correctly recorded **zero** rows for ecommerce on
  a non-commerce site — confirming the "skip the AI call entirely, not
  just its result" optimization (Phase 4).

---

## Known gaps summary (prioritized for Phase 9, or sooner if touching that area)

1. ~~`UrlSecurityValidator` has zero automated tests despite being the
   most security-critical code in the repo~~ — **closed**: 19 tests added
   (`UrlSecurityValidatorTest`) covering IP-range blocking, localhost
   variants, scheme/port/credential/query-length validation, and a real-
   DNS sanity check. `crawler/lib/ssrf.mjs` (the Node-side redirect
   re-validation) remains manually-verified only — no Node test runner
   exists in this repo yet.
2. No automated Feature/integration test drives the full crawl→analysis
   pipeline — only manual CLI runs. A test using `Http::fake()` for
   PageSpeed/AI and a stub for the Node subprocess (or a real headless
   crawl against a local fixture page) would close this.
3. `PlatformDetector`, `PerformanceAuditModule`, `LandingPageAuditModule`,
   `ConversionAuditModule` have modules but no dedicated unit tests yet.
4. Migration rollback and cascade-delete behavior are unverified.
5. Everything under Feature/API/Frontend/E2E is a gap because the
   corresponding features don't exist yet — not a testing shortfall,
   just not built (see CONTEXT.md §25 for phase status).
