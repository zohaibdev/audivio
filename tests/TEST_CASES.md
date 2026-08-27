# Test Case Registry

Central index of test coverage for Audivio. Every meaningful code change
must be checked against the relevant cases here — do not declare a task
complete on visual inspection or manual testing alone. Update this file
whenever coverage is added, moved, or removed.

**Run**: `cd backend && php artisan test` (SQLite in-memory DB, `Http::fake()`
for all external calls — no test hits a real third-party API) and
`cd crawler && npm test` (Node's built-in test runner, `node --test`).

**Current total**: 240 tests (200 PHP + 40 Node), all passing, 0 known failures.

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
| Platform detection | Shopify (CDN reference, header, case-insensitive header) | `PlatformDetectorTest::test_detects_shopify_from_cdn_reference` + 2 siblings | ✅ |
| Platform detection | WordPress (wp-content, generator meta) | `PlatformDetectorTest::test_detects_wordpress_from_wp_content` + 1 sibling | ✅ |
| Platform detection | WooCommerce (WordPress + commerce markers) vs plain WordPress | `PlatformDetectorTest::test_detects_woocommerce_as_wordpress_plus_woocommerce_markers`, `test_wordpress_without_woocommerce_markers_is_plain_wordpress` | ✅ |
| Platform detection | Webflow (data-wf-site attribute) | `PlatformDetectorTest::test_detects_webflow_from_data_attribute` | ✅ |
| Platform detection | Wix (static asset domain, header) | `PlatformDetectorTest::test_detects_wix_from_static_asset_domain`, `test_detects_wix_from_header` | ✅ |
| Platform detection | Signal priority (Shopify wins over a simultaneous WordPress match) | `PlatformDetectorTest::test_shopify_signal_takes_priority_over_wordpress` | ✅ |
| Platform detection | Unrecognized generator meta → Custom; no signal at all → Unknown | `PlatformDetectorTest::test_unrecognized_generator_meta_is_custom`, `test_no_signals_at_all_is_unknown`, `test_empty_html_is_unknown` | ✅ |
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
| Report access | A different registered user does NOT get full access to someone else's audit (closed an authorization gap — see CONTEXT.md §9/§26) | `ReportAccessResolverTest::test_a_different_registered_user_does_not_get_full_access_to_someone_elses_audit` | ✅ |
| Report access | Anonymous viewer of a registered user's audit has no full access | `ReportAccessResolverTest::test_anonymous_viewer_of_a_registered_users_audit_has_no_full_access` | ✅ |
| Admin dashboard | Users/admins counted separately | `AdminDashboardServiceTest::test_counts_users_and_admins_separately` | ✅ |
| Admin dashboard | Audits grouped by status | `AdminDashboardServiceTest::test_audits_grouped_by_status` | ✅ |
| Admin dashboard | Leads grouped by status | `AdminDashboardServiceTest::test_leads_grouped_by_status` | ✅ |
| Admin dashboard | AI usage separates successful from failed calls, sums tokens | `AdminDashboardServiceTest::test_ai_usage_separates_successful_from_failed_calls_and_sums_tokens` | ✅ |
| Admin dashboard | Empty database returns zeros, not errors/exceptions | `AdminDashboardServiceTest::test_empty_database_returns_zeros_not_errors` | ✅ |

## Feature tests

| Flow | Test | Status |
|---|---|---|
| Signup | `AuthTest::test_registration_creates_a_user_and_returns_a_token`, `test_registration_subscribes_the_user_to_the_default_plan`, `test_registration_rejects_a_duplicate_email`, `test_registration_rejects_mismatched_password_confirmation` | ✅ |
| Login | `AuthTest::test_login_with_correct_credentials_returns_a_token`, `test_login_with_wrong_password_is_rejected`, `test_login_with_unknown_email_is_rejected` | ✅ |
| Logout | `AuthTest::test_logout_revokes_the_current_token` (see CONTEXT.md §26 for the Sanctum-guard testing gotcha this test works around) | ✅ |
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
| Recrawl | — | ⬜ not built — no recrawl endpoint/feature exists yet (only `full_recrawls` sits unused on `plans`, reserved for when it's built) |
| Lead creation (public) | `AdminApiTest::test_anyone_can_submit_a_lead`, `test_lead_submission_requires_a_valid_source` | ✅ |
| Lead management (admin) | `AdminApiTest::test_admin_can_list_and_filter_leads_by_status`, `test_admin_can_update_lead_status_and_it_logs_an_activity`, `test_admin_can_add_a_note_to_a_lead`, `test_lead_submission_is_rejected_without_admin_auth_to_list` | ✅ |
| CMS content (public read) | `AdminApiTest::test_public_can_read_a_cms_page`, `test_public_read_of_unknown_cms_page_returns_404` | ✅ |
| CMS content (admin management) | `AdminApiTest::test_admin_can_create_a_cms_page`, `test_admin_can_upsert_a_section_creating_it_if_new`, `test_admin_can_upsert_a_section_updating_it_if_it_exists` (asserts update, not duplicate, on the second call), `test_admin_can_delete_a_section`, `test_cms_page_slug_must_be_unique` | ✅ |
| Admin operations — authorization | `AdminApiTest::test_unauthenticated_request_to_admin_route_is_rejected` (401), `test_non_admin_authenticated_user_is_rejected_with_403` (403 — a real Sanctum-authenticated non-admin, not just no auth), `test_admin_user_can_access_admin_routes` | ✅ |
| Admin operations — dashboard/resource listings | `AdminApiTest::test_dashboard_returns_summary_data`, `test_users_listing_is_paginated_and_includes_subscription`, `test_websites_listing_includes_audit_count`, `test_audit_show_includes_scores_and_issues`, `test_crawl_jobs_listing`, `test_usage_listing`, `test_ai_usage_endpoint_returns_aggregates` | ✅ |
| Admin operations — failed jobs | `AdminApiTest::test_failed_jobs_listing_and_deletion`, `test_deleting_an_unknown_failed_job_returns_404` | ✅ |
| Admin operations — plan CRUD | `AdminApiTest::test_admin_can_create_a_plan`, `test_setting_a_new_default_plan_unsets_the_previous_default` (only one plan can ever be default), `test_admin_can_update_a_plan`, `test_admin_cannot_delete_a_plan_with_an_active_subscription` (409, not a raw 500), `test_plan_slug_must_be_unique` | ✅ |

**The default `Tests\Feature\ExampleTest`** (Laravel scaffold, asserts `/`
returns 200) still exists and passes — kept as-is; no real reason to
replace it, the `web.php` root route it tests isn't part of this app's
actual functionality either way.

## API tests

| Category | Test | Status |
|---|---|---|
| Authentication (register/login/logout/me) | `AuthTest` (10 tests, see Feature tests table above) | ✅ |
| Authorization (report access scoped to the real viewer; admin gate) | `AuditApiTest`'s report-access tests, `AdminApiTest`'s authorization tests (see Feature tests table above) | ✅ |
| Validation (missing/invalid input → 422) | `AuthTest::test_registration_rejects_mismatched_password_confirmation`, `AuditApiTest::test_missing_url_is_rejected_with_a_validation_error`, `AdminApiTest::test_plan_slug_must_be_unique`, `test_cms_page_slug_must_be_unique`, `test_lead_submission_requires_a_valid_source` | ✅ |
| Status codes (200/201/202/401/403/404/409/422/429) | Exercised across `AuthTest`/`AuditApiTest`/`AdminApiTest` — every status code the API actually returns has at least one test asserting it, including 403 (non-admin) and 409 (plan deletion conflict), which only exist because of Phase 7 | ✅ |
| Response schema | `AuthTest::test_registration_creates_a_user_and_returns_a_token` asserts `assertJsonStructure(['data' => ['user', 'token']])`; `AdminApiTest::test_dashboard_returns_summary_data` asserts the full dashboard shape; other tests assert specific `assertJsonPath()` values | ✅ |
| Error handling (SSRF rejection, usage-limit rejection, plan-deletion conflict) | `AuditApiTest::test_unsafe_url_is_rejected_before_creating_any_records`, usage-limit tests (see Feature tests table), `AdminApiTest::test_admin_cannot_delete_a_plan_with_an_active_subscription` | ✅ |
| Rate limits | `AuditApiTest::test_requests_beyond_the_rate_limit_are_throttled` | ✅ |
| Security — cross-user data leakage | `AuditApiTest::test_report_endpoint_does_not_grant_full_access_to_a_different_registered_user` | ✅ |
| Pagination | `AdminApiTest::test_users_listing_is_paginated_and_includes_subscription` and every other admin listing test asserts the paginator shape (`current_page`/`total`/`data`) | ✅ |
| Duplicate/malicious input beyond SSRF | — | ⬜ gap: no test sends e.g. oversized payloads, unexpected types, or injection-style strings to the audit/auth/admin endpoints — Laravel's validation layer handles most of this by construction, but it isn't explicitly exercised |
| Backward compatibility | — | ⬜ n/a — no prior API version exists to be compatible with |

## Database tests

| Check | Status |
|---|---|
| Fresh migrations run cleanly | `DatabaseMigrationTest::test_migrate_fresh_runs_cleanly` — asserts exit code 0 and every application table exists afterward | ✅ |
| Every table is InnoDB with enforced FKs | ✅ verified manually via `information_schema` query in Phase 2 after finding the MyISAM default-engine issue (MySQL-specific — the automated test suite runs against SQLite, so this check isn't portable to it); not automated |
| Migration rollback (`down()`) — single-step and full reset | `DatabaseMigrationTest::test_rolling_back_the_last_migration_drops_its_table_and_remigrating_restores_it`, `test_rolling_back_every_migration_drops_every_application_table` — actually runs `migrate:rollback` / `migrate:reset` and asserts tables are dropped, then re-migrates and asserts they're back | ✅ |
| Unique constraints (`audit_scores` per audit+category, `ai_analyses` cache key) | ✅ exercised indirectly — `updateOrCreate` calls in `RunAuditModuleJob`/`CalculateScoreJob`/`AiAnalysisService` rely on these and are covered by the Unit tests above, but no test directly asserts the DB rejects a duplicate insert |
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

All cascade tests above were confirmed to exercise real DB-level FK
enforcement, not vacuous passes — `config/database.php`'s sqlite
connection sets `foreign_key_constraints => true` by default, checked
before trusting these results.

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
  Sanctum-guard note in CONTEXT.md §26) rather than truly separate
  processes.

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
   gotcha, documented in CONTEXT.md §26; and admin-gate 401/403 checks),
   validation, status codes, error handling, rate limiting, and
   pagination.
7. ~~Everything under Frontend/E2E, and the Feature-level flows
   explicitly marked not-built (recrawl, leads, admin), was a gap
   because the corresponding features didn't exist yet~~ — **partially
   closed** (Phase 7): lead capture and admin (dashboard, resource
   listings, plan CRUD, CMS, lead management) are now built and tested
   via `AdminApiTest`. Frontend/E2E for these remains a gap since no
   frontend UI exists yet for admin or lead capture — see CONTEXT.md §25
   for phase status.
8. `OpenAiProvider`/`GeminiProvider` only have their success-path parsing
   tested; the missing-key/error-status pair is only verified for
   `ClaudeProvider`. Low priority — the failure behavior is structurally
   identical across all three (shared `AiProviderException` pattern), not
   independently implemented per provider.
9. No test asserts the DB rejects a duplicate insert against the
   `audit_scores` (per audit+category) or `ai_analyses` (cache key)
   unique constraints directly — only exercised indirectly via
   `updateOrCreate` call sites. (The equivalent `cms_sections`
   `(cms_page_id, section_key)` unique constraint from Phase 7 has the
   same gap.)
10. No test sends oversized payloads, unexpected types, or injection-
    style strings to the audit/auth/admin endpoints beyond what
    Laravel's validation layer handles by construction (see the API
    tests table's "Duplicate/malicious input" row).
