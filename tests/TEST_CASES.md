# Test Case Registry

Central index of test coverage for Audivio. Every meaningful code change
must be checked against the relevant cases here — do not declare a task
complete on visual inspection or manual testing alone. Update this file
whenever coverage is added, moved, or removed.

**Run**: `cd backend && php artisan test` (SQLite in-memory DB, `Http::fake()`
for all external calls — no test hits a real third-party API) and
`cd crawler && npm test` (Node's built-in test runner, `node --test`).

**Current total**: 394 tests (354 PHP + 40 Node), all passing, 0 known failures.
Frontend verification is manual only (browser, against the real backend)
— see the "Frontend manual verification" entry near the bottom; there is
no automated frontend test suite yet.

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
| Audit rules — SEO | The same issue on every page (e.g. 25/25 missing meta description) collapses to one site-wide issue and does not zero the score | `SeoAuditModuleTest::test_the_same_issue_on_every_page_is_aggregated_into_one_and_does_not_zero_the_score` — regression for a real bug found auditing a live 25-page site (CONTEXT.md §34) | ✅ |
| Audit rules — Accessibility | No violations scores 100 | `AccessibilityAuditModuleTest::test_no_violations_scores_100` | ✅ |
| Audit rules — Accessibility | Same rule across pages raised once, not per page | `AccessibilityAuditModuleTest::test_same_rule_repeated_across_pages_is_raised_once` | ✅ |
| Audit rules — Accessibility | Highest impact seen for a rule wins | `AccessibilityAuditModuleTest::test_highest_impact_seen_for_a_rule_wins` | ✅ |
| Audit rules — Accessibility | Pages with no violation scan ignored, not penalized | `AccessibilityAuditModuleTest::test_pages_with_no_violation_scan_are_ignored_not_penalized` | ✅ |
| Audit rules — Ecommerce | Non-ecommerce site is Not Applicable (`score: null`, `is_applicable: false`), zero issues, **zero AI calls** | `EcommerceAuditModuleTest::test_non_ecommerce_site_is_not_applicable_with_no_score` — was "scores 100" before `EcommerceDetector` (CONTEXT.md §34); a Not Applicable category is no longer a fabricated 100 | ✅ |
| Audit rules — Ecommerce | A lone generic transaction CTA ("checkout"/"buy") does not trigger ecommerce detection | `EcommerceAuditModuleTest::test_a_lone_generic_checkout_cta_does_not_trigger_ecommerce_detection` — regression for the reported false-positive bug (nonprofit ticket-order flow) | ✅ |
| Audit rules — Ecommerce | A checkout-shaped link *plus* a generic CTA alone still does not trigger detection | `EcommerceAuditModuleTest::test_a_checkout_link_plus_a_generic_cta_alone_does_not_trigger_ecommerce_detection` — a second false positive on the *same* real site, found only by re-auditing after the first fix (CONTEXT.md §34) | ✅ |
| Audit rules — Ecommerce | Purchase CTA without Product schema flagged | `EcommerceAuditModuleTest::test_purchase_cta_without_product_schema_flagged` | ✅ |
| Audit rules — Ecommerce | Product schema without purchase CTA flagged | `EcommerceAuditModuleTest::test_product_schema_without_purchase_cta_flagged` | ✅ |
| Audit rules — Ecommerce | Shopify platform treated as ecommerce even without other signals | `EcommerceAuditModuleTest::test_shopify_platform_treated_as_ecommerce_even_without_signals` | ✅ |
| Audit rules — AIO | Well-structured page scores 100 | `AioAuditModuleTest::test_well_structured_page_scores_100` | ✅ |
| Audit rules — AIO | No structured data anywhere flagged | `AioAuditModuleTest::test_no_structured_data_anywhere_is_flagged` | ✅ |
| Audit rules — AIO | Page with no headings flagged high | `AioAuditModuleTest::test_page_with_no_headings_is_flagged_high` | ✅ |
| Audit rules — Landing Page | Page with CTA + heading scores 100 | `LandingPageAuditModuleTest::test_page_with_cta_and_heading_scores_100` | ✅ |
| Audit rules — Landing Page | No CTA anywhere flagged high | `LandingPageAuditModuleTest::test_no_cta_anywhere_is_flagged_high` | ✅ |
| Audit rules — Landing Page | Page with no headings flagged medium | `LandingPageAuditModuleTest::test_page_with_no_headings_is_flagged_medium` | ✅ |
| Audit rules — Landing Page | CTA count summed across all pages (one page's CTA saves the site-wide check) | `LandingPageAuditModuleTest::test_cta_count_is_summed_across_all_pages` | ✅ |
| Audit rules — Conversion | No forms scores 100 | `ConversionAuditModuleTest::test_page_with_no_forms_scores_100` | ✅ |
| Audit rules — Conversion | Form at the friction threshold (6 required fields) not flagged | `ConversionAuditModuleTest::test_form_at_the_friction_threshold_is_not_flagged` | ✅ |
| Audit rules — Conversion | Form over the friction threshold (7 required fields) flagged medium | `ConversionAuditModuleTest::test_form_over_the_friction_threshold_is_flagged` | ✅ |
| Audit rules — Conversion | Only required fields count toward friction (optional fields don't) | `ConversionAuditModuleTest::test_only_required_fields_count_toward_friction` | ✅ |
| Audit rules — Performance | Fast lightweight page scores 100 | `PerformanceAuditModuleTest::test_fast_lightweight_page_scores_100` | ✅ |
| Audit rules — Performance | Slow load time flagged | `PerformanceAuditModuleTest::test_slow_load_time_flagged` | ✅ |
| Audit rules — Performance | Oversized page weight flagged | `PerformanceAuditModuleTest::test_oversized_page_weight_flagged` | ✅ |
| Audit rules — Performance | Images missing dimensions flagged, count aggregated | `PerformanceAuditModuleTest::test_images_missing_dimensions_flagged` | ✅ |
| Audit rules — Performance | PSI score <50 → critical, <90 → medium, ≥90 → not flagged | `PerformanceAuditModuleTest::test_low_pagespeed_score_is_critical` + 2 siblings | ✅ |
| Audit rules — Performance | Poor LCP (>2500ms) → high | `PerformanceAuditModuleTest::test_poor_lcp_is_flagged_high` | ✅ |
| Audit rules — Performance | Poor CLS (>0.1) → medium | `PerformanceAuditModuleTest::test_poor_cls_is_flagged_medium` | ✅ |
| Audit rules — Performance | PSI only called for the configured page count (mocked `PageSpeedService`, `->once()` expectation) | `PerformanceAuditModuleTest::test_pagespeed_only_called_for_configured_page_count` | ✅ |
| Audit rules — Performance | The same issue on every page (e.g. 25/25 slow load time) collapses to one site-wide issue and does not zero the score | `PerformanceAuditModuleTest::test_the_same_issue_on_every_page_is_aggregated_into_one_and_does_not_zero_the_score` — regression for the same real-site bug as SEO's equivalent test (CONTEXT.md §34) | ✅ |
| Per-page issue aggregation | Single occurrence of a code passes through unchanged; 2+ occurrences collapse into one site-wide issue with `affected_pages`/`affected_page_count`/`affectedPageIds`; different codes never merge; affected-pages list is deduplicated | `PerPageIssueAggregatorTest` (4 tests) — the shared logic `SeoAuditModule`/`PerformanceAuditModule` both use, extracted so the aggregation rule (and its bug fix) lives in one place, CONTEXT.md §34 | ✅ |
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
| AI | OpenAI/Gemini: throws without key / on error status | `ProvidersTest::test_openai_provider_throws_without_api_key`, `test_openai_provider_throws_on_error_status`, `test_gemini_provider_throws_without_api_key`, `test_gemini_provider_throws_on_error_status` | ✅ |
| AI | Second identical call hits cache, not the provider | `AiAnalysisServiceTest::test_second_identical_call_hits_cache_not_the_provider` | ✅ |
| AI | Different context is not a cache hit | `AiAnalysisServiceTest::test_different_context_is_not_a_cache_hit` | ✅ |
| AI | Provider failure returns null + records an error row | `AiAnalysisServiceTest::test_provider_failure_returns_null_and_logs_an_error_row` | ✅ |
| AI | Caching disabled calls the provider every time | `AiAnalysisServiceTest::test_caching_disabled_calls_provider_every_time` | ✅ |
| AI | No plan-allowed model records a visible `ai_analyses` error row instead of being silent | `AiAnalysisServiceTest::test_no_plan_allowed_model_records_an_error_row_instead_of_being_silent` — closes a visibility regression introduced by §33's `PlanAiModelResolver` (CONTEXT.md §34); asserts zero `api_usage_logs` rows too, since no provider was ever chosen | ✅ |
| Platform detection | Shopify (CDN reference, header, case-insensitive header) | `PlatformDetectorTest::test_detects_shopify_from_cdn_reference` + 2 siblings | ✅ |
| Platform detection | WordPress (wp-content, generator meta) | `PlatformDetectorTest::test_detects_wordpress_from_wp_content` + 1 sibling | ✅ |
| Platform detection | WooCommerce (WordPress + commerce markers) vs plain WordPress | `PlatformDetectorTest::test_detects_woocommerce_as_wordpress_plus_woocommerce_markers`, `test_wordpress_without_woocommerce_markers_is_plain_wordpress` | ✅ |
| Platform detection | Webflow (data-wf-site attribute) | `PlatformDetectorTest::test_detects_webflow_from_data_attribute` | ✅ |
| Platform detection | Wix (static asset domain, header) | `PlatformDetectorTest::test_detects_wix_from_static_asset_domain`, `test_detects_wix_from_header` | ✅ |
| Platform detection | Signal priority (Shopify wins over a simultaneous WordPress match) | `PlatformDetectorTest::test_shopify_signal_takes_priority_over_wordpress` | ✅ |
| Platform detection | Unrecognized generator meta → Custom; no signal at all → Unknown | `PlatformDetectorTest::test_unrecognized_generator_meta_is_custom`, `test_no_signals_at_all_is_unknown`, `test_empty_html_is_unknown` | ✅ |
| URL normalization | Trailing-slash homepage dedup | — | ⬜ gap: fixed as a real bug (see CONTEXT.md §28) but not regression-tested — a sitemap URL matching the seed URL modulo trailing slash should not be double-created as a `CrawlUrl` |
| SSRF / URL security | Loopback (IPv4 `127.0.0.1` + IPv6 `::1`), private ranges (10/8, 172.16/12, 192.168/16), cloud metadata (`169.254.169.254`), IPv6 unique-local (`fc00::/7`) | `UrlSecurityValidatorTest::test_loopback_ipv4_is_blocked` and 6 sibling `test_*_is_blocked` methods | ✅ |
| SSRF / URL security | Public IP literal allowed | `UrlSecurityValidatorTest::test_public_ip_literal_is_allowed` | ✅ |
| SSRF / URL security | `localhost` / case-insensitive / `*.localhost` subdomain blocked | `UrlSecurityValidatorTest::test_localhost_hostname_is_blocked` + 2 siblings | ✅ |
| SSRF / URL security | Disallowed scheme (`ftp://`, `file://`) blocked, `http`/`https` allowed | `UrlSecurityValidatorTest::test_ftp_scheme_is_blocked`, `test_file_scheme_is_blocked` | ✅ |
| SSRF / URL security | Non-standard port blocked, 80/443 allowed | `UrlSecurityValidatorTest::test_non_standard_port_is_blocked`, `test_standard_https_port_is_allowed` | ✅ |
| SSRF / URL security | Embedded credentials blocked | `UrlSecurityValidatorTest::test_embedded_credentials_are_blocked` | ✅ |
| SSRF / URL security | Oversized query string blocked | `UrlSecurityValidatorTest::test_oversized_query_string_is_blocked` | ✅ |
| SSRF / URL security | Malformed URL blocked | `UrlSecurityValidatorTest::test_malformed_url_is_blocked` | ✅ |
| SSRF / URL security | Real public hostname resolves via DNS and is allowed (sanity check against the actual DNS-resolution code path) | `UrlSecurityValidatorTest::test_real_public_hostname_resolves_and_is_allowed` | ✅ |
| SSRF / URL security (Node) | `isPrivateAddress` — every IPv4 blocked range (0.0.0.0/8, 10/8, 100.64/10, 127/8, 169.254/16 incl. cloud metadata, 172.16/12, TEST-NET 1-3, 198.18/15, multicast, broadcast) + boundary cases just outside each range | `crawler/lib/ssrf.test.mjs` — `isPrivateAddress (IPv4)` suite, 23 cases | ✅ |
| SSRF / URL security (Node) | `isPrivateAddress` — IPv6 loopback/unspecified/unique-local/link-local + IPv4-mapped addresses (both blocked and allowed cases) | `crawler/lib/ssrf.test.mjs` — `isPrivateAddress (IPv6)` suite, 9 cases | ✅ |
| SSRF / URL security (Node) | Malformed address treated as private (fail closed) | `crawler/lib/ssrf.test.mjs::treats a malformed address as private` | ✅ |
| SSRF / URL security (Node) | `assertHostnameIsPublic` — localhost, empty hostname, IP-literal loopback/private/cloud-metadata/IPv6-loopback all rejected without a real DNS query; public IP literal allowed; real hostname resolves via actual DNS and is allowed | `crawler/lib/ssrf.test.mjs` — `assertHostnameIsPublic` suite, 8 cases | ✅ |
| Usage calculation | Anonymous with no prior usage can create an audit | `UsageLimitServiceTest::test_anonymous_with_no_prior_usage_can_create_an_audit` | ✅ |
| Usage calculation | Anonymous with a prior audit from the same IP is blocked (lifetime limit) | `UsageLimitServiceTest::test_anonymous_with_a_prior_audit_from_the_same_ip_cannot_create_another` | ✅ |
| Usage calculation | Anonymous limit is scoped per IP | `UsageLimitServiceTest::test_anonymous_limit_is_scoped_per_ip` | ✅ |
| Usage calculation | Registered user under/at their plan's daily limit | `UsageLimitServiceTest::test_registered_user_under_their_daily_limit_can_create_an_audit`, `test_registered_user_at_their_daily_limit_cannot_create_another` | ✅ |
| Usage calculation | Registered user's daily limit resets on a new day | `UsageLimitServiceTest::test_registered_users_limit_resets_on_a_new_day` | ✅ |
| Usage calculation | Registered user with no subscription cannot create an audit | `UsageLimitServiceTest::test_registered_user_with_no_subscription_cannot_create_an_audit` | ✅ |
| Usage calculation | One user's usage doesn't affect a different user's limit | `UsageLimitServiceTest::test_a_users_own_usage_does_not_affect_a_different_users_limit` | ✅ |
| Usage calculation | max_pages: anonymous uses config, registered uses their plan, no-subscription falls back to anonymous config | `UsageLimitServiceTest::test_max_pages_for_anonymous_uses_config` + 2 siblings | ✅ |
| Usage calculation | Recording usage persists a `usage_logs` row with the right references | `UsageLimitServiceTest::test_record_audit_created_persists_a_usage_log` | ✅ |
| Report visibility | Anonymous 60% visibility split | — | ⬜ gap: not built yet (see CONTEXT.md §20) |
| Recommendation logic | Report-level recommendation list: only issues with a `recommendation` included, worst severity first | `ReportBuilderTest::test_recommendations_only_include_issues_with_a_recommendation_worst_first` | ✅ |
| Severity calculation | Covered indirectly via `ScoreCalculator` and each module's issue tests above | — | ✅ (via above) |
| Report generation | Overview includes website info, overall score, category scores (overall excluded from the category map) | `ReportBuilderTest::test_overview_includes_website_info_and_scores`, `test_overall_score_is_excluded_from_category_scores` | ✅ |
| Report generation | Issues serialized with derived priority (critical/high→high, medium→medium, low→low), page URL attached when the issue has one | `ReportBuilderTest::test_issues_are_serialized_with_derived_priority` | ✅ |
| Report generation | AI analysis section only includes categories with a successful (non-error) response | `ReportBuilderTest::test_ai_analysis_only_includes_successful_responses` | ✅ |
| Report generation | Per-page findings include per-page issue counts | `ReportBuilderTest::test_page_findings_include_per_page_issue_counts` | ✅ |
| Report visibility | Full access returns everything unmodified + `is_full_access: true` | `ReportVisibilityFilterTest::test_full_access_returns_everything_unmodified_plus_the_access_flag` | ✅ |
| Report visibility | Anonymous view only includes major-severity (critical/high) issues | `ReportVisibilityFilterTest::test_anonymous_view_only_includes_major_severity_issues` | ✅ |
| Report visibility | Anonymous view hides full-only sections (`ai_analysis`, `pages`) | `ReportVisibilityFilterTest::test_anonymous_view_hides_full_only_sections` | ✅ |
| Report visibility | Anonymous view strips the `priority` field from issues/recommendations | `ReportVisibilityFilterTest::test_anonymous_view_strips_priority_from_issues_and_recommendations` | ✅ |
| Report visibility | Anonymous view respects configured issue/recommendation limits | `ReportVisibilityFilterTest::test_anonymous_view_respects_configured_limits` | ✅ |
| Report visibility | Hidden counts reflect ALL hidden issues (not just excess major-severity ones) | `ReportVisibilityFilterTest::test_anonymous_view_reports_hidden_counts` | ✅ |
| Report visibility | Anonymous view always shows the overview (scores) | `ReportVisibilityFilterTest::test_anonymous_view_always_shows_the_overview` | ✅ |
| Report access | Anonymous audit grants full access to no one — not even a logged-in viewer, not even its own anonymous creator | `ReportAccessResolverTest::test_anonymous_audit_grants_no_one_full_access_even_a_logged_in_viewer` | ✅ |
| Report access | Owner viewing their own audit has full access | `ReportAccessResolverTest::test_owner_viewing_their_own_audit_has_full_access` | ✅ |
| Report access | A different registered user does NOT get full access to someone else's audit (closed an authorization gap — see CONTEXT.md §9/§28) | `ReportAccessResolverTest::test_a_different_registered_user_does_not_get_full_access_to_someone_elses_audit` | ✅ |
| Report access | Anonymous viewer of a registered user's audit has no full access | `ReportAccessResolverTest::test_anonymous_viewer_of_a_registered_users_audit_has_no_full_access` | ✅ |
| Admin dashboard | Users/admins counted separately | `AdminDashboardServiceTest::test_counts_users_and_admins_separately` | ✅ |
| Admin dashboard | Audits grouped by status | `AdminDashboardServiceTest::test_audits_grouped_by_status` | ✅ |
| Admin dashboard | Leads grouped by status | `AdminDashboardServiceTest::test_leads_grouped_by_status` | ✅ |
| Admin dashboard | AI usage separates successful from failed calls, sums tokens | `AdminDashboardServiceTest::test_ai_usage_separates_successful_from_failed_calls_and_sums_tokens` | ✅ |
| Admin dashboard | Empty database returns zeros, not errors/exceptions | `AdminDashboardServiceTest::test_empty_database_returns_zeros_not_errors` | ✅ |
| Admin dashboard | Audit schedules counted total vs. active separately | `AdminDashboardServiceTest::test_audit_schedules_counts_total_and_active_separately` | ✅ |
| Admin dashboard | Today's AI request/token/cost totals and PageSpeed request count, summarized separately from yesterday's | `AdminDashboardServiceTest::test_provider_usage_today_summarizes_ai_and_pagespeed_separately` | ✅ |
| Scheduling | `ScheduleFrequency::nextRunFrom` advances by exactly one day/week/month and does not mutate the given instant | `ScheduleFrequencyTest` (4 tests) | ✅ |
| Scheduling | A user with no plan, or a plan without the `scheduled_audits` feature, cannot create a schedule | `AuditSchedulerServiceTest::test_a_user_with_no_plan_cannot_create_a_schedule`, `test_a_plan_without_the_scheduled_audits_feature_cannot_create_a_schedule` | ✅ |
| Scheduling | A plan with the feature can create schedules up to its `full_recrawls` limit; inactive schedules don't count against it | `AuditSchedulerServiceTest::test_a_plan_with_the_feature_can_create_a_schedule_up_to_its_full_recrawls_limit`, `test_inactive_schedules_do_not_count_against_the_limit` | ✅ |
| Scheduling | Creating a schedule sets `next_run_at` per the chosen frequency | `AuditSchedulerServiceTest::test_create_sets_next_run_at_based_on_frequency` | ✅ |
| Scheduling | `runDue()` dispatches a crawl and advances the schedule for due, active schedules only — ignores not-yet-due and inactive ones | `AuditSchedulerServiceTest::test_run_due_dispatches_a_crawl_and_advances_the_schedule`, `test_run_due_ignores_schedules_not_yet_due`, `test_run_due_ignores_inactive_schedules` | ✅ |
| PDF export | Renders a valid PDF binary (`%PDF-` signature) for a report with issues/recommendations/AI analysis, and for one with none of those | `ReportPdfServiceTest` (2 tests) | ✅ |
| AI providers | Claude/OpenAI/Gemini throw when no `ApiProvider` row exists, when disabled, and on an error status; success parses response+usage; a failure records `last_failure_at`/`last_error` on the provider row | `ProvidersTest` (14 tests, extended in the provider-config work) | ✅ |
| AI providers | `testConnection()` works even while the provider is disabled (only requires configured), and fails safely with a message when no key exists | `ProvidersTest::test_claude_test_connection_succeeds_even_when_disabled`, `test_claude_test_connection_fails_safely_without_a_key` | ✅ |
| AI analysis | A successful/failed call writes an `api_usage_logs` row with the right provider/service/tokens/audit; analysis is skipped once the provider's configured daily request limit is reached | `AiAnalysisServiceTest::test_successful_call_writes_an_api_usage_log`, `test_failed_call_writes_a_failed_api_usage_log`, `test_analysis_is_skipped_once_the_daily_limit_is_reached` | ✅ |
| Provider cost estimation | Returns a computed cost when both per-1k-token rates are configured; returns `null` (never a guessed number) when neither is set | `AdminProviderApiTest::test_estimated_cost_is_calculated_where_supported` | ✅ |

## Feature tests

| Flow | Test | Status |
|---|---|---|
| Signup | `AuthTest::test_registration_creates_a_user_and_returns_a_token`, `test_registration_subscribes_the_user_to_the_default_plan`, `test_registration_rejects_a_duplicate_email`, `test_registration_rejects_mismatched_password_confirmation` | ✅ |
| Login | `AuthTest::test_login_with_correct_credentials_returns_a_token`, `test_login_with_wrong_password_is_rejected`, `test_login_with_unknown_email_is_rejected` | ✅ |
| Logout | `AuthTest::test_logout_revokes_the_current_token` (see CONTEXT.md §28 for the Sanctum-guard testing gotcha this test works around) | ✅ |
| Authenticated profile fetch / rejection when unauthenticated | `AuthTest::test_authenticated_user_can_fetch_their_own_profile`, `test_unauthenticated_request_to_user_endpoint_is_rejected` | ✅ |
| Website creation | Implicit in every `AuditApiTest` audit-creation case (a `Website` row is created alongside the `Audit`) — no dedicated test since there's no standalone website-creation endpoint | ✅ (via `AuditApiTest`) |
| Audit creation (anonymous) | `AuditApiTest::test_anonymous_visitor_can_create_an_audit`, `test_created_audit_has_no_user_id_for_an_anonymous_request`, `test_anonymous_audit_is_capped_at_the_configured_anonymous_page_limit` | ✅ |
| Audit creation (registered) | `AuditApiTest::test_registered_user_can_create_an_audit_with_their_plans_page_limit` — asserts the plan's page limit, not the anonymous one, and that `user_id` is set | ✅ |
| Audit creation validation/security | `AuditApiTest::test_unsafe_url_is_rejected_before_creating_any_records` (SSRF, 422, zero records created), `test_missing_url_is_rejected_with_a_validation_error` (422) | ✅ |
| Audit creation respects usage limits | `AuditApiTest::test_second_audit_from_the_same_anonymous_ip_is_rejected`, `test_registered_user_hitting_their_daily_limit_is_rejected`, `test_a_registered_users_limit_is_independent_of_an_exhausted_anonymous_ip_limit` (429s, correctly isolated per actor) | ✅ |
| Audit status polling | `AuditApiTest::test_show_returns_audit_status`, `test_show_for_an_unknown_audit_returns_404` | ✅ |
| Report fetch, correctly scoped per viewer | `AuditApiTest::test_report_endpoint_returns_the_anonymous_view_for_an_anonymous_viewer`, `test_report_endpoint_returns_full_access_for_the_audits_owner`, `test_report_endpoint_does_not_grant_full_access_to_a_different_registered_user` | ✅ |
| API rate limiting | `AuditApiTest::test_requests_beyond_the_rate_limit_are_throttled` — fires 11 requests against `throttle:10,1`, asserts the 11th gets a real 429 | ✅ |
| Crawler dispatch → audit completion (full pipeline: crawl → 7 modules → overall score) | `CrawlAndAnalyzePipelineTest::test_full_pipeline_produces_a_completed_audit_with_all_category_scores` — real `CrawlOrchestrator`, real SSRF validation, real platform detection, all 7 real audit modules, real scoring math; only `PlaywrightCrawlerClient` (no real browser) and outbound HTTP (no real robots.txt/sitemap fetch) are faked. Dispatches through the actual queue pipeline (`sync` driver in tests runs it inline). Asserts all 8 score rows (7 categories + overall) equal exactly 100 for a page crafted to be flawless against every deterministic rule. | ✅ |
| Audit failure handling | `CrawlAndAnalyzePipelineTest::test_pipeline_marks_audit_failed_when_the_homepage_crawl_fails` — homepage crawl throws, asserts `Audit.status = failed`, `error_message` set, zero pages/scores/**reports** created | ✅ |
| Report generation | Covered by `CrawlAndAnalyzePipelineTest::test_full_pipeline_produces_a_completed_audit_with_all_category_scores`, which now also asserts `GenerateReportJob` created a `Report` row with correct content | ✅ |
| Usage limits | See `AuditApiTest`'s usage-limit rows above (API level) and `UsageLimitServiceTest` (Unit level) | ✅ |
| Recrawl (via a recurring schedule, not an on-demand endpoint) | `AuditSchedulerServiceTest::test_run_due_dispatches_a_crawl_and_advances_the_schedule` (Unit level — creates a new `Audit` against the existing `Website` and dispatches `CrawlWebsiteJob`) | ✅ |
| Lead creation (public) | `AdminApiTest::test_anyone_can_submit_a_lead`, `test_lead_submission_requires_a_valid_source` | ✅ |
| Lead management (admin) | `AdminApiTest::test_admin_can_list_and_filter_leads_by_status`, `test_admin_can_update_lead_status_and_it_logs_an_activity`, `test_admin_can_add_a_note_to_a_lead`, `test_lead_submission_is_rejected_without_admin_auth_to_list` | ✅ |
| CMS content (public read) | `AdminApiTest::test_public_can_read_a_cms_page`, `test_public_read_of_unknown_cms_page_returns_404` | ✅ |
| CMS content (admin management) | `AdminApiTest::test_admin_can_create_a_cms_page`, `test_admin_can_upsert_a_section_creating_it_if_new`, `test_admin_can_upsert_a_section_updating_it_if_it_exists` (asserts update, not duplicate, on the second call), `test_admin_can_delete_a_section`, `test_cms_page_slug_must_be_unique` | ✅ |
| Admin operations — authorization | `AdminApiTest::test_unauthenticated_request_to_admin_route_is_rejected` (401), `test_non_admin_authenticated_user_is_rejected_with_403` (403 — a real Sanctum-authenticated non-admin, not just no auth), `test_admin_user_can_access_admin_routes` | ✅ |
| Admin operations — dashboard/resource listings | `AdminApiTest::test_dashboard_returns_summary_data`, `test_users_listing_is_paginated_and_includes_subscription`, `test_websites_listing_includes_audit_count`, `test_audit_show_includes_scores_and_issues`, `test_crawl_jobs_listing`, `test_usage_listing`, `test_ai_usage_endpoint_returns_aggregates` | ✅ |
| Admin operations — failed jobs | `AdminApiTest::test_failed_jobs_listing_and_deletion`, `test_deleting_an_unknown_failed_job_returns_404` | ✅ |
| Admin operations — plan CRUD | `AdminApiTest::test_admin_can_create_a_plan`, `test_setting_a_new_default_plan_unsets_the_previous_default` (only one plan can ever be default), `test_admin_can_update_a_plan`, `test_admin_cannot_delete_a_plan_with_an_active_subscription` (409, not a raw 500), `test_plan_slug_must_be_unique` | ✅ |
| Admin operations — audit schedules listing | `AdminApiTest::test_audit_schedules_listing` | ✅ |
| PDF export | `AuditApiTest::test_pdf_export_returns_a_pdf_for_the_owner_on_a_plan_with_the_feature` (200, `Content-Type: application/pdf`, `%PDF-` body), `test_pdf_export_is_rejected_when_the_owners_plan_lacks_the_feature` (403), `test_pdf_export_is_rejected_for_a_non_owner` (403), `test_pdf_export_is_rejected_for_an_anonymous_viewer` (403), `test_pdf_export_returns_404_when_the_report_is_not_ready` | ✅ |
| Scheduled audits (create/list/update/delete, all ownership- and plan-gated) | `AuditScheduleApiTest` (12 tests: unauthenticated 401, owns-website 201, someone-else's-website 422, missing feature 403, over the `full_recrawls` limit 403, duplicate-website 422, invalid frequency 422, list-scoped-to-self, pause via update, update/delete blocked with 404 — not 403 — for a non-owner, delete) | ✅ |
| AI/API provider configuration (view/configure Claude/OpenAI/Gemini/PageSpeed, enable/disable, test connection) | `AdminProviderApiTest` (21 tests — see the "Provider configuration" category below for the full breakdown) | ✅ |
| Cross-provider usage/cost analytics (summary, daily, by-provider, filterable logs) | `AdminProviderApiTest`'s TC-USAGE-tagged tests (8 tests) | ✅ |
| Self-service "my websites"/"my audits" (scoped to the caller, distinct from the admin-wide equivalents) | `AuditApiTest::test_my_audits_lists_only_the_authenticated_users_own_audits`, `test_my_audits_requires_authentication`, `test_my_websites_lists_only_the_authenticated_users_own_websites`, `test_my_websites_requires_authentication` (frontend dashboard work) | ✅ |
| Public plan listing (pricing page data source) | `PlanApiTest::test_anyone_can_list_plans`, `test_plans_are_ordered_by_price` (frontend work) | ✅ |
| Register/login responses include the new subscription+plan (frontend dashboard needs plan limits immediately after auth, not just after a later refetch) | `AuthTest::test_registration_response_includes_the_new_subscription_and_plan`, `test_login_response_includes_the_current_subscription_and_plan` (frontend work) | ✅ |

**The default `Tests\Feature\ExampleTest`** (Laravel scaffold, asserts `/`
returns 200) still exists and passes — kept as-is; no real reason to
replace it, the `web.php` root route it tests isn't part of this app's
actual functionality either way.

## API tests

| Category | Test | Status |
|---|---|---|
| Authentication (register/login/logout/me) | `AuthTest` (10 tests, see Feature tests table above) | ✅ |
| Authorization (report access scoped to the real viewer; admin gate; schedule/PDF ownership + plan-feature gates) | `AuditApiTest`'s report-access and PDF-export tests, `AdminApiTest`'s authorization tests, `AuditScheduleApiTest`'s ownership/plan-gate tests (see Feature tests table above) | ✅ |
| Validation (missing/invalid input → 422) | `AuthTest::test_registration_rejects_mismatched_password_confirmation`, `AuditApiTest::test_missing_url_is_rejected_with_a_validation_error`, `AdminApiTest::test_plan_slug_must_be_unique`, `test_cms_page_slug_must_be_unique`, `test_lead_submission_requires_a_valid_source`, `AuditScheduleApiTest::test_invalid_frequency_is_rejected`, `test_a_website_cannot_be_scheduled_twice` | ✅ |
| Status codes (200/201/202/401/403/404/409/422/429) | Exercised across `AuthTest`/`AuditApiTest`/`AdminApiTest`/`AuditScheduleApiTest` — every status code the API actually returns has at least one test asserting it, including 403 (non-admin; PDF/schedule plan-feature gates), 404-not-403 for another user's schedule (a deliberate choice not to confirm it exists), and 409 (plan deletion conflict) | ✅ |
| Response schema | `AuthTest::test_registration_creates_a_user_and_returns_a_token` asserts `assertJsonStructure(['data' => ['user', 'token']])`; `AdminApiTest::test_dashboard_returns_summary_data` asserts the full dashboard shape; the PDF export test asserts `Content-Type: application/pdf` and a `%PDF-` body instead of the usual JSON envelope; other tests assert specific `assertJsonPath()` values | ✅ |
| Error handling (SSRF rejection, usage-limit rejection, plan-deletion conflict, schedule limit/feature gates) | `AuditApiTest::test_unsafe_url_is_rejected_before_creating_any_records`, usage-limit tests (see Feature tests table), `AdminApiTest::test_admin_cannot_delete_a_plan_with_an_active_subscription`, `AuditScheduleApiTest::test_a_plan_without_the_scheduled_audits_feature_is_rejected`, `test_a_second_website_beyond_the_plans_full_recrawls_limit_is_rejected` | ✅ |
| Rate limits | `AuditApiTest::test_requests_beyond_the_rate_limit_are_throttled`, `AuthTest::test_login_attempts_beyond_the_rate_limit_are_throttled`, `test_registration_attempts_beyond_the_rate_limit_are_throttled`, `AdminApiTest::test_lead_submissions_beyond_the_rate_limit_are_throttled` (Phase 9 — `/register`/`/login`/`/leads` were previously unthrottled, see CONTEXT.md §26) | ✅ |
| Security — cross-user data leakage | `AuditApiTest::test_report_endpoint_does_not_grant_full_access_to_a_different_registered_user`, `test_show_hides_a_registered_audit_from_a_different_registered_user`, `test_show_hides_a_registered_audit_from_an_anonymous_viewer` (Phase 9 — closed the audit-ID-enumeration gap for registered audits, CONTEXT.md §9) | ✅ |
| Pagination | `AdminApiTest::test_users_listing_is_paginated_and_includes_subscription` and every other admin listing test asserts the paginator shape (`current_page`/`total`/`data`) | ✅ |
| Duplicate/malicious input beyond SSRF | `AuditApiTest::test_oversized_url_is_rejected_with_a_validation_error`, `test_non_string_url_is_rejected_with_a_validation_error`, `AuthTest::test_registration_rejects_an_oversized_name`, `test_registration_safely_stores_an_injection_style_name_without_executing_it`, `AdminApiTest::test_lead_submission_rejects_an_oversized_message`, `test_lead_submission_safely_stores_injection_and_script_style_strings_without_executing_them` (Phase 9 — the injection/XSS-style tests assert the string is stored verbatim and the table survives intact, proving parameter binding rather than just "no error was thrown") | ✅ |
| Password policy | `AuthTest::test_registration_rejects_a_password_with_no_numbers`, `test_registration_rejects_a_password_under_eight_characters` (Phase 9 — tightened from Laravel's bare 8-characters-only default, CONTEXT.md §26) | ✅ |
| Security headers | `AuthTest::test_successful_api_responses_include_baseline_security_headers`, `test_exception_rendered_api_responses_also_include_security_headers` (the second one specifically proves the `RequestHandled`-listener approach, not a middleware, since middleware after-code is skipped on the exception path — CONTEXT.md §26/§28) | ✅ |
| Backward compatibility | — | ⬜ n/a — no prior API version exists to be compatible with |

## Provider configuration and usage tracking tests

All in `tests/Feature/Api/AdminProviderApiTest.php` unless noted. TC-IDs
match the ones this feature was scoped against.

| TC-ID | Case | Test | Status |
|---|---|---|---|
| TC-ADMIN-PROVIDER-001 | Admin can view provider configuration | `test_admin_can_view_provider_configuration` | ✅ |
| TC-ADMIN-PROVIDER-002 | Admin can configure Claude | `test_admin_can_configure_claude` | ✅ |
| TC-ADMIN-PROVIDER-003 | Admin can configure OpenAI | `test_admin_can_configure_openai` | ✅ |
| TC-ADMIN-PROVIDER-004 | Admin can configure Gemini | `test_admin_can_configure_gemini` | ✅ |
| TC-ADMIN-PROVIDER-005 | Admin can configure Google PageSpeed | `test_admin_can_configure_google_pagespeed` | ✅ |
| TC-ADMIN-PROVIDER-006 | Admin can enable/disable a provider | `test_admin_can_enable_and_disable_a_provider`, `test_enabling_a_provider_without_a_key_is_rejected` | ✅ |
| TC-ADMIN-PROVIDER-007 | Provider API key is never returned in full | `test_provider_api_key_is_never_returned_in_full` — asserts the raw key string is absent from the full response body, not just that a specific JSON key is missing | ✅ |
| TC-ADMIN-PROVIDER-008 | Non-admin cannot access provider configuration | `test_non_admin_cannot_access_provider_configuration`, `test_unauthenticated_request_is_rejected` | ✅ |
| TC-ADMIN-PROVIDER-009 | Test connection succeeds with valid credentials | `test_connection_succeeds_with_valid_credentials` | ✅ |
| TC-ADMIN-PROVIDER-010 | Test connection fails safely with invalid credentials | `test_connection_fails_safely_with_invalid_credentials` — asserts the response never contains the raw key | ✅ |
| TC-USAGE-001 | Every provider request creates a usage record | `test_every_provider_request_creates_a_usage_record` | ✅ |
| TC-USAGE-002 | Daily request aggregation is correct | `test_daily_request_aggregation_is_correct` | ✅ |
| TC-USAGE-003 | Provider usage filtering works | `test_usage_provider_filtering_works` | ✅ |
| TC-USAGE-004 | Date filtering works | `test_usage_date_filtering_works` | ✅ |
| TC-USAGE-005 | Failed requests are counted | `test_failed_requests_are_counted` | ✅ |
| TC-USAGE-006 | Token usage is recorded where available | `test_token_usage_is_recorded_where_available` | ✅ |
| TC-USAGE-007 | Estimated cost is calculated where supported | `test_estimated_cost_is_calculated_where_supported` (Unit-level, on `ApiProvider::estimateCost()`) | ✅ |
| TC-USAGE-008 | Usage dashboard loads real backend data | `test_usage_dashboard_loads_real_backend_data` | ✅ |
| TC-QUALITY-005 | No new critical backend errors | Full suite run clean after this change (340 tests, `pint --test` clean, `php -l` on every source file) — see CONTEXT.md §23 | ✅ |
| TC-QUALITY-006 | No database integrity problems | `DatabaseMigrationTest` includes `api_providers`/`api_usage_logs`; `CascadeDeleteTest` covers both tables' nullOnDelete relationships | ✅ |
| — | Regression: an empty-string `api_key` (e.g. from a set-but-blank `.env` value) must not read as "configured" — encrypting `''` still yields a non-null ciphertext | `test_an_empty_string_api_key_does_not_count_as_configured` — found via manual browser verification, not written speculatively (see CONTEXT.md §28) | ✅ |
| — | System health: real DB/cache round-trip status, admin-gated | `AdminApiTest::test_system_health_reports_real_database_and_cache_status`, `test_system_health_requires_admin` | ✅ |

**TC-FRONTEND-001 through 011** (homepage, URL audit form, progress
screen, preview report, signup flow, full report, user dashboard, admin
dashboard, provider configuration UI, usage charts, usage filters):
✅ verified manually against the real backend in a browser this
session — see the "Frontend manual verification" entry further down.
Not automated (§29/§32 — no Vitest/RTL exists), so these are proven
once, not regression-tested going forward.

**TC-FRONTEND-012** (no API secrets present in frontend network
responses): ✅ verified directly — the Test Connection failure response
body and the provider-config GET response were both inspected for the
literal key string during manual verification; neither contained it.

**TC-QUALITY-001 through 004** (no incomplete production functions, no
unintended TODO/FIXME, no dead code, no unexpected frontend runtime
errors): ✅ — `grep` for `TODO|FIXME|HACK|XXX|console\.log` across
`frontend/src` returned nothing; every page component and API function
created is imported/used somewhere (cross-checked file-by-file); the
only console errors observed during manual verification were the
expected ones (a graceful 404 from the CMS-content fallback fetch when
no CMS page exists yet, and the throttle 429 that led directly to the
polling-interval bug fix in CONTEXT.md §28) — no unexplained runtime
errors.

## Pricing plan feature & AI model access tests

TC-IDs match the ones this feature was scoped against (see CONTEXT.md
§33 for the full architecture).

| TC-ID | Case | Test | Status |
|---|---|---|---|
| TC-PLAN-001 | Admin can create a pricing plan | `AdminApiTest::test_admin_can_create_a_plan` (pre-existing, unchanged) | ✅ |
| TC-PLAN-002 | Admin can edit pricing plan | `AdminApiTest::test_admin_can_update_a_plan` (pre-existing, unchanged) | ✅ |
| TC-PLAN-003 | Admin can enable/disable Performance | `AdminApiTest::test_admin_can_enable_and_disable_individual_audit_features_for_a_plan` (covers all 7 keys via one bulk-sync request, per the endpoint's actual contract) | ✅ |
| TC-PLAN-004 | Admin can enable/disable Accessibility / ADA | same test as TC-PLAN-003 | ✅ |
| TC-PLAN-005 | Admin can enable/disable CRO | same test — also specifically re-toggles `cro` alone in a second request to prove independence, not just initial-assignment | ✅ |
| TC-PLAN-006 | Admin can enable/disable SEO | same test as TC-PLAN-003 | ✅ |
| TC-PLAN-007 | Admin can enable/disable AIO | same test as TC-PLAN-003 | ✅ |
| TC-PLAN-008 | Admin can enable/disable Ecommerce/Landing Auditor | same test as TC-PLAN-003; `FeatureAccessResolverTest::test_ecommerce_feature_key_gates_both_ecommerce_and_landing_page_categories` proves the one `ecommerce` key gates both `AuditCategory::Ecommerce` and `::LandingPage` | ✅ |
| TC-PLAN-009 | Admin can enable/disable Product Auditor | same test as TC-PLAN-003 (registry-level only — no backing module exists yet, see CONTEXT.md §29) | ✅ |
| TC-AI-001 | Admin can assign Claude model to plan | `AdminApiTest::test_admin_can_assign_and_remove_ai_models_from_a_plan` | ✅ |
| TC-AI-002 | Admin can remove Claude model from plan | same test — a second `PUT` with a smaller model-ID set proves removal | ✅ |
| TC-AI-003 | Admin can assign OpenAI model to plan | same test (asserts both Claude and OpenAI model IDs together) | ✅ |
| TC-AI-004 | Admin can remove OpenAI model from plan | same test | ✅ |
| TC-AI-005 | Admin can assign Gemini model to plan | `PlanAiModelResolverTest::test_the_default_provider_is_preferred_when_multiple_are_allowed` and `AdminAiModelApiTest` exercise the same assignment mechanism against Gemini-equivalent fixtures (the assignment endpoint is provider-agnostic — it takes `ai_model_id`s, not provider-specific inputs) | ✅ |
| TC-AI-006 | Admin can remove Gemini model from plan | same reasoning as TC-AI-005 | ✅ |
| TC-AI-007 | Globally disabled model cannot be used | `PlanAiModelResolverTest::test_a_globally_disabled_model_is_never_used_even_if_the_plan_allows_it`, `AdminAiModelApiTest::test_a_globally_disabled_model_is_not_usable_even_if_a_plan_allows_it` | ✅ |
| TC-AI-008 | User cannot bypass model restriction through API | `MeApiTest::test_ai_models_not_assigned_to_the_plan_are_not_exposed`; `PlanAiModelResolverTest::test_a_model_enabled_globally_but_not_assigned_to_the_plan_is_not_used` proves the *server-side analysis path* (not just the read-only listing endpoint) also refuses an unassigned model — a user can't get `AiAnalysisService` to use it by any route, since there's no request parameter that names which model to call in the first place | ✅ |
| TC-FEATURE-001 | User cannot run unavailable audit feature | `AuditModuleRegistryFeatureGatingTest::test_a_plan_that_disables_seo_and_accessibility_skips_only_those_modules` — drives the real `CrawlWebsiteJob` → `AuditModuleRegistry::dispatchAnalysis()` pipeline (not a unit-level stand-in) and asserts no `AuditScore` row exists for a disabled category | ✅ |
| TC-FEATURE-002 | User can run an audit included in their plan | same test — asserts the allowed categories *do* get scored, plus `overall`, and the audit reaches `Completed` | ✅ |
| TC-FEATURE-003 | Upgrade message appears for locked feature | `MeApiTest::test_a_locked_feature_lists_which_plans_include_it` | ✅ |
| TC-FEATURE-004 | Pricing page displays dynamic features | `PlanApiTest` (pre-existing) plus manual verification — see "Frontend manual verification" below; `PlanController::index()`'s eager-loaded `auditFeatures` is what the Pricing page's comparison table renders, so a plan change reflects immediately, no hardcoded list | ✅ |
| TC-SECURITY-001 | Non-admin cannot modify plan features | `AdminApiTest::test_non_admin_cannot_modify_plan_features_or_global_feature_status` | ✅ |
| TC-SECURITY-002 | Non-admin cannot assign AI models to plans | `AdminApiTest::test_non_admin_cannot_assign_ai_models_to_a_plan` | ✅ |
| TC-DATABASE-001 | Duplicate plan-feature assignments are prevented | `plan_features` has a DB-level unique `(plan_id, feature_id)` constraint (migration); the admin endpoint uses `sync()`, which can't produce a duplicate row by construction — no separate app-level duplicate-rejection test needed for this pair since the write path physically cannot create one | ✅ |
| TC-DATABASE-002 | Duplicate plan-model assignments are prevented | `AdminApiTest::test_duplicate_plan_ai_model_assignment_via_repeated_ids_is_rejected` — asserts 422 from the request validation's `distinct` rule (a defense-in-depth check in front of the same `plan_ai_models` unique constraint) | ✅ |
| TC-DATABASE-003 | Database relationships remain valid | `CascadeDeleteTest`'s 4 new cases (plan↔feature, feature↔plan, plan↔ai_model, provider↔ai_model) confirm real FK cascade behavior, not just that the Eloquent relationship methods exist | ✅ |

**Additional coverage beyond the required list**: `FeatureAccessResolverTest`
(7 unit tests — anonymous/registered plan resolution, the inactive-
default-plan edge case, the "no plan exists at all → unrestricted"
fallback that keeps every pre-existing test passing, §31) and
`PlanAiModelResolverTest` (6 unit tests — the same fallback, plus the
"allowed by plan but not the provider's live active model" case that
`AiProviderFactory`'s architecture makes a real constraint, not a
hypothetical one, §29).

## Database tests

| Check | Status |
|---|---|
| Fresh migrations run cleanly | `DatabaseMigrationTest::test_migrate_fresh_runs_cleanly` — asserts exit code 0 and every application table exists afterward | ✅ |
| Every table is InnoDB with enforced FKs | ✅ verified manually via `information_schema` query in Phase 2 after finding the MyISAM default-engine issue (MySQL-specific — the automated test suite runs against SQLite, so this check isn't portable to it); not automated |
| Migration rollback (`down()`) — single-step and full reset | `DatabaseMigrationTest::test_rolling_back_the_last_migration_reverses_it_and_remigrating_restores_the_schema`, `test_rolling_back_every_migration_drops_every_application_table` — actually runs `migrate:rollback` / `migrate:reset` and asserts the schema (tables *and* columns — several migrations only alter a column rather than create a table, e.g. `add_is_active_to_plans_table`) changed, then re-migrates and asserts it's back exactly | ✅ |
| Unique constraint: `audit_scores` rejects a duplicate (audit, category) insert | `UniqueConstraintsTest::test_audit_scores_rejects_a_duplicate_category_for_the_same_audit` — asserts a real `QueryException`, not just that application code happens to call `updateOrCreate()` | ✅ |
| Unique constraint: `ai_analyses` rejects a duplicate cache-key insert | `UniqueConstraintsTest::test_ai_analyses_rejects_a_duplicate_cache_key` | ✅ |
| Unique constraint: `audit_schedules` per (user_id, website_id) | `AuditScheduleApiTest::test_a_website_cannot_be_scheduled_twice` — asserted at the API level via the controller's friendlier pre-check (422), not a raw `QueryException` | ✅ |
| Cascade delete: website → audits | `CascadeDeleteTest::test_deleting_a_website_cascades_to_its_audits` | ✅ |
| Null-on-delete: user → website.user_id (website itself survives) | `CascadeDeleteTest::test_deleting_a_user_nulls_the_websites_user_id_instead_of_deleting_the_website` | ✅ |
| Cascade delete: audit → crawl_job, audit_pages, audit_issues, audit_scores, ai_analyses, reports | `CascadeDeleteTest::test_deleting_an_audit_cascades_to_its_crawl_job_pages_issues_and_scores` | ✅ |
| Cascade delete: crawl_job → crawl_urls | `CascadeDeleteTest::test_deleting_a_crawl_job_cascades_to_its_crawl_urls` | ✅ |
| Null-on-delete: crawl_url → audit_page.crawl_url_id (page itself survives) | `CascadeDeleteTest::test_deleting_a_crawl_url_nulls_the_audit_pages_crawl_url_id_instead_of_deleting_the_page` | ✅ |
| Cascade delete: audit_page → its issues (site-wide issues with no audit_page_id survive) | `CascadeDeleteTest::test_deleting_an_audit_page_cascades_to_its_issues` | ✅ |
| No orphaned rows: crawl_urls after deleting the owning audit (transitively, via crawl_job) | `CascadeDeleteTest::test_no_orphaned_crawl_urls_remain_after_deleting_the_owning_audit` | ✅ |
| Cascade delete: user → subscription | `CascadeDeleteTest::test_deleting_a_user_cascades_to_their_subscription` | ✅ |
| Restrict delete: a plan with an active subscription cannot be deleted | `CascadeDeleteTest::test_deleting_a_plan_with_an_active_subscription_is_prevented` — asserts the DB actually throws `QueryException`, not just that the app happens not to call delete | ✅ |
| Cascade delete: user → usage_logs | `CascadeDeleteTest::test_deleting_a_user_cascades_to_their_usage_logs` | ✅ |
| Null-on-delete: website/audit → usage_logs.website_id/audit_id (log itself survives) | `CascadeDeleteTest::test_deleting_a_website_or_audit_nulls_the_usage_logs_references_instead_of_deleting_the_log` | ✅ |
| Cascade delete: lead → lead_activities | `CascadeDeleteTest::test_deleting_a_lead_cascades_to_its_activities` | ✅ |
| Null-on-delete: audit → lead.audit_id (lead itself survives) | `CascadeDeleteTest::test_deleting_an_audit_nulls_the_leads_audit_id_instead_of_deleting_the_lead` | ✅ |
| Null-on-delete: user → lead_activity.user_id (activity itself survives) | `CascadeDeleteTest::test_deleting_a_user_nulls_the_lead_activities_user_id_instead_of_deleting_the_activity` | ✅ |
| Cascade delete: cms_page → cms_sections | `CascadeDeleteTest::test_deleting_a_cms_page_cascades_to_its_sections` | ✅ |
| Cascade delete: user → audit_schedules | `CascadeDeleteTest::test_deleting_a_user_cascades_to_their_audit_schedules` | ✅ |
| Cascade delete: website → audit_schedules | `CascadeDeleteTest::test_deleting_a_website_cascades_to_its_audit_schedules` | ✅ |
| Null-on-delete: user → api_usage_log.user_id (log itself survives) | `CascadeDeleteTest::test_deleting_a_user_nulls_the_api_usage_logs_user_id_instead_of_deleting_the_log` | ✅ |
| Null-on-delete: audit → api_usage_log.audit_id (log itself survives) | `CascadeDeleteTest::test_deleting_an_audit_nulls_the_api_usage_logs_audit_id_instead_of_deleting_the_log` | ✅ |
| Cascade delete: plan → plan_features pivot (the `features` registry row itself survives) | `CascadeDeleteTest::test_deleting_a_plan_cascades_to_its_plan_features_pivot_rows` | ✅ |
| Cascade delete: feature → plan_features pivot | `CascadeDeleteTest::test_deleting_a_feature_cascades_to_its_plan_features_pivot_rows` | ✅ |
| Cascade delete: plan → plan_ai_models pivot (the `ai_models` catalog row itself survives) | `CascadeDeleteTest::test_deleting_a_plan_cascades_to_its_plan_ai_models_pivot_rows` | ✅ |
| Cascade delete: api_provider → ai_models | `CascadeDeleteTest::test_deleting_an_api_provider_cascades_to_its_ai_models` | ✅ |
| Cascade delete: audit → screenshots | `CascadeDeleteTest::test_deleting_an_audit_cascades_to_its_screenshots` | ✅ |
| Cascade delete: audit_issue → issue_pages pivot | `CascadeDeleteTest::test_deleting_an_audit_issue_cascades_to_its_issue_pages_pivot_rows` | ✅ |
| Cascade delete: audit_page → issue_pages pivot | `CascadeDeleteTest::test_deleting_a_page_cascades_to_its_issue_pages_pivot_rows` | ✅ |
| Issue↔page relational query — an issue is queryable by page *and* a page is queryable by issue (not just a JSON list) | `IssuePageRelationshipTest::test_a_multi_page_issue_is_queryable_by_page_and_a_page_is_queryable_by_issue` — drives a real `SeoAuditModule` run through `RunAuditModuleJob`, not a hand-inserted pivot row (CONTEXT.md §34) | ✅ |
| Screenshot storage paths are relative to the public disk, not absolute filesystem paths | `CrawlAndAnalyzePipelineTest::test_screenshot_storage_paths_are_stored_relative_to_the_public_disk_not_as_absolute_paths` — regression for a real bug found by inspecting a live audit's stored `Screenshot.storage_path` (Windows backslash vs. forward-slash mismatch in `CrawlOrchestrator::toPublicPath()`, CONTEXT.md §34) | ✅ |

All cascade tests above were confirmed to exercise real DB-level FK
enforcement, not vacuous passes — `config/database.php`'s sqlite
connection sets `foreign_key_constraints => true` by default, checked
before trusting these results.

## Frontend tests

No automated frontend test suite exists (no Vitest/RTL — see CONTEXT.md
§29/§32). The full React frontend (13 public pages, auth flow, audit
submission/progress/report, user dashboard, admin dashboard, provider
configuration UI, usage analytics, and — this chunk — the plan feature/
AI-model access management UI: the tabbed Plan editor, the AI Model
catalog manager embedded in each provider card, the Pricing page's
feature-comparison table, `PlanFeatureChecklist` on audit submission,
and the Subscription page's two live plan-access panels) was verified
manually against the real running backend in a browser — see
TC-FRONTEND-001 through 012 in the "Provider configuration and usage
tracking tests" section above, TC-FEATURE-003/004 and the additional
coverage note in "Pricing plan feature & AI model access tests" above,
and the "Frontend manual verification" entry below. This is proven-once
coverage, not regression-tested going forward — a future change to any
of these pages needs a fresh manual pass until an automated suite is
added.

## E2E tests

No automated E2E runner exists (no Playwright/Cypress). The real flows
(anonymous URL submit → crawl → progress polling → preview report →
signup → full report; registered-user website/audit history; admin
provider configuration → test connection → enable → usage analytics)
were driven end-to-end by hand through the actual UI against the real
backend during the frontend build — see "Frontend manual verification"
below. Automating these is a gap, not a defect, since a headless
browser tool doesn't yet run in CI here.

## Manual/exploratory verification (not automated — recorded for traceability)

These were actually run and their results checked by hand during
development; they remain useful historical records but are no longer the
*only* coverage for what they exercised — see the automated equivalents
added above.

- SSRF blocking against a real running crawler CLI (Phase 2) —
  superseded as automated regression coverage by
  `UrlSecurityValidatorTest` (PHP layer) and `crawler/lib/ssrf.test.mjs`
  (Node layer), but the original manual run against a live process is
  still useful evidence the two layers behave the same when wired
  together for real.
- Full crawl→7-module pipeline against `https://example.com` and
  `https://wordpress.org` — page limits respected, no duplicate pages,
  sitemap-index discovery worked (459 URLs found on wordpress.org),
  platform correctly detected as `wordpress`, weighted overall scores
  matched hand-computed expected values (Phases 2-4). Superseded as
  automated regression coverage by `CrawlAndAnalyzePipelineTest`, though
  the manual run is still the only evidence the *real* Playwright/axe-core
  subprocess and a real multi-page sitemap-discovery crawl behave
  correctly — the automated test fakes the crawler subprocess.
- `robots.txt`/`sitemap.xml` fetch failing with a 403 from Guzzle's
  default User-Agent, fixed by sending an explicit UA (Phase 2) — this
  was a one-off environment finding, not an ongoing behavior to
  regression-test.
- `ai_analyses` correctly recorded 3 failure rows (landing_page,
  conversion, aio) and correctly recorded **zero** rows for ecommerce on
  a non-commerce site (Phase 4) — automated equivalent is
  `EcommerceAuditModuleTest::test_non_ecommerce_site_scores_100_with_no_issues`.
- Full pipeline including report generation against `https://wordpress.org`
  (Phase 5): 10 issues / 8 recommendations found across 3 crawled pages;
  full view correctly showed all of them; anonymous view correctly
  showed 0 issues (none were critical/high severity that run — all
  medium/low) and 3 of 8 recommendations (capped at
  `report.anonymous_recommendation_limit`), with `hidden_issue_count: 10`
  and `hidden_recommendation_count: 5` — both counts checked against the
  actual full-view numbers, not just "some number came back." Automated
  equivalent is `CrawlAndAnalyzePipelineTest`'s report assertions plus
  the full `ReportVisibilityFilterTest` suite, though the manual run
  remains the only evidence real crawled/AI-analysis data (not
  hand-crafted fixtures) flows correctly end to end into a report.
- Full public API flow via real HTTP against a running `php artisan
  serve` (Phase 6): register → login → anonymous audit creation
  (max_pages correctly capped at 5, second attempt from the same IP
  correctly 429'd) → registered-user audit creation (correctly
  independent of the exhausted anonymous IP limit, max_pages correctly
  25 from the plan) → status polling → report fetch showing
  `is_full_access: false` for the anonymous view and `true` for the
  owner's authenticated view of the same audit. This is the only
  end-to-end evidence of the real HTTP kernel, real Sanctum token
  issuance/verification, and real middleware stack working together —
  `AuditApiTest`/`AuthTest` cover the same logic through Laravel's test
  client, which shares the app container across calls (see the
  Sanctum-guard note in CONTEXT.md §28) rather than truly separate
  processes.
- **Frontend manual verification** (this chunk): the entire new React
  frontend driven by hand in a real browser against the real running
  backend (`php artisan serve` + Vite dev server, no mocked responses) —
  homepage/services/how-it-works/pricing/FAQ/contact render real CMS and
  plan data; signup → login → dashboard shows the new user's real
  subscription/plan immediately (this run is what caught the missing
  `.load('subscription.plan')` bug on `register()`/`login()`, see
  CONTEXT.md §28); anonymous URL submission → progress polling → status
  reaching `completed` → preview report render (this run is what caught
  the polling-interval/429 bug, see CONTEXT.md §28); registered-user
  My Websites/Audits/History/Reports pages backed by the new
  `/api/websites` and `/api/audits` endpoints; full report page incl.
  PDF download; admin login → all 16 nav sections load real data; admin
  provider configuration — editing a key, Test Connection against each
  of Claude/OpenAI/Gemini/PageSpeed, enable/disable gating on
  `isConfigured()` (this run is what caught the empty-string-key bug,
  see CONTEXT.md §28), and confirming no raw key ever appears in a
  network response body; admin API/AI usage analytics charts render
  real `ApiUsageLog` data with working date-range filters; mobile
  viewport pass via `resize_window` on the public nav (this run is what
  caught the missing mobile hamburger menu, see CONTEXT.md §28) and the
  dashboard/admin sidebar layouts. Every bug fixed in this chunk (see
  CONTEXT.md §28) was found this way, not through code review — no
  automated equivalent exists yet (see "Frontend tests"/"E2E tests"
  above), so this remains the only evidence the frontend and backend
  actually work together for real.
- **Plan feature/AI-model access management manual verification**
  (this chunk, CONTEXT.md §33): admin login → Pricing Plans page renders
  the real Users/Features/AI Models/Status columns and the global
  feature registry panel with real per-feature toggle state; opened the
  tabbed plan editor's AI Models tab and confirmed all 3 real seeded
  models render grouped by their real provider; on the Providers page,
  added a real "Claude Opus 5" model through the new per-provider model
  catalog UI, confirmed it appeared in both the catalog list and the
  "Active model" `<select>`, then deleted it; toggled "AI Product
  Auditor" off in the global registry and confirmed it disappeared
  entirely (not shown as locked) from both the public Pricing page's
  comparison table and the audit-submission `PlanFeatureChecklist`, then
  toggled it back on; confirmed the dashboard Subscription page
  correctly reports zero available AI models for a real account in this
  dev environment, because every AI provider here is currently disabled
  — this is the "provider must also be enabled" leg of the access chain
  working correctly, not a bug. No new bugs were found this pass (the
  automated suite's "no plan at all → unrestricted" fallback work
  happened *before* this manual pass, catching what would otherwise have
  been a full regression of every pre-existing plan-agnostic test — see
  the Known gaps summary entry below).

---

## Known gaps summary

1. ~~`UrlSecurityValidator` has zero automated tests~~ — **closed**: 19
   PHP tests (`UrlSecurityValidatorTest`).
2. ~~`crawler/lib/ssrf.mjs` has zero automated tests, no Node test runner
   exists~~ — **closed**: added `node --test` (Node's built-in runner,
   no new dependency) and 40 tests in `crawler/lib/ssrf.test.mjs`
   covering `isPrivateAddress` and `assertHostnameIsPublic` directly.
3. ~~No automated Feature/integration test drives the full
   crawl→analysis pipeline~~ — **closed**:
   `CrawlAndAnalyzePipelineTest` (2 tests) drives the real pipeline
   (`CrawlOrchestrator`, SSRF validation, platform detection, all 7 real
   audit modules, real scoring) with only the crawler subprocess and
   outbound HTTP faked, exploiting the `sync` queue driver in tests to
   run the whole job cascade inline.
4. ~~`PlatformDetector`, `PerformanceAuditModule`,
   `LandingPageAuditModule`, `ConversionAuditModule` had no dedicated
   unit tests~~ — **closed**: 32 new tests across
   `PlatformDetectorTest` (14), `PerformanceAuditModuleTest` (10),
   `LandingPageAuditModuleTest` (4), `ConversionAuditModuleTest` (4).
5. ~~Migration rollback and cascade-delete behavior were unverified~~ —
   **closed**: `DatabaseMigrationTest` (3 tests: fresh migrate, single-
   step rollback + re-migrate, full reset) and `CascadeDeleteTest` (15
   tests as of Phase 7 — 11 from Phase 6 plus 4 covering
   `leads`/`lead_activities`/`cms_pages`/`cms_sections`), covering every
   declared `cascadeOnDelete()`/`nullOnDelete()` relationship in the
   schema plus the one `RESTRICT`-by-default relationship — `plans` ←
   `subscriptions` — that deliberately prevents deletion), confirmed to
   exercise real FK enforcement (not vacuous passes) by checking
   SQLite's `foreign_key_constraints` connection setting first.
6. ~~No API tests existed~~ — **closed** (Phase 6, extended Phase 7):
   `AuthTest` (10 tests), `AuditApiTest` (15 tests), and `AdminApiTest`
   (30 tests, Phase 7) exercise the real HTTP endpoints, not just the
   services behind them — authentication, authorization (including a
   cross-user isolation check that caught a real Sanctum-guard testing
   gotcha, documented in CONTEXT.md §28; and admin-gate 401/403 checks),
   validation, status codes, error handling, rate limiting, and
   pagination.
7. ~~Everything under Frontend/E2E, and the Feature-level flows
   explicitly marked not-built (recrawl, leads, admin), was a gap
   because the corresponding features didn't exist yet~~ — **partially
   closed** (Phase 7): lead capture and admin (dashboard, resource
   listings, plan CRUD, CMS, lead management) are now built and tested
   via `AdminApiTest`. Frontend/E2E for these remains a gap since no
   frontend UI exists yet for admin or lead capture — see CONTEXT.md §27
   for phase status.
8. ~~`OpenAiProvider`/`GeminiProvider` only have their success-path
   parsing tested; the missing-key/error-status pair is only verified
   for `ClaudeProvider`~~ — **closed** (Phase 9): 4 new `ProvidersTest`
   cases give both providers the same missing-key/error-status pair
   Claude already had.
9. ~~No test asserts the DB rejects a duplicate insert against the
   `audit_scores` (per audit+category) or `ai_analyses` (cache key)
   unique constraints directly~~ — **closed** (Phase 9): `UniqueConstraintsTest`
   (2 tests) asserts a real `QueryException` from each constraint,
   not just that application code happens to call `updateOrCreate()`.
   (The equivalent `cms_sections` `(cms_page_id, section_key)` unique
   constraint from Phase 7 still has this gap — low priority, same
   reasoning as before: only ever written to via `updateOrCreate`.)
10. ~~No test sends oversized payloads, unexpected types, or injection-
    style strings to the audit/auth/admin endpoints~~ — **closed**
    (Phase 9): 6 new tests across `AuditApiTest`/`AuthTest`/`AdminApiTest`
    (see the API tests table's "Duplicate/malicious input" row) — the
    injection/XSS-style-string tests specifically assert the string was
    stored verbatim and the table survives intact, which is stronger
    evidence of safety than just "the request didn't error."
11. ~~PDF export and scheduled/recurring audits (`plans.full_recrawls`,
    `plans.features['scheduled_audits']`/`['pdf_export']`, and the
    `audit_schedules` table) sat unused/unbuilt~~ — **closed** (Phase 8):
    `ReportPdfServiceTest` (2), `AuditApiTest`'s 5 PDF-export tests,
    `ScheduleFrequencyTest` (4), `AuditSchedulerServiceTest` (8),
    `AuditScheduleApiTest` (12), plus `AdminApiTest`'s and
    `AdminDashboardServiceTest`'s new schedule-listing/count tests and
    `CascadeDeleteTest`'s 2 new `audit_schedules` rows — 35 new tests
    total this phase. Writing the anonymous-viewer PDF test surfaced a
    second test-only gotcha (`withHeader()` sets a *persistent* default
    header on the test client, not a per-request one — see CONTEXT.md
    §27), distinct from but easy to confuse with the existing
    Sanctum-guard-memoization one; both are needed together whenever a
    test authenticates and then makes a deliberately-anonymous request
    in the same method.
12. ~~`/register`, `/login`, and `/leads` were unthrottled; audit IDs had
    no ownership check at all on the status endpoint; the password
    policy was Laravel's bare 8-characters-only default; no response
    carried baseline security headers~~ — **closed** (Phase 9, dedicated
    security hardening pass): rate-limit tests (3), audit-ID-ownership
    tests (3), password-policy tests (2), and security-header tests (2)
    — 10 new tests, plus the 6 malicious-input, 4 AI-provider-failure,
    and 2 unique-constraint tests already counted in items 8-10 above —
    22 new PHP tests total this phase (235 → 257). The security-headers
    test suite caught a real implementation bug before it shipped: a
    first (middleware-based) version silently missed every
    exception-rendered response (401/403/422/429/404) because middleware
    post-`$next()` code doesn't run on that path — see CONTEXT.md §26/§28
    for the fix (a `RequestHandled` event listener instead).
13. AI/API provider credentials and limits lived in `.env`/`config/*.php`
    with no admin UI, no encryption, no usage/cost tracking beyond
    `ai_analyses`' per-audit records, and no coverage for PageSpeed at
    all — **closed**: DB-backed encrypted provider config
    (`App\Models\ApiProvider`) and cross-provider usage tracking
    (`App\Models\ApiUsageLog`), with admin CRUD/test-connection/usage-
    analytics endpoints. 30 new PHP tests this chunk (257 → 287):
    `AdminProviderApiTest` (20, see the "Provider configuration and
    usage tracking tests" section above for the full TC-ID mapping),
    `AiAnalysisServiceTest` additions (3), `AdminDashboardServiceTest`
    addition (1), `CascadeDeleteTest` additions (2), plus `ProvidersTest`
    growing from 10 to 14 as its `config()`-based setup was rewritten to
    seed real `ApiProvider` rows (the DB is now the only source of truth
    for provider settings, so the old config-mutation approach no longer
    reflects reality). See CONTEXT.md §23 for the full design.
14. The project had no production frontend beyond the Vite scaffold —
    **closed**: full React frontend built (13 public pages, auth flow,
    audit submission/progress/preview/full report, user dashboard, admin
    dashboard covering all 16 nav sections, provider configuration UI,
    usage analytics with charts) and verified end-to-end by hand against
    the real backend — see "Frontend manual verification" above and
    CONTEXT.md §27-§29. 12 new PHP tests this chunk (287 → 299):
    `PlanApiTest` (2), `AuditApiTest`/`AuthTest`/`AdminApiTest` additions
    for `/api/websites`, `/api/audits`, `/api/admin/system-health`, and
    the empty-string-key regression (see item 13's `AdminProviderApiTest`
    count, 20 → 21). No automated frontend/E2E suite exists yet (see
    "Frontend tests"/"E2E tests" above) — that remains a real gap, not
    a defect, since every page was still proven working once against
    live data, just not regression-tested going forward.
15. Pricing plans had no per-feature or per-AI-model access control —
    every audit category ran unconditionally for every plan, and every
    plan could use whichever `AI_DEFAULT_PROVIDER` was globally
    configured, with no admin control over either — **closed**: the
    7-feature registry (`features`/`plan_features`) and AI model catalog
    (`ai_models`/`plan_ai_models`) described in CONTEXT.md §33, with
    real backend enforcement (`AuditModuleRegistry::modulesFor()`,
    `PlanAiModelResolver`) — not just frontend hiding. 41 new PHP tests
    this chunk (299 → 340): `FeatureAccessResolverTest` (7),
    `PlanAiModelResolverTest` (6), `AuditModuleRegistryFeatureGatingTest`
    (2, driving the real crawl→analyze pipeline), `AdminAiModelApiTest`
    (10), `MeApiTest` (5), `AdminApiTest` additions (7), `CascadeDeleteTest`
    additions (4). Discovering that the naive "deny when no plan
    resolves" design would have broken every pre-existing plan-agnostic
    test (`CrawlAndAnalyzePipelineTest`, `AiAnalysisServiceTest`,
    `ProvidersTest`, all 4 AI-driven module tests) surfaced the
    "no plan exists at all → unrestricted" fallback documented in
    CONTEXT.md §31 — found by running the full pre-existing suite after
    the initial implementation and seeing it fail, not by reasoning about
    it in advance. Known gaps: `product_auditor` has no backing audit
    module yet (registry-only), and a plan can only ever use a
    provider's one currently-*active* model, not truly pick among
    several concurrently (see CONTEXT.md §29 for why this is an
    architectural constraint, not an oversight).
16. A real audit against a live 25-page site (not a synthetic fixture)
    scored `seo: 0` and `performance: 0`, and produced a false-positive
    Ecommerce score on a nonprofit ministry site — **closed**:
    `PerPageIssueAggregator` (site-wide aggregation of a repeated
    per-page issue, the same pattern `AccessibilityAuditModule` already
    used) fixes the zero-score bug; `EcommerceDetector` (weighted
    multi-signal confidence scoring, replacing a single loose CTA-text
    regex) fixes ecommerce false positives; `audit_scores.score` is now
    nullable with an `is_applicable` flag so "Not Applicable" is a real
    state, never a fabricated 0 or 100; a visibility regression in
    `AiAnalysisService` (introduced by item 15's own `PlanAiModelResolver`
    — "no AI model allowed" silently recorded nothing) now writes a
    visible `ai_analyses` error row. A **second** ecommerce false
    positive on the *same* real site was found only by re-running the
    real audit again after the first fix landed (a checkout-link
    signal's weight was too generous) — see CONTEXT.md §34 for the full
    account of both misses. 13 new PHP tests this chunk (340 → 353):
    `PerPageIssueAggregatorTest` (4), `SeoAuditModuleTest`/
    `PerformanceAuditModuleTest` aggregation regressions (2),
    `EcommerceAuditModuleTest` additions (2 new false-positive
    regressions, 1 rewritten for the Not Applicable state), `AiAnalysisServiceTest`
    addition (1). Known gap: `ConversionAuditModule`/`LandingPageAuditModule`
    still have only 1-2 deterministic checks each — accurate for what
    they actually check, not fabricated, but a much narrower audit than
    the product spec's ~15-signal CRO/AIO checklists; building that out
    was explicitly scoped out of this pass.
17. Screenshots were captured (desktop only, one path column on
    `audit_pages`) but nothing used them, and an issue affecting several
    pages was only listable via a `meta.affected_pages` JSON array — not
    queryable by page, and a page's issues weren't queryable at all in
    the multi-page case — **closed**: dual-viewport (desktop 1366×900 +
    mobile 390×844) capture from a single page load, a dedicated
    `screenshots` table (`App\Models\Screenshot`), and a real `issue_pages`
    pivot (`AuditIssue::pages()`, `AuditPage::multiPageIssues()`/
    `allIssues()`) giving genuine bidirectional queryability — see
    CONTEXT.md §34. This reopens (supersedes) the JSON-blob decision from
    item 15's own session, once a later requirement explicitly needed
    bidirectional queries a JSON blob can't do without a table scan. 4
    new PHP tests this chunk (counted in item 16's 353 total, 353 → 354):
    `IssuePageRelationshipTest` (1), `CascadeDeleteTest` additions for
    `screenshots`/`issue_pages` (2), `CrawlAndAnalyzePipelineTest`'s
    screenshot-storage-path regression (1, see the Database tests
    table) — a real bug (Windows absolute path stored instead of a
    relative one, `CrawlOrchestrator::toPublicPath()`) found by
    inspecting a live audit's actual `Screenshot.storage_path`, not by
    any test, since every prior test faked the disk with a consistent
    separator throughout — plus 4 previously-missing
    `plan_features`/`plan_ai_models`/`ai_models` cascade rows from item 15
    that were written but never actually added to the Database tests
    table until now. Explicitly out of scope for this pass (per the
    user's own sequencing choice): the seven per-service report UIs, the
    GTmetrix/PageSpeed-style performance report, per-service PDFs, the
    page explorer, and the frontend dashboard redesign — this closes only
    the data model and capture pipeline they'll need.
