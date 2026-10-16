# Test Case Registry

Central index of test coverage for Audivio. Every meaningful code change
must be checked against the relevant cases here — do not declare a task
complete on visual inspection or manual testing alone. Update this file
whenever coverage is added, moved, or removed.

**Run**: `cd backend && php artisan test` (SQLite in-memory DB, `Http::fake()`
for all external calls — no test hits a real third-party API) and
`cd crawler && npm test` (Node's built-in test runner, `node --test`).

**Current total**: 760 tests (698 PHP + 62 Node), all passing, 0 known failures.
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
| URL normalization | Trailing-slash homepage dedup | — | ⬜ gap: fixed as a real bug (see CONTEXT.md §28) but not regression-tested — a sitemap URL matching the seed URL modulo trailing slash should not be double-created as a `CrawlUrl`. Note this is *`CrawlUrl`* dedup within one crawl; **website-record** dedup across audits is separately and fully covered under "Website records" below |
| URL normalization | Scheme/host lowercased; bare host gets the implied `https://`; empty path becomes `/`; fragment dropped; query preserved; default port (80/443) dropped but a non-default port kept for the validator to reject | `UrlNormalizerTest` — 10 cases | ✅ |
| URL normalization | DNS root trailing dot stripped from the host; Unicode host converted to punycode; IPv6 literal keeps its brackets | `UrlNormalizerTest::test_dns_root_trailing_dot_is_stripped_from_the_host`, `test_unicode_host_is_converted_to_punycode`, `test_ipv6_literal_keeps_its_brackets` | ✅ |
| URL normalization | `javascript:`/`data:`/`file:`/`mailto:` rejected rather than silently prefixed with `https://`; credentials, embedded newline/tab, empty input, overlong URL and host-less URL rejected | `UrlNormalizerTest` — 9 rejection cases | ✅ |
| URL normalization | `origin()` strips path/query/fragment (what robots.txt and sitemap.xml URLs are built from) | `UrlNormalizerTest::test_origin_strips_path_query_and_fragment`, `test_origin_of_a_bare_host` | ✅ |
| SSRF / URL security | Loopback (IPv4 `127.0.0.1` + IPv6 `::1`), private ranges (10/8, 172.16/12, 192.168/16), cloud metadata (`169.254.169.254`), IPv6 unique-local (`fc00::/7`) | `UrlSecurityValidatorTest::test_loopback_ipv4_is_blocked` and 6 sibling `test_*_is_blocked` methods | ✅ |
| SSRF / URL security | Public IP literal allowed | `UrlSecurityValidatorTest::test_public_ip_literal_is_allowed` | ✅ |
| SSRF / URL security | `localhost` / case-insensitive / `*.localhost` subdomain blocked | `UrlSecurityValidatorTest::test_localhost_hostname_is_blocked` + 2 siblings | ✅ |
| SSRF / URL security | Disallowed scheme (`ftp://`, `file://`) blocked, `http`/`https` allowed | `UrlSecurityValidatorTest::test_ftp_scheme_is_blocked`, `test_file_scheme_is_blocked` | ✅ |
| SSRF / URL security | Non-standard port blocked, 80/443 allowed | `UrlSecurityValidatorTest::test_non_standard_port_is_blocked`, `test_standard_https_port_is_allowed` | ✅ |
| SSRF / URL security | Embedded credentials blocked | `UrlSecurityValidatorTest::test_embedded_credentials_are_blocked` | ✅ |
| SSRF / URL security | Oversized query string blocked | `UrlSecurityValidatorTest::test_oversized_query_string_is_blocked` | ✅ |
| SSRF / URL security | Malformed URL blocked | `UrlSecurityValidatorTest::test_malformed_url_is_blocked` | ✅ |
| SSRF / URL security | Real public hostname resolves via DNS and is allowed (sanity check against the actual DNS-resolution code path) | `UrlSecurityValidatorTest::test_real_public_hostname_resolves_and_is_allowed` | ✅ |
| SSRF / URL security | Ranges PHP's own `FILTER_FLAG_NO_PRIV_RANGE\|NO_RES_RANGE` does **not** cover: carrier-grade NAT (100.64/10), IETF protocol assignments (192.0.0.0/24), benchmark (198.18/15), multicast (224/4), NAT64 (`64:ff9b::/96`, which embeds an arbitrary IPv4 destination) | `UrlSecurityValidatorTest::test_carrier_grade_nat_range_is_blocked` + 4 siblings, `test_addresses_just_outside_blocked_ranges_are_allowed` for the boundaries | ✅ |
| SSRF / URL security | IPv4-mapped IPv6 judged by the IPv4 rules, in both dotted (`::ffff:127.0.0.1`) and hex (`::ffff:7f00:1`) spellings | `UrlSecurityValidatorTest::test_ipv4_mapped_ipv6_loopback_is_blocked` + 2 siblings | ✅ |
| SSRF / URL security | Alternate integer spellings of loopback — decimal (`2130706433`), octal (`0177.0.0.1`), hex (`0x7f.0.0.1`), short dotted (`127.1`). These fail every IP parser but resolve to 127.0.0.1 in Chromium/curl, so failing the IP check must not mean "treat as a domain" | `UrlSecurityValidatorTest::test_decimal_integer_form_of_loopback_is_blocked` + 3 siblings | ✅ |
| SSRF / URL security | `localhost.` (DNS root dot) blocked — resolves identically but is a different string, so a literal comparison without canonicalization would let it past | `UrlSecurityValidatorTest::test_localhost_with_trailing_dns_root_dot_is_blocked` | ✅ |
| SSRF / URL security | `javascript:`, `data:`, `gopher:` schemes blocked; non-web ports (6379 redis, 8080) blocked | `UrlSecurityValidatorTest::test_javascript_scheme_is_blocked` + 4 siblings | ✅ |
| SSRF / URL security | Credentials disguising an internal host (`http://example.com@127.0.0.1/`) blocked — reads as example.com to a human, 127.0.0.1 to every parser | `UrlSecurityValidatorTest::test_credentials_disguising_an_internal_host_are_blocked` | ✅ |
| SSRF / URL security | Shell metacharacters, command substitution, SQL and XSS payloads in the host position rejected before reaching a resolver, a subprocess argument or a log line | `UrlSecurityValidatorTest::test_shell_metacharacters_in_host_are_blocked` + 3 siblings | ✅ |
| SSRF / URL security | Raw CR/LF and NUL in the URL rejected (request splitting) rather than silently stripped the way a browser would | `UrlSecurityValidatorTest::test_crlf_in_url_is_blocked`, `test_null_byte_in_url_is_blocked` | ✅ |
| SSRF / URL security | Overlong URL rejected; resolved IPs returned so a caller can pin rather than re-resolve | `UrlSecurityValidatorTest::test_oversized_url_is_blocked`, `test_resolved_addresses_are_returned_for_the_caller_to_pin` | ✅ |
| SSRF / redirect validation | **A 302 from a legitimately-public host into the cloud metadata endpoint is blocked.** The headline case: every hop of the chain is re-validated, because the URL that passed the check is not the URL a redirect-following client ends up at | `SafeHttpClientTest::test_redirect_to_the_cloud_metadata_endpoint_is_blocked` | ✅ |
| SSRF / redirect validation | Redirect to localhost, to a private IP, to a non-http scheme, and a scheme-relative `//127.0.0.1/` redirect all blocked | `SafeHttpClientTest` — 4 cases | ✅ |
| SSRF / redirect validation | A relative `Location` is resolved against its origin before being checked; a safe cross-host redirect is still followed (the protection blocks unsafe targets, not redirects as such) | `SafeHttpClientTest::test_a_relative_redirect_is_resolved_against_its_origin_and_followed`, `test_a_safe_cross_host_redirect_is_followed` | ✅ |
| SSRF / redirect validation | A chain longer than `crawler.max_redirects` is abandoned even when every individual hop is safe; a 302 with no `Location` gives up | `SafeHttpClientTest::test_a_redirect_chain_longer_than_the_budget_is_abandoned`, `test_a_redirect_with_no_location_header_gives_up` | ✅ |
| Response size limits | An oversized `Content-Length` is rejected without reading the body, **and** an oversized body with no `Content-Length` at all (the chunked case a header-only check would miss) is rejected mid-read; a body within the cap is returned whole | `SafeHttpClientTest` — 3 cases | ✅ |
| SSRF / URL security (Node) | `isPrivateAddress` — every IPv4 blocked range (0.0.0.0/8, 10/8, 100.64/10, 127/8, 169.254/16 incl. cloud metadata, 172.16/12, TEST-NET 1-3, 198.18/15, multicast, broadcast) + boundary cases just outside each range | `crawler/lib/ssrf.test.mjs` — `isPrivateAddress (IPv4)` suite, 23 cases | ✅ |
| SSRF / URL security (Node) | `isPrivateAddress` — IPv6 loopback/unspecified/unique-local/link-local + IPv4-mapped addresses (both blocked and allowed cases) | `crawler/lib/ssrf.test.mjs` — `isPrivateAddress (IPv6)` suite, 9 cases | ✅ |
| SSRF / URL security (Node) | Malformed address treated as private (fail closed) | `crawler/lib/ssrf.test.mjs::treats a malformed address as private` | ✅ |
| SSRF / URL security (Node) | `assertHostnameIsPublic` — localhost, empty hostname, IP-literal loopback/private/cloud-metadata/IPv6-loopback all rejected without a real DNS query; public IP literal allowed; real hostname resolves via actual DNS and is allowed | `crawler/lib/ssrf.test.mjs` — `assertHostnameIsPublic` suite, 8 cases | ✅ |
| SSRF / URL security (Node) | Ranges added in the Phase 1 pass: NAT64-wrapped metadata and loopback, IPv6 multicast, documentation (`2001:db8::/32`), discard-only (`100::/64`), IPv4-mapped in hex form, link-local with a zone index; a malformed IPv6 address fails closed | `crawler/lib/ssrf.test.mjs` — `isPrivateAddress: ranges the original list missed` suite, 11 cases | ✅ |
| SSRF / URL security (Node) | An octal octet is read as octal by real resolvers but as decimal by `Number()`, so `0177.0.0.1` is rejected rather than evaluated as 177.0.0.1; an octet above 255 fails closed | `crawler/lib/ssrf.test.mjs::blocks an octal-spelled loopback octet`, `blocks an octet above 255` | ✅ |
| SSRF / URL security (Node) | Hostname forms: `*.localhost`, `localhost.`, bracketed `[::1]` as Node reports it, decimal/octal/hex loopback spellings, NAT64 literal, shell and XSS payloads all rejected; a real public hostname written with the DNS root dot is still allowed | `crawler/lib/ssrf.test.mjs` — `assertHostnameIsPublic: hostname forms` suite, 10 cases | ✅ |
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
| Per-service reports | A service with no score row is omitted (never an empty report); a Not Applicable service keeps `score: null` rather than a number | `ServiceReportBuilderTest::test_a_service_with_no_score_row_is_omitted_entirely`, `test_a_not_applicable_service_keeps_that_state_instead_of_a_number` | ✅ |
| Per-service reports | Issues group into user-facing categories within their service (Metadata/Headings/…), worst severity first, with a full severity summary | `ServiceReportBuilderTest::test_issues_are_grouped_into_categories_within_their_service`, `test_severity_summary_counts_every_level` | ✅ |
| Per-service reports | CRO merges its two internal categories (`conversion` + `landing_page`) into one score as their mean, not one picked arbitrarily | `ServiceReportBuilderTest::test_cro_merges_the_two_internal_categories_into_one_service_score` | ✅ |
| Per-service reports | Page findings carry both screenshots and only that service's issues (no cross-service leakage) | `ServiceReportBuilderTest::test_page_findings_include_both_screenshots_and_only_this_services_issues` | ✅ |
| Per-service reports | **Partial coverage**: an AI-dependent service whose AI failed is marked `partial` (never "good") and keeps its real deterministic score, with a note naming what was skipped — found against a live audit reporting "CRO 100 — good" with its provider down | `ServiceReportBuilderTest::test_an_ai_dependent_service_whose_ai_failed_is_marked_partial_not_good`, `test_a_service_with_working_ai_is_marked_complete`, `test_a_service_with_no_ai_dependency_is_complete_without_an_ai_record` | ✅ |
| Per-service reports | A failed AI provider reads as unavailable rather than as an absent finding; AI-derived issues are labelled `detected_by: ai` | `ServiceReportBuilderTest::test_a_failed_ai_provider_reads_as_unavailable_not_as_an_absent_finding`, `test_ai_generated_issues_are_labelled_as_ai_detected` | ✅ |
| Per-service reports | Recommendations come only from issues that actually carry one — never fabricated to fill space | `ServiceReportBuilderTest::test_recommendations_only_come_from_issues_that_carry_one` | ✅ |
| Performance metrics | Resource-breakdown issues fire from measured JS/CSS/image/font/request/weight values, each carrying both the measured value and the budget it was compared against | `PerformanceAuditModuleTest::test_resource_breakdown_flags_oversized_javascript_css_and_images`, `test_resource_issues_carry_the_measured_value_and_the_budget_as_evidence`, `test_slow_server_response_and_excessive_dom_are_flagged_from_measured_values` | ✅ |
| Performance metrics | A page with no crawler measurement raises no resource issues at all — absent data is never treated as zeros that trivially pass every budget | `PerformanceAuditModuleTest::test_a_page_with_no_crawler_metrics_raises_no_resource_issues` | ✅ |
| Mobile/desktop separation | Mobile and desktop Lighthouse results are stored as two separate rows and keep their own scores/metrics; issues are driven by the mobile result, never desktop's | `PerformanceAuditModuleTest::test_mobile_and_desktop_pagespeed_results_are_stored_separately`, `test_issues_are_driven_by_the_mobile_result_not_desktop` | ✅ |
| Performance metrics | A null Lighthouse score is not treated as a score of 0 and does not raise a critical issue | `PerformanceAuditModuleTest::test_a_null_lighthouse_score_does_not_become_a_zero_issue` | ✅ |
| Issue taxonomy | Every performance issue code maps to a specific category rather than the catch-all bucket; axe rules map to accessibility categories; unknown/AI-invented codes fall back rather than disappearing | `IssueTaxonomyTest` (19 tests incl. a data provider over all 15 performance codes) | ✅ |
| Product audit | A site with no product pages is Not Applicable, not scored; a fully specified product page scores 100 | `ProductAuditModuleTest::test_a_site_with_no_product_pages_is_not_applicable`, `test_a_fully_specified_product_page_scores_100` | ✅ |
| Product detection | Product schema alone is definitive; a product-shaped URL without a corroborating buy signal is not a product page (listing pages and site-wide nav links must not qualify) | `ProductAuditModuleTest::test_a_product_page_is_detected_from_schema_alone`, `test_a_product_shaped_url_without_a_buy_signal_is_not_a_product_page` | ✅ |
| Product audit | Missing price/availability/images/copy are flagged; a missing schema is reported once rather than cascading into several derived findings; the same defect across products aggregates to one issue | `ProductAuditModuleTest::test_missing_price_and_availability_are_flagged`, `test_a_product_page_with_no_images_is_critical`, `test_too_few_images_and_thin_copy_are_flagged`, `test_a_missing_schema_is_reported_once_not_as_several_derived_findings`, `test_findings_across_several_product_pages_are_aggregated` | ✅ |
| Screenshot paths | A relative path is produced even when the disk base path uses backslashes and the crawler's path uses forward slashes (the real Windows mismatch); POSIX and no-trailing-separator cases; a path outside the disk is normalized, not mangled | `ScreenshotPathTest` (4 tests) — reproduces what `Storage::fake()` structurally cannot, which is why the earlier fix shipped broken | ✅ |

## Feature tests

| Flow | Test | Status |
|---|---|---|
| **Business onboarding context** (§42) — the AIO goal saves through the profile endpoint; the context builder includes only fields the owner filled in (an empty field is omitted, never sent as `''`); context is "sufficient" only once the AIO goal is set; missing fields are reported for the onboarding prompt | `BusinessContextAndCompetitorTest` — 5 onboarding tests | ✅ |
| **AIO prompt set** — ten defaults run on a complete profile; the three context-dependent ones are **skipped, not asked blindly**, when the goal is missing (asking "does this serve the stated goal" with no goal invites the model to invent one); custom prompts append after defaults; a tight execution budget truncates custom prompts *before* defaults, since the baseline is what makes audits comparable | `BusinessContextAndCompetitorTest` — 4 prompt-library tests | ✅ |
| **Custom AIO prompt CRUD and limit** — add/remove; adding past `custom_aio_prompt_limit` is refused at the point of adding (not silently ignored at audit time) with `errors.limit`; a plan with a limit of 0 is told so; another user's prompts are unreachable; the index shows the defaults so an empty custom list never reads as "nothing is being asked" | `BusinessContextAndCompetitorTest` — 5 CRUD tests | ✅ |
| **Competitor discovery** — runs only when the plan allows it, the owner named none, *and* there is business context to reason from; stores suggestions with `source: discovered`; never returns the site as its own competitor; discards entries that are not resolvable domains (a hallucinated "domain" is rejected at the door, not stored as an uncrawlable row); never relabels an owner-named competitor | `BusinessContextAndCompetitorTest` — 6 discovery tests | ✅ |
| **Competitor reporting** — an unanalyzed competitor reports `aio_score: null`, never 0 (rendering zero for a site we could not reach would be a false claim about a real company); owner-named competitors sort before discovered ones regardless of score | `BusinessContextAndCompetitorTest::test_an_unanalyzed_competitor_reports_null_scores_not_zero`, `test_owner_named_competitors_are_listed_before_discovered_ones` | ✅ |
| **Snapshot backfill** (§41) — writes snapshots for audits that completed before the feature existed; marks them `backfilled` so an assembled record cannot be mistaken for a contemporaneous one; is a no-op once every audit has one (never rewrites an immutable snapshot); `--dry-run` writes nothing; an unfinished audit is skipped, since a snapshot records a *finished* result | `AuditSnapshotTest` — 6 backfill tests | ✅ |
| **Website limit** — a new website beyond the plan's cap is refused (403, `errors.limit: max_websites`); re-auditing a site already owned still works *at* the cap, so lowering a plan does not lock subscribers out of sites they have; one user's websites never count against another's | `EntitlementTest::test_a_new_website_beyond_the_plans_limit_is_refused`, `test_re_auditing_a_website_already_owned_still_works_at_the_limit`, `test_one_users_websites_do_not_count_against_another` | ✅ |
| **Monthly audit limit** — enforced on top of the daily one; usage from last month does not count (calendar month, not a rolling window); a null limit means unlimited, not zero | `EntitlementTest::test_the_monthly_audit_limit_is_enforced_on_top_of_the_daily_one`, `test_usage_from_last_month_does_not_count_against_this_month`, `test_a_null_monthly_limit_means_unlimited` | ✅ |
| **Page limit** — comes from the plan, and a `max_pages` in the request payload is inert | `EntitlementTest::test_the_page_limit_comes_from_the_plan_and_ignores_the_payload` | ✅ |
| **Report visibility** — the plan's percentage decides how many findings a restricted viewer sees; the per-service cap can lower it further; a single finding survives a low percentage (counts round up, so a restricted report still proves the audit found something); 0% still shows the overview | `EntitlementTest::test_report_visibility_percent_decides_how_much_an_owner_sees`, `test_the_issues_per_service_cap_lowers_what_the_percentage_allowed`, `ReportVisibilityFilterTest` (12 tests) | ✅ |
| Report visibility — **ownership decides whose entitlements apply, not whether to bypass them**. Regression for a hole caught in testing: passing the *viewer's* entitlements unconditionally let a non-owner on a 100%-visibility plan read someone else's full report | `ReportVisibilityFilterTest::test_a_restricted_plan_never_yields_the_full_report`, `AuditApiTest::test_report_endpoint_does_not_grant_full_access_to_a_different_registered_user` | ✅ |
| **Service entitlement / PDF entitlement** — a built feature follows the plan's configuration, and PDF export is refused without it | `EntitlementTest::test_a_built_feature_follows_the_plans_configuration`, `test_pdf_export_is_refused_without_the_entitlement` | ✅ |
| **White-label entitlement** — a capability that is not built yet never reports as allowed however the plan is configured (answering true would make callers render something no renderer produces), while still reporting what the admin set | `EntitlementTest::test_a_feature_that_is_not_built_yet_never_reports_as_allowed` | ✅ |
| **Audit frequency** — a schedule more frequent than the plan's ceiling is refused with its own message and `errors.limit`, while the allowed frequency is accepted; less frequent than the ceiling is always allowed | `EntitlementTest::test_a_schedule_more_frequent_than_the_plan_allows_is_refused`, `test_a_less_frequent_schedule_than_the_ceiling_is_always_allowed` | ✅ |
| **Plan upgrade / downgrade** — both take effect on the very next request. These caught a real defect: the resolver memoized by user id, so an upgrade did *not* take effect for a resolver instance that had already answered once (CONTEXT.md §40) | `EntitlementTest::test_an_upgrade_takes_effect_immediately`, `test_a_downgrade_takes_effect_immediately_too` | ✅ |
| **Expired plan** — an expired, cancelled, or deactivated-plan subscription falls back to the default plan flagged `is_downgraded`; one still inside its paid period does not; a user with no subscription row at all resolves to null (an anomaly, not a lifecycle state) | `EntitlementTest` — 5 subscription-state tests | ✅ |
| Guest tier — read from `config/entitlements.php`, not a `plans` row, and grants no on/off features | `EntitlementTest::test_the_guest_tier_comes_from_config_not_a_plan_row` | ✅ |
| **API bypass** — plan limits cannot be raised through the request payload (`max_pages`, `max_websites`, `monthly_audit_limit`, `plan_id` are all inert), and a non-admin cannot edit a plan | `EntitlementTest::test_limits_cannot_be_raised_through_the_request_payload`, `test_a_non_admin_cannot_edit_plan_limits` | ✅ |
| The entitlements endpoint describes the caller's plan for both a subscriber and a guest, and distinguishes "not in your plan" from "nobody has this yet" | `EntitlementTest::test_the_entitlements_endpoint_describes_the_callers_plan`, `test_the_entitlements_endpoint_works_for_a_guest` | ✅ |
| **Cross-phase review regressions** (Phase 1-3 review pass, CONTEXT.md §28) — a terminal audit closes its running `crawl_jobs` row, so failed/cancelled/recovered audits stop leaking the *global* crawl slot that `LimitConcurrentCrawls` counts (after 5, crawling stopped system-wide); a crawl job that finished normally keeps its real `completed_at` | `AuditPipelineIntegrityTest::test_cancelling_an_audit_closes_its_running_crawl_job`, `test_failing_an_audit_closes_its_running_crawl_job`, `test_stuck_audit_recovery_also_frees_the_global_crawl_slot`, `test_completing_an_audit_leaves_its_already_completed_crawl_job_alone` | ✅ |
| Cross-phase review — two page jobs finishing together dispatch analysis **once**, not twice (an unconditional crawl-job update let every audit module run and write its issues twice) | `AuditPipelineIntegrityTest::test_two_page_jobs_finishing_together_dispatch_analysis_only_once` (asserts exactly 8 module jobs, not 16) | ✅ |
| Cross-phase review — a generated report carries its `audit_date`; §38 moved the completion stamp after report generation, so `ReportBuilder` was reading a still-null `completed_at` | `AuditPipelineIntegrityTest::test_a_generated_report_records_the_audit_date` | ✅ |
| Cross-phase review — scheduled audits go through the concurrency limiter rather than creating audits directly, and a skipped schedule still advances `next_run_at` (or the scheduler retries it every tick for as long as the blocking audit runs) | `AuditPipelineIntegrityTest::test_a_scheduled_audit_does_not_exceed_the_plans_concurrent_limit`, `test_a_skipped_schedule_still_moves_its_next_run_forward` | ✅ |
| Cross-phase review — an anonymous audit can only be cancelled from the IP that created it. Reading one by ID is an accepted gap (reading changes nothing); cancelling destroys a running audit, so ID enumeration must not be able to kill every guest's audit | `AuditPipelineIntegrityTest::test_a_stranger_cannot_cancel_an_anonymous_audit_from_another_ip`, `test_the_guest_who_started_an_anonymous_audit_can_still_cancel_it` | ✅ |
| Cross-phase review — a failing audit module is retried before its category is written off (`$tries = 2` was dead code: the first throw was swallowed and the category permanently marked unassessable), and is written off on the final attempt | `AuditReliabilityTest::test_a_module_failure_is_retried_before_the_category_is_written_off`, `test_a_module_failure_on_the_final_attempt_writes_off_the_category` — the `sync` connection counts as final, since it runs inline and never retries despite reporting `attempts() = 1` | ✅ |
| **Audit lifecycle state machine** — every non-terminal state holds a concurrent slot and every terminal one releases it; a terminal audit can never be transitioned again (a worker finishing after a cancellation must not resurrect it); terminal transitions stamp `terminated_at`; failures record the stage they died in | `AuditLifecycleTest::test_every_non_terminal_state_holds_a_slot_and_every_terminal_state_releases_it`, `test_a_terminal_audit_cannot_be_transitioned_again`, `test_a_terminal_transition_stamps_terminated_at`, `test_failing_an_audit_records_the_stage_it_died_in` | ✅ |
| **One active audit limit / multiple active audits per plan** — a plan allowing 1 refuses the second; a plan allowing 3 accepts up to 3 and refuses the 4th; every one of the five active states consumes a slot; a planless user has 0 slots; one user's audits never consume another's | `AuditLifecycleTest::test_a_plan_allowing_one_active_audit_refuses_a_second`, `test_a_plan_allowing_several_active_audits_permits_up_to_its_limit`, `test_each_active_state_counts_toward_the_limit`, `test_a_user_with_no_subscription_has_no_slots_at_all`, `test_one_users_running_audits_do_not_consume_another_users_slots` | ✅ |
| Guest active limit — read from config, counted through `usage_logs.ip_address` (the audit row carries no IP), and scoped to that one guest | `AuditLifecycleTest::test_the_anonymous_active_limit_comes_from_config`, `test_a_guests_running_audit_consumes_their_slot` | ✅ |
| **Completed / failed / cancelled audits release their slot** — asserted the same way for all three, since release is a consequence of the status being terminal rather than a separate step any path could forget | `AuditLifecycleTest::test_a_completed_audit_releases_its_slot`, `test_a_failed_audit_releases_its_slot`, `test_a_cancelled_audit_releases_its_slot` | ✅ |
| **Race condition / concurrent requests** — holding the limiter's own lock makes the request refuse (proving the guard is actually acquired, and acquired non-blocking); the guarded callback never runs at capacity (creation must never happen, not happen-then-roll-back); a burst of 6 requests against a limit of 2 creates exactly 2 | `AuditLifecycleTest::test_the_slot_check_and_the_insert_happen_under_one_lock`, `test_the_guarded_callback_never_runs_when_the_owner_is_at_capacity`, `test_a_burst_of_requests_creates_no_more_audits_than_the_plan_allows` — the burst test is sequential (PHPUnit is single-threaded, so it cannot reproduce a true race); the lock test covers the mechanism that protects the parallel case | ✅ |
| Cancellation — the owner can cancel a running audit; a completed one cannot be cancelled (that would destroy a real result); another user gets 404, not 403; a second cancel is refused; a crawl finishing after cancellation cannot drag the audit back into `analyzing` | `AuditLifecycleTest` — 5 cancellation tests | ✅ |
| Status endpoint carries the lifecycle flags the UI needs (`status_label`, `is_active`, `is_cancellable`) | `AuditLifecycleTest::test_the_status_endpoint_reports_the_lifecycle_flags_the_ui_needs` | ✅ |
| **Snapshot immutability** — a written snapshot cannot be updated, cannot be changed by attribute assignment + save, cannot be deleted (delete-and-re-run is a subtler rewrite), keeps its stored content after a rejected rewrite, and is not affected by a later change to its website; rebuilding returns the existing row rather than re-deriving (a report-job retry must not alter it) | `AuditSnapshotTest` — 6 immutability tests | ✅ |
| Snapshot contents — every spec field present (versions, AI provider/model, pages analyzed, service results, scores, issues, recommendations, screenshots, performance); the scoring *weights* stored, not just their version; services that did not run recorded as such (an omitted category would read as "no problems here"); Not Applicable distinguishable from "did not run"; recommendations only from issues that carry one; a failed AI call not reported as the answering provider | `AuditSnapshotTest` — 7 content tests | ✅ |
| The report pipeline writes a snapshot and only then marks the audit completed, so `completed` means "there is something to look at" | `AuditSnapshotTest::test_the_report_pipeline_writes_a_snapshot_when_an_audit_completes` | ✅ |
| **Crawler failures** — a crawler exception fails the audit with the stage recorded and releases the slot | `AuditReliabilityTest::test_a_crawler_failure_fails_the_audit_and_records_the_stage`, `test_a_crawler_failure_releases_the_owners_slot` | ✅ |
| **Retry exhaustion** — every pipeline job's `failed()` handler marks the audit failed with the right stage. Without these an audit sits active forever holding a slot nothing can release | `AuditReliabilityTest::test_a_job_the_queue_gives_up_on_still_fails_the_audit`, `test_every_pipeline_job_marks_the_audit_failed_when_the_queue_gives_up` | ✅ |
| **Partial failures** — one module throwing does not fail the audit: the category is recorded unassessable (`score: null`, `is_applicable: false`, `breakdown.error`) so the pipeline can still finish and the other categories' findings survive. Covers both the in-handler catch and the queue-gave-up path | `AuditReliabilityTest::test_one_failing_module_does_not_fail_the_whole_audit`, `test_a_module_the_queue_gives_up_on_also_records_an_unassessable_score` | ✅ |
| Cancellation stops work — a cancelled audit's crawl job never calls the orchestrator at all; its report job writes neither report nor snapshot | `AuditReliabilityTest::test_a_cancelled_audit_stops_the_crawl_job_before_it_does_anything`, `test_a_cancelled_audit_stops_the_report_job` | ✅ |
| **Stuck audit recovery** — an audit with no progress past the threshold is failed and its slot freed; a merely-slow audit is left alone; a finished audit is never touched; rows predating `progress_at` fall back to `updated_at`; `--dry-run` changes nothing; `--minutes` overrides the threshold; a `touch()` keeps an audit out of recovery | `AuditReliabilityTest` — 7 recovery tests | ✅ |
| **Website records — duplicate prevention** (the Phase 2 headline: auditing one site repeatedly must never produce a second card) | `WebsiteApiTest::test_auditing_the_same_domain_twice_reuses_one_website_record` (1 website, 2 audits), `test_www_and_bare_domain_are_the_same_website`, `test_url_spelling_differences_do_not_create_a_second_website` (5 spellings — case, default port, path, DNS root dot, `www` + query — 1 website, 5 audits), `test_a_different_domain_gets_its_own_website`, `test_a_repeat_audit_updates_the_primary_url_but_not_the_user_edited_profile` | ✅ |
| Website records — the unique index, not just the resolver's read-then-write, is what prevents duplicates under a race | `WebsiteApiTest::test_the_database_itself_rejects_a_duplicate_domain_for_one_user` | ✅ |
| Website records — scoped per user, not globally; anonymous submissions deliberately **not** deduped (sharing a row would hand every guest the audit history of every other guest for that domain) | `WebsiteApiTest::test_two_users_auditing_the_same_domain_get_separate_website_records`, `test_anonymous_audits_of_the_same_domain_do_not_share_a_website_record` | ✅ |
| Website listing — one card per domain carrying every field the card shows (name, domain, last audit date, overall + 5 service scores, pages analyzed, critical/high counts, audit count) | `WebsiteApiTest::test_the_listing_shows_one_card_per_domain_with_its_audit_count`, `test_the_card_reports_the_latest_audits_scores_and_issue_counts` (counts come from the newest audit only, never summed across history) | ✅ |
| Latest audit — a card keeps the last *completed* scores while a re-audit is running, reporting the in-flight run alongside rather than instead; a website whose only audit failed reports no scores at all | `WebsiteApiTest::test_a_card_keeps_the_last_completed_scores_while_a_re_audit_is_running`, `test_a_website_with_only_a_failed_audit_reports_no_scores` | ✅ |
| Website detail — every spec section present, addressable by domain or id, with spelling variants (`WWW.Example.COM`, `example.com.`) resolving to the one record | `WebsiteApiTest::test_detail_can_be_addressed_by_domain_and_returns_every_section` (asserts all 9 sections), `test_detail_can_also_be_addressed_by_id_and_by_domain_spelling_variants` | ✅ |
| Multiple audit history — trends are chronological and exclude unfinished runs; history includes the failed runs the trends omit (a failed run on a trend line reads as a real cliff) | `WebsiteApiTest::test_score_trends_are_chronological_and_exclude_unfinished_audits`, `test_audit_history_includes_failed_runs_that_the_trends_exclude`, `test_issue_trends_report_counts_per_audit_over_time` | ✅ |
| Website detail — a category no audit ever scored is **omitted** from the trends rather than zero-filled (a flat line at zero reads as "measured and terrible", not "never ran") | `WebsiteApiTest::test_a_category_no_audit_scored_is_absent_from_the_trends_rather_than_flat_at_zero` | ✅ |
| Website detail — `overall` is excluded from `service_scores`. Regression for a bug found **in the browser, not by the suite**: `audit_scores.category` is enum-cast, so a `=== 'overall'` filter silently never matches and the overall score rendered as a seventh service | `WebsiteApiTest::test_service_scores_exclude_the_overall_score` | ✅ |
| Website detail — recommendations come only from issues that actually carry one, never a message reworded to look like advice | `WebsiteApiTest::test_recommendations_only_include_issues_that_carry_one` | ✅ |
| **Website ownership / unauthorized access** — every endpoint requires auth; another user's website 404s (never 403, which would confirm the record exists and turn the endpoint into a way to enumerate other users' domains) by id *and* by domain; it cannot be edited; an anonymous website is invisible to every logged-in user | `WebsiteApiTest::test_website_endpoints_require_authentication`, `test_another_users_website_is_not_found_by_id`, `test_another_users_website_is_not_found_by_domain`, `test_another_users_website_cannot_be_edited`, `test_an_anonymous_website_is_not_visible_to_any_logged_in_user` | ✅ |
| Website profile — the owner can edit every profile field (country upper-cased, competitors round-tripped); identity fields (`domain`, `normalized_domain`, `primary_url`, `user_id`) are ignored when sent, so one record can never claim another's identity; validation rejects bad country/budget/competitors | `WebsiteApiTest::test_the_owner_can_edit_the_website_profile`, `test_identity_fields_cannot_be_rewritten_through_the_profile_endpoint`, `test_profile_validation_rejects_bad_values` | ✅ |
| **The websites migration** — the only code in the project that deletes rows from a live DB, and the only code the rest of the suite structurally cannot reach (every other test starts from a fresh schema with no duplicates, so the merge path runs zero times). Each test rolls the migration back, recreates the pre-migration state, and runs it forward | `WebsiteDeduplicationMigrationTest` (9 tests): column rename + new fields; backfill of `normalized_domain`/`name` leaving `domain` untouched; duplicates merged onto the *earliest* row with `audits` and `usage_logs` repointed; two users' same-domain rows not merged together; anonymous rows skipped; a duplicate's `audit_schedule` moved when the survivor has none and dropped when it does (the table's own unique `(user_id, website_id)` would otherwise collide); the unique index in place afterwards | ✅ |
| Audit entry point — hostile URLs (the whole spec sweep: malformed, `javascript:`/`file:`/`data:`/`ftp:`/`gopher:`, localhost in four spellings, loopback IPv4/IPv6, private IPv4/IPv6, link-local + AWS/GCP metadata + NAT64-wrapped metadata, decimal/octal/hex IP forms, credentials, non-web ports, shell/SQL/XSS/CRLF/NUL payloads) | `AuditUrlSecurityTest::test_unsafe_url_is_rejected_by_the_api` — a 43-case data provider driven **through the HTTP API**, not the validator class, so it proves the controller actually consults the validator. Each case asserts 422 *and* zero `websites`, `audits` and `usage_logs` rows: a rejection must leave nothing behind to be picked up later and must not burn the caller's free audit | ✅ |
| Audit entry point — the URL that passed validation is the URL that gets stored | `AuditUrlSecurityTest::test_the_stored_url_is_the_normalized_one` (`HTTPS://Example.COM:443/Pricing#plans` → `https://example.com/Pricing`), `test_the_stored_domain_is_lowercased_and_stripped_of_the_dns_root_dot` — closes the gap where a filter checks one string and the crawler fetches another | ✅ |
| Guest cannot bypass audit restrictions through the API | `AuditUrlSecurityTest::test_guest_cannot_raise_its_page_limit_through_the_payload` (`max_pages` in the body is ignored, plan/config value wins), `test_guest_cannot_assign_itself_a_user_id_through_the_payload`, `test_guest_cannot_set_the_audit_status_through_the_payload`, `test_guest_second_audit_is_refused_and_creates_nothing`, `test_guest_cannot_reuse_its_free_audit_by_varying_the_url_spelling`, `test_audit_creation_is_rate_limited` | ✅ |
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
| Per-service report endpoints | Owner sees full reports incl. page findings and screenshots; a missing screenshot reads as `null`, never a fabricated path | `ServiceReportApiTest::test_owner_sees_full_service_reports_including_pages_and_screenshots` | ✅ |
| Per-service report endpoints | Anonymous viewers keep scores/counts (proving the audit is real) but lose page findings, low-severity issues, fix text, evidence and the affected-URL list — with honest `hidden_*` counts | `ServiceReportApiTest::test_anonymous_viewer_gets_scores_but_not_page_findings_or_low_severity_issues`, `test_anonymous_issue_detail_is_redacted_but_not_misrepresented` | ✅ |
| Per-service report endpoints | A service that never ran is a 404, not an empty shell; Not Applicable is reported as such rather than scored | `ServiceReportApiTest::test_a_service_that_never_ran_is_404_not_an_empty_report`, `test_a_not_applicable_service_is_reported_as_such_not_scored` | ✅ |
| Page explorer | Requires full access (403 anonymous); returns cross-service issue counts per page | `ServiceReportApiTest::test_page_explorer_requires_full_access` | ✅ |
| Per-service PDF | Renders a real `%PDF-` binary with the right filename for a plan with `pdf_export`; 403 anonymous; 404 for a service that never ran | `ServiceReportApiTest::test_service_pdf_renders_a_real_pdf_for_a_plan_with_pdf_export`, `test_service_pdf_is_refused_for_an_anonymous_viewer`, `test_service_pdf_404s_for_a_service_that_never_ran` | ✅ |

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
- **Per-service report manual verification** (this chunk, CONTEXT.md
  §35): the new report driven in a real browser against **audit #11**, a
  genuine 25-page crawl of a live site, logged in as its owner. Verified:
  the service navigation lists only the six services that actually ran;
  score cards show real scores (Performance 61, Accessibility 26, SEO 68)
  with Ecommerce as N/A and its detection reason; the SEO report renders
  real categories (Metadata 3 / Headings 1 / Images 1 / Canonical 1),
  severity tiles, quick wins, site-wide badges, real affected-page lists
  with "…and 15 more", and a working "Technical details" toggle; the page
  explorer lists all 25 real pages sorted worst-first with correct
  per-page critical/high counts; the mobile screenshot loads at exactly
  390×844 (the configured viewport) and is the default view. **Two real
  bugs found this way, neither catchable by the unit suite**: the CRO
  "100 — good with AI down" manufactured score, and screenshots 403ing
  because `toPublicPath()` still stored absolute Windows paths (item 17's
  fix was wrong and had shipped unverified). A third, non-code issue was
  also found: the checkout needs `php artisan storage:link`, without
  which every screenshot 403s regardless of the stored path. One console
  error observed (`isDownloading is not defined`) was traced to stale
  Vite HMR state, not a defect — confirmed by hard-reloading and then
  exercising that exact code path (the button renders with
  `disabled=false`, a value computed from that variable).
- **Performance & product audit manual verification** (§36): two live
  audits driven end to end. Audit #18 (6 real pages) produced mobile 36 /
  desktop 51 with mobile LCP 14.06s marked Poor beside desktop 2.28s
  marked Good — the mobile/desktop separation visible in the actual UI,
  not just the database — with mobile labelled "Primary", plain-English
  metric explanations, and published thresholds ("Good is under 2.5s ·
  measured by Lighthouse"). The resource panel rendered a real
  31.0 MB / 707-request breakdown (JavaScript 14.9 MB across 444
  requests, third-party 9.6 MB), and the per-page table showed a dash for
  the five pages Lighthouse never ran against rather than borrowing the
  homepage's numbers. Screenshots loaded at exactly 390×844 with mobile
  as the default view, and no failing resource requests were recorded.
  Audit #19 against a real store confirmed the negative case: the
  catalogue listing page was correctly *not* classified as a product
  page. Two real gaps were found this way (the taxonomy catch-all and the
  missing basket/bag CTA keywords) — see Known gaps item 19.

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
18. Every audit service was crushed into one combined report, so no
    service had its own score/findings/recommendations view, issues had
    no user-facing category, and page-level findings and screenshots were
    unreachable from the UI — **closed**: the per-service report
    architecture in CONTEXT.md §35 (`IssueTaxonomy`,
    `ServiceReportBuilder`, `ServiceReportVisibilityFilter`,
    `PageExplorerBuilder`, `ServiceReportPdfService`), 4 new endpoints,
    and a frontend shell (`AuditReportPage`) with a shared
    `ServiceReportView`, `IssueCard` progressive disclosure, mobile-first
    `ScreenshotViewer` and `PageExplorer`. 26 new PHP tests (354 → 380):
    `ServiceReportBuilderTest` (12), `ServiceReportApiTest` (10),
    `ScreenshotPathTest` (4). Two real bugs found by running the new
    model against a **live 25-page audit** rather than fixtures:
    (a) CRO reported "100 — good" with zero issues while its AI provider
    was unavailable — a manufactured score from an assessment that mostly
    never ran, now `confidence: partial` with a `coverage_note` naming
    what was skipped; (b) `CrawlOrchestrator::toPublicPath()` was *still*
    storing absolute Windows paths despite item 17 claiming that fix —
    the incoming path comes from the Node crawler (forward slashes) while
    `$disk->path('')` is OS-native (backslashes), so `str_starts_with()`
    never matched. `ScreenshotPathTest` reproduces the mismatch by
    passing a backslash base path explicitly, which `Storage::fake()`
    structurally cannot do (it is forward-slash on both sides) — which is
    exactly why item 17's fix shipped unverified. A data-only migration
    repairs already-written rows. Still open: the deep performance report
    (Core Web Vitals / opportunities / diagnostics), because the crawler
    does not yet collect per-resource JS/CSS/image/font/third-party
    metrics to populate it — building that UI first would mean inventing
    numbers (CONTEXT.md §35). **Closed by item 19.**
19. The performance report had no real measurements behind it and
    `product_auditor` had no audit module — **closed**: the crawler now
    records every response's real transferred bytes by resource type,
    PageSpeed runs mobile and desktop as two independent measurements
    stored in a new `page_performance_metrics` table (unique on
    page+source+strategy, so a desktop number can never be read as
    mobile), and `ProductAuditModule`/`ProductPageDetector` audit real
    product pages. See CONTEXT.md §36. 35 new PHP tests (380 -> 415):
    `PerformanceAuditModuleTest` additions (7), `IssueTaxonomyTest` (19),
    `ProductAuditModuleTest` (9). Verified against **two live audits**: a
    6-page real-site crawl produced mobile 36 / desktop 51 with mobile
    LCP 14.06s (poor) vs desktop 2.28s (good) — the strategies
    demonstrably not mixed — plus a real 31MB/707-request resource
    breakdown, while pages without a Lighthouse run correctly showed a
    dash rather than borrowing another page's numbers. Two real gaps
    found this way: the 15 new performance issue codes were all landing
    in the "Other Performance" catch-all because the taxonomy had not
    been extended (now covered by a data-provider test over every code),
    and the crawler's CTA keyword list omitted `add to basket`/`add to
    bag`, so UK/EU product pages yielded no purchase CTA and could never
    be detected. Still open: Lighthouse opportunities are collected but
    not yet surfaced as their own UI section, and the basket-wording fix
    is not verified against a live basket button (the validation store
    has no working cart element).

20. The accessibility module reported axe rules and little else — no
    check grouping, no WCAG citation, no failing-element evidence, no
    count of what passed, and no statement anywhere that an automated
    score is not an ADA verdict. **Closed**: see CONTEXT.md §43. 29 new
    PHP tests: `DeepAccessibilityTest` (18) covers the sixteen check
    areas, the unmapped-rule fallback, WCAG parsing including the
    best-practice case with no criterion, strictest-level-wins, the nine
    issue fields, passes vs incompletes, scanned-vs-crawled coverage, and
    that every area carries a real explanation;
    `AccessibilityReportApiTest` (11) covers the eight report fields, the
    partial-scan flag, the owner's evidence, and that the disclaimer
    survives the owner view, the anonymous view, the combined report, the
    restricted combined report and the service PDF. Two assertions are
    deliberately adversarial: one fails if the disclaimer ever regains a
    compliance or certification claim, the other if the PDF ever labels a
    check area "Compliant". Not covered: the disclaimer's presence in the
    React tree is asserted only by construction — the frontend has no test
    runner — and no live axe run has yet been re-crawled through the new
    node/pass/incomplete capture, so the crawler side is verified by unit
    fixtures rather than a real page.

21. The SEO module ran seven checks, had no redirect report, no schema
    validation, no social-card analysis, no robots/sitemap reporting, and
    no way to say a check had not run. **Closed**: see CONTEXT.md §44. 45
    new PHP tests: `DeepSeoTest` (29) covers indexability across markup,
    headers, canonicals and status; robots/sitemap three-state reporting;
    redirect chains, loops and the unknown-status case; broken links,
    orphans, nofollow and www/subdomain link classification; social-card
    fallbacks; schema subtypes, `@graph` nesting and empty-property
    handling; heading skips; and the coverage list.
    `SeoReportApiTest` (16) covers the four report views, the site-file
    status trio, the PDF rendering, and every backlink state. Existing
    fixtures were updated rather than the checks weakened: the
    "perfect page" and pipeline fixtures now carry real Organization
    schema and a real robots.txt/sitemap, because under the new check set
    they were not actually clean sites.

    Three assertions are deliberately adversarial: a single redirect
    (`http`→`https`) must not be called a chain; a link to an uncrawled
    page must never be called broken; and a failing backlink provider must
    report no data rather than zero.

    **A real bug caught by the tests, not by review:** redirect-loop
    detection initially reused the link graph's URL normalization, which
    drops the scheme — so `http://x/a → https://x/a` looked like a URL
    redirecting to itself and would have been reported as a *critical*
    redirect loop on essentially every HTTPS site. Fixed by keeping the
    scheme in `RedirectAnalyzer` only.

    Not covered: no live crawl has yet exercised the new extraction
    (robots meta, og/twitter maps, per-hop redirect statuses) against a
    real site — the crawler side is verified by unit fixtures and
    `node --check` only. The frontend `SeoReportView` is verified by
    typecheck and construction, as the frontend still has no test runner.
    No non-null backlink provider implementation exists, so the
    `available` path is exercised only by a test double.

22. Cross-phase review of Phases 1-7 (CONTEXT.md §28). Seven defects
    found and fixed; 9 new PHP tests (797 -> 806), each written to fail
    against the old code.

    `LapsedSubscriptionAccessTest` (7) covers the two that mattered most:
    `FeatureAccessResolver` and `AuditSchedulerService` both read
    `$user->plan()` instead of going through `EntitlementResolver`, so an
    expired subscription kept its paid feature access and its paid crawl
    budget indefinitely. Both were in unwatched paths — a resolver
    consulted deep inside audit setup, and a background scheduler — so
    nothing in any report would have shown it. The tests pin the expired,
    withdrawn-plan, active and guest cases separately, because those four
    resolve through different branches.

    `IssueTaxonomyTest` gains a 25-case SEO data provider mirroring the
    performance one, plus an assertion that the taxonomy and the §43
    accessibility rollup group an axe rule *the same way* — they had
    drifted into two maps, so one report could group a rule two ways.

    `ServiceReportApiTest` gains a header assertion that caught every
    per-service PDF shipping with a blank site URL (`$website->url` vs
    `primary_url`, renamed in §37). Worth noting how it was missed: the
    original assertion checked the response was a real PDF, which it was.
    The replacement matches the header block by regex — a plain
    substring check on the domain passes even when the header is empty,
    which I verified by reverting the fix and watching the loose version
    still pass.

    `SeoReportApiTest` gains a cache test proving a successful backlink
    answer is reused while a failure is not, so a provider outage cannot
    be pinned in place for the cache TTL.

    Not covered: the fixes were verified on SQLite only — the test suite
    has no MySQL run, and MySQL was down for this pass, so no migration
    has been applied to the real database since Phase 5.

23. The performance audit reported measurements but no Lighthouse
    findings: opportunities were extracted and thrown away, diagnostics
    were never extracted at all, Caching and Compression were named
    categories with no checks behind them, and INP was simply absent.
    **Closed**: see CONTEXT.md §45. 28 new PHP tests (808 -> 836).

    `ProfessionalPerformanceTest` (18) holds the four stated rules.
    Several are written as traps rather than confirmations: a diagnostic
    must have no `savings_ms` key at all (not a zero one); an empty
    opportunity list must report null savings, not 0; a page measured
    only on desktop must report `findings.mobile` as null rather than an
    empty set; a URL with no CrUX record must carry the *reason* INP is
    missing, at every visibility tier, because a silently absent metric
    reads as an absent problem.

    `PageSpeedFindingsTest` (10) drives the extraction through faked
    Lighthouse responses — the riskiest input in the module, since the
    shape is deeply nested, varies by Lighthouse version, and every
    number in it reaches a site owner. Covers the opportunity/diagnostic
    split, passing and informative audits being neither, savings-ordered
    output, unrecognised audit ids surfacing as `other` rather than
    disappearing, summary rows without URLs not becoming resources, CrUX
    extraction including the 100x CLS scaling, and a null category score
    staying null.

    Two Blade-specific bugs were found while adding the PDF section, both
    silent: an inline `@if/@else` inside a `<td>` and a literal `(` inside
    a `@foreach` array literal each ended the directive early, and the
    resulting parse error surfaced only as a failed PDF render. Both are
    now avoided in the template with a note saying why.

    Not covered: no live PageSpeed run has exercised the new extraction —
    it is verified against hand-built fixtures of the documented response
    shape, not against Google's actual output, and no real API key has
    been used from this codebase (§29). The crawler's caching and
    compression summaries are likewise unit-verified only; no live crawl
    has produced one.

24. The audit had no notion of what kind of site it was looking at, so
    every module ran against every site, and the CRO and ecommerce
    assessments were almost entirely AI-driven — three deterministic
    checks between them. **Closed**: see CONTEXT.md §46. 46 new PHP tests
    (836 -> 868, plus 14 taxonomy cases).

    `SiteClassificationTest` (16) covers all eight types and, more
    importantly, the cases where the classifier must decline: equal
    evidence for a shop and a blog reports `Other` rather than picking;
    a one-page crawl of a multi-page site is never called a landing page;
    an empty crawl says why. It also pins the three stated rules — a blog
    with a checkout link gets no ecommerce score, the skip reason is
    written in the owner's terms, and a local business is still allowed
    to be audited as a shop.

    `ConversionSignalsTest` (16) covers the nine CRO and eight ecommerce
    areas. Several are written as traps: "Learn more about pricing" must
    not be flagged as vague wording, a hidden CSRF field must not count as
    form friction, one destination linked twice must count once, a returns
    policy linked as `/p/17` by its text must be found, and a blog must
    not be told to add a phone number.

    **A bug found while wiring this up, not by a test:**
    `AuditModuleResult` had carried `notApplicableReason` and
    `detectionEvidence` since §34 and nothing ever persisted them — the
    report substituted one generic sentence for every skipped service. The
    phase's own "explain why a service was skipped" rule was therefore
    unmeetable until those columns existed.

    Two fixtures were updated rather than the checks weakened: the
    conversion unit fixture and the pipeline fixture were bare rows that,
    under the new checks, are genuinely pages with no way to act, no way
    to get in touch and no evidence anyone has trusted them.

    Not covered: classification has not been run against a live crawl of a
    real site of each type — the eight types are verified against
    hand-built fixtures, and the weights are a first pass that real audits
    will likely need to correct. `Other` is the safe failure mode, so a
    miscalibration under-claims rather than mislabels.

25. The report interface was mostly already built (§35): seven service
    reports, a page explorer with search/filter/sort, screenshots,
    grouped categories, severity styling. Three things were missing, and
    all three were backend or cross-cutting rather than layout. **Closed**:
    see CONTEXT.md §47. 9 new PHP tests (882 -> 891).

    `ReportInterfaceTest` (9) covers the per-page score — that it uses the
    same severity weights as the category scores, floors at zero, and is
    computed per page — and the "why it matters" text: that every mapped
    code has a substantial one, that an unmapped code falls back to its
    category rather than to silence, that accessibility delegates to the
    §43 check definitions rather than duplicating them, and that a free
    viewer keeps the reason while losing the fix.

    The theme system was **verified in a browser rather than by
    inspection**, because it is the kind of change that typechecks
    perfectly and still does nothing: confirmed that the browser
    preference resolves to dark and sets the class, `color-scheme` and
    body background together; that an explicit light override survives a
    reload; and that at 375px the header does not overflow horizontally.

    Not covered: the report page itself was not rendered live, because
    MySQL is down and the API returns nothing — the report components are
    verified by typecheck, production build, and the backend tests behind
    their data. The consultation form's submit path has never been
    exercised against the live `/api/leads` endpoint for the same reason.
    There is still no frontend test runner (§29), so no component has an
    automated test.

26. Screenshots were world-readable, PDF export bypassed plan visibility,
    and white label had been a settable no-op since §40. **Closed**: see
    CONTEXT.md §48. 20 new PHP tests (891 -> 911).

    `ScreenshotsAndBrandingTest` (20) covers the access model, and the
    important tests are the negative ones: an unsigned URL is refused, a
    *genuinely signed* URL is useless to another user and to an anonymous
    visitor (a forwarded report link, not a forged one), a missing file
    reads as 404 rather than erroring, and a capture from before the disk
    moved is still served. Branding covers the plan gate, the lapsed-plan
    case, the empty-profile case, an anonymous audit, logo inlining, an
    oversized logo being dropped rather than failing the export, and that
    saving is allowed on a plan that cannot yet use it.

    **A bug the first PDF test caught immediately:** applying the
    visibility filter to the combined PDF crashed it, because
    `pdf.blade.php` read `$content['pages']` unguarded and the restricted
    payload omits that key entirely rather than emptying it. Filtering and
    rendering had never met before.

    **A design consequence found while wiring the frontend, not by a
    test:** an `<img src>` cannot send an Authorization header, so keeping
    the ownership check meant screenshots could not be loaded by a plain
    image tag at all. Rather than weaken the check to signature-only —
    which is the exact property that makes a forwarded report link safe —
    the images are fetched through the authenticated client and rendered
    from object URLs.

    Not covered: no live browser has loaded a signed screenshot, since
    MySQL is down and the API cannot serve a report; `AuthenticatedImage`
    is verified by typecheck and build only. No PDF has been opened and
    read by a human to confirm the branding header renders as intended —
    the templates are asserted on as HTML, not as rendered pages.

27. Cross-phase review of Phases 1-11 (CONTEXT.md §28), and the first
    end-to-end run of the whole stack. 1 new PHP test (911 -> 912) plus
    four fixes that no unit test could have caught.

    **What the end-to-end run proved**, none of which the suite covers:
    all ten outstanding migrations apply cleanly to a fresh database in
    order; a seeded audit serves all seven services over HTTP with the
    N/A reasons intact; a signed screenshot returns 200 to its owner, 404
    to an anonymous caller and 403 unsigned; a real 1.1MB PDF exports; and
    `AuthenticatedImage` genuinely loads a screenshot in a browser —
    fetched with the auth header, converted to a blob, rendered. That last
    one was listed as unverified after §48 and is the reason the run was
    worth doing.

    **The bug it found:** the screenshot route inherited the audit group's
    `throttle:10,1`, so a report view firing fifty image requests got 429
    on all but ten. Pinned by a test that loads one screenshot 25 times.

    Three more found by inspection during the same pass: the site
    classification was rendered only in a component the live report page
    does not use; `ServiceKey` omitted `product_auditor`; and every
    environment variable added since §43 was undocumented.

    Still not covered: no live crawl has ever run in this environment
    (Playwright and network), so the crawler's own new extraction —
    caching/compression headers, per-hop redirect statuses, robots and
    social meta — remains verified by fixtures only. MySQL has been down
    throughout, so nothing has been applied to the real database; the
    migration run was against SQLite. There is still no frontend test
    runner, so the UI is verified by typecheck, build and this manual
    browser pass rather than automated tests.

28. Crawling stopped working in production. Two causes, one code and one
    operational. **Closed**: see CONTEXT.md §28/§49. 4 new PHP tests
    (912 -> 916).

    `FailureRecordingTest` (4) pins the code bug: `error_message` was
    varchar(191) while the writer truncated to 250, so a long failure
    message made the *failure-recording write* throw, killing the job and
    stranding the audit in `crawling` forever.

    One of those four is the only one that can actually catch a
    regression: **SQLite ignores varchar lengths**, so the other three
    pass just as happily against the broken schema. Asserting the declared
    column *type* is TEXT is what would catch a revert to `string()`. Worth
    remembering for any width-related bug — this suite cannot reproduce
    one on its default connection.

    The existing `DatabaseMigrationTest` immediately caught a second
    mistake in the fix: the migration's `down()` used `LEFT()`, which is
    MySQL-only, so the rollback failed on SQLite.

    **Verified against the live MySQL database, not fixtures:** a real
    crawl of example.com now completes end to end — classified
    (landing_page, 100% confidence), nine category scores with ecommerce
    and product correctly N/A, 16 issues, both screenshots, site signals,
    and crawler metrics carrying the caching and compression summaries
    §45 added. That is the first live confirmation of the crawler-side
    work from §44-§45, which until now had only fixture coverage.

    Still not covered: the underlying crawl failure was a stale
    `queue:work` process, which no test can catch — a worker holds its
    booted code and environment until restarted.

29. Password-protected report sharing. **New**: see CONTEXT.md §50.
    37 new PHP tests (916 -> 953), covering all eleven cases the spec
    names plus the audit log.

    `ReportSharingTest` (37). Almost every test is an attack, because the
    feature hands a URL to someone with no account: a revoked link must
    kill an *already-unlocked* session (not merely stop new ones); an
    access token for one share must not unlock another (IDOR); an unknown
    token and a revoked one must be byte-identical in response; a share of
    one audit must not read another audit's screenshots; the access token
    must grant no elevation on the owner's own endpoints; and `allow_pdf`
    must not permit an export the owner's plan forbids.

    One test is written as a comparison rather than an assertion of
    failure: presenting a share token to `/report/services` is checked
    against a *plain anonymous request*, because that route already
    answers anonymous callers with the free preview (§35). Asserting an
    error there would have encoded a misunderstanding of existing
    behaviour as a security requirement.

    **Verified over real HTTP against a running server**, not only in the
    test harness: create → lock screen leaks nothing but branding →
    wrong password 422 → five failures then a *correct* password 429 →
    unlock → report, 881KB PDF, and screenshot all served → revoke →
    already-open session 401 on its next request. Owner status showed
    1 view and 6 failed attempts with no token or hash in the payload,
    and the event log held all six event types with IPs.

    Two bugs in the tests themselves, both worth noting because both
    would have produced false confidence: the Sanctum guard-memoization
    trap (§28) made three tests pass as the owner when they meant to run
    anonymously, and an assertion that a 64-character hex token "does not
    contain the audit id" is meaningless when the id is `1`.

    Not covered: the frontend share dialog and lock screen are verified by
    typecheck and production build only — there is still no frontend test
    runner (§29) — and no browser has driven the unlock flow. The
    migrations have not been applied to MySQL, which was down again during
    this work.

30. Audit progress, skeleton UI and estimated duration. **New**: see
    CONTEXT.md §51. 17 new PHP tests (953 -> 970).

    `AuditProgressTest` (17). Most assert an *absence*, because the rule
    is "never show fake progress": no estimate before the first page
    completes, no current stage on a terminal audit, no service marked
    running while the crawl is still going, no scale warning on a small
    audit. The positive tests check that each figure moves only when
    something real happened — the screenshot stage advances because a
    screenshot row exists, the analysis stage advances because a score row
    was written, and a slower crawl produces a longer estimate than a fast
    one with identical page counts.

    **Verified in a browser against a running server**, with a seeded
    mid-crawl audit: 84s elapsed over 7 pages projected ~4 minutes
    remaining from 12 pages outstanding, and the panel rendered the stage,
    the four counters, the basis line, the large-site note, all twelve
    stages with correct done/current/pending markers, and the skeleton.

    **A pre-existing hazard this surfaced:** a plan with no `features`
    rows makes every audit module skip, producing an empty report
    silently. Not fixed here — the gating policy is a product decision —
    but the progress panel now states it rather than hiding the empty
    services card.

    Not covered: no automated frontend test exercises the panel (still no
    test runner, §29), and the estimate has never been checked against a
    real multi-minute crawl of a large site — the arithmetic is verified,
    the calibration of `seconds_per_service` is not.

31. Audit comparison, score trends and improvement tracking. **New**: see
    CONTEXT.md §52. 32 new PHP tests (970 -> 1002).

    `AuditComparisonTest` (26). The subject under test is not really
    arithmetic — subtracting two integers needs no coverage — it is the
    set of cases where subtracting them would produce a confident lie.
    Each of those has a test that fails loudly if the guard is removed:

    - A finding on a page the later audit did not crawl appears under
      `not_rechecked`, never `resolved`
      (`test_a_finding_on_a_page_that_was_not_recrawled_is_not_called_resolved`).
      Paired with the positive case, so the guard cannot be satisfied by
      simply never reporting anything as resolved.
    - A site-wide finding, which is tied to no page, is never excused that
      way (`test_a_site_wide_finding_is_never_excused_as_not_rechecked`).
    - A service that became Not Applicable reports `is_comparable: false`
      with its stored reason rather than a delta of -64
      (`test_a_service_that_stopped_applying_is_not_reported_as_a_collapse`),
      and never becomes an insight sentence.
    - The coverage caveat is asserted to be *first* in the insight list,
      not merely present — a caveat that changes how every other line
      reads is worthless underneath them.
    - The spec's own example sentence is asserted verbatim:
      `'Page Speed & Performance improved from 48 to 72 (+24 points).'`

    **Two real defects the tests caught**, both in code written minutes
    earlier and both invisible without a test:

    - `coverageNote()`'s "pages were added" branch was unreachable, because
      `is_like_for_like` only considered *dropped* pages. A re-crawl that
      reached more pages than before was reported as directly comparable
      while its "new issues" list was inflated by pages nobody had looked
      at on the first run.
    - Naming a `previous` audit from another website (or a failed one)
      returned "this is the first completed audit for this website" — a
      false statement whenever earlier audits exist. It is now a 404, and
      an explicitly named failed run is refused for the same reason
      `previousFor()` skips them: no scores means every service reports
      incomparable and every finding reports new.

    **Four more defects the green suite did not catch**, all found by
    running the finished class against three real audit pairs in the
    development database (`AuditComparison` over audits 18/11, 16/15,
    27/21) — a reminder that tests written from the same mental model as
    the code inherit its blind spots:

    - Services nobody ran were reported as "did not apply to the site",
      which asserts a finding about the site that was never made
      (`test_a_service_that_never_ran_is_not_described_as_inapplicable`).
      An unassessable category (§35) now says "could not be assessed"
      instead (`test_a_service_that_could_not_be_assessed_says_so`).
    - `Overall` rendered as the raw enum value, so the headline read
      "overall declined from 64 to 53"
      (`test_the_overall_score_is_named_like_a_service_not_an_enum`).
    - Landing Page and Product were missing from the category list
      entirely, so two services that scored never appeared
      (`test_every_scored_service_appears_in_the_comparison`), and Landing
      Page shared CRO's label
      (`test_landing_page_is_not_labelled_the_same_as_cro`).
    - A stored Not Applicable reason was thrown away when neither run
      scored
      (`test_the_stored_not_applicable_reason_survives_when_neither_run_scored`).

    **Verified against the real database** rather than only fixtures: the
    25-page → 6-page re-crawl of `beautifulashesministries.org` produced
    the caveat first, `resolved=0` and `not_rechecked=4` — a naive diff
    would have reported those four as fixed.

    Not covered: the comparison view and score-history chart are verified
    by typecheck and production build only (still no frontend test runner,
    §29), and no browser has rendered a real two-audit comparison — the
    dev server was serving 500s throughout this work (see below). The
    `not_rechecked` logic is tested against `meta.url` and the page
    relation; issues carrying `meta.affected_pages` are handled by the
    same code path but have no test of their own.

32. The test suite moved to MySQL. **Changed**: see CONTEXT.md §54.

    `phpunit.xml` now points at a real `audivio_test` database on MySQL
    5.7 instead of in-memory SQLite. Runtime went from 37 seconds to about
    22 minutes; nothing about the tests themselves changed.

    The trade is deliberate: SQLite ignores varchar lengths, so every
    column-width bug is structurally invisible to it. That is how §49's
    `error_message` overflow passed a green suite and then threw against
    the real database.

    **The first MySQL run produced 62 failures from a single cause.**
    `migrate:reset` fails on MySQL with error 1553 — two migrations'
    `down()` methods drop indexes InnoDB is using to satisfy foreign keys.
    `DatabaseMigrationTest` runs a reset mid-suite, it aborted half-way,
    and every later test inherited a schema missing three tables. All 1002
    pass once the two `down()` methods are fixed.

    Lost in the trade: `DatabaseMigrationTest` no longer double-checks
    that migrations run on SQLite, which is what caught a MySQL-only
    `LEFT()` during §49. And a test run now requires MySQL, so it cannot
    happen in a sandbox without one.

33. AI provider management, usage tracking and token optimization.
    **New**: see CONTEXT.md §53. 15 new PHP tests.

    `ModelTieringAndUsageTest` (15). Most of Phase 15 already existed, so
    coverage is aimed at the parts that did not and at the two failure
    paths that were live defects.

    Tier selection — a simple task takes the cheapest model that can do
    it; a complex task is **never** handed a simple one; a model with no
    price recorded is not treated as free; tiering never widens what a
    plan allows; an all-Standard catalogue (what every upgraded install
    gets) still serves Complex work via the pre-§53 fallback rather than
    returning null and silently switching AI off.

    Attribution — usage records the model actually called rather than the
    one configured (`test_usage_records_the_model_that_was_called_not_the_one_configured`),
    cost is priced at that model's rate rather than the flagship's
    (`test_cost_is_priced_at_the_model_actually_used`), and every call
    records its prompt version.

    **Two live instances of §49's bug**, found because the suite moved to
    MySQL (§54): `ai_analyses.error` and `api_providers.last_error` were
    both varchar(191) written by code truncating to 250, under
    `STRICT_ALL_TABLES`, inside the handler recording a provider failure.
    Covered by a column-type regression test and by driving a long
    provider error through the real path
    (`test_a_long_provider_error_is_recorded_rather_than_destroying_the_record`).

    A test written against the design rather than the code was corrected
    rather than the code: `tier` is NOT NULL DEFAULT 'standard' on
    purpose, so the "untiered catalogue" case does not exist — the real
    fallback path is "nothing meets the required tier", which is what the
    test now exercises.

    Not covered: the admin tier selector and the model usage chart are
    verified by typecheck and production build only (still no frontend
    test runner, §29). No real provider call has been made with an
    overridden model — all three providers are faked at the HTTP layer, so
    the override is proven to reach the request payload and URL, not to be
    accepted by Anthropic, OpenAI or Google.

34. Consultation requests and the sales workflow. **New**: see CONTEXT.md
    §55. 15 new PHP tests.

    `ConsultationRequestTest` (15). The endpoint is public and
    unauthenticated, so most of the coverage is about what it refuses to
    take on trust:

    - The website comes from the audit, and a `website_id` in the payload
      is ignored
      (`test_the_website_is_taken_from_the_audit_not_from_the_request`) —
      otherwise anyone could file leads against a competitor's site.
    - Findings are kept only if they are really on that audit
      (`test_findings_are_kept_only_if_they_are_really_on_that_audit`),
      never accepted from a different audit, and dropped entirely when no
      audit is named. Storing the raw claim would hand the sales team a
      fabricated list of problems attributed to a real report.
    - Requested services are filtered against the real service catalogue.
    - A request with no audit still records the lead — the pricing page
      and an un-audited footer must not lose a customer.

    Notification — sales is told when configured, nothing is sent when no
    recipients are set, and **a throwing mail transport still leaves the
    lead written** (`test_a_failing_notification_never_costs_the_lead`).

    Pipeline — `proposal` exists and is reachable through the admin
    endpoint; `won` replaces `converted` and `LeadStatus::tryFrom('converted')`
    is asserted null so the rename cannot be half-applied.

    Two test-authoring mistakes worth noting, both mine: a helper named
    `post()` collided with `Illuminate\Foundation\Testing\TestCase::post()`
    (a fatal, same class as the earlier `run()` collision), and the admin
    lead update route is PUT, not PATCH.

    Not covered: the four CTA placements are verified by typecheck and
    production build only (still no frontend test runner, §29), and no
    browser has clicked any of them. The queued notification is asserted
    as dispatched, not as delivered — no mail transport has actually sent
    one.

35. QA, regression, security and code-quality verification. **New**: see
    CONTEXT.md §56. 3 new PHP tests.

    `PublicSurfaceTest` (3). Deliberately structural rather than
    by-example: it walks the router and asserts a property of *every*
    unauthenticated route. A test naming one endpoint would not have found
    the five that were unthrottled when this was written.

    - `test_every_unauthenticated_api_route_is_rate_limited` — a route
      added later without a throttle fails here instead of in production.
    - `test_the_public_surface_has_not_quietly_grown` — a pinned list of
      the 24 public URIs. Every entry was reasoned about once; a new name
      in this diff is a prompt to do that again, not to update the list.
    - `test_a_public_read_endpoint_actually_returns_429_past_its_limit` —
      proves the middleware is a real limit, not a misspelled one that
      silently does nothing.

    **Verified live, not only in tests** (see §56 for the full list): the
    rate limit returned 39 × 200 then 41 × 429 against the running server;
    SSRF was refused on a disallowed port and on the cloud-metadata
    address; admin returned 401 unauthenticated and comparison, page
    explorer and unsigned screenshots returned 403 to a stranger; two real
    websites were audited end to end through the real database queue
    worker with zero failed jobs, and their scores differentiated
    correctly (performance 58 vs 95, SEO 91 vs 64).

    **Flagged-item closeout** (same phase, second pass): 7 further tests.

    - `AuditModuleRegistryFeatureGatingTest` — the zero-service audit now
      **fails** instead of completing with a fabricated overall score of
      0, writes no score row at all, and names the cause in its error
      message. The previous test asserted the buggy behaviour, so it was
      rewritten rather than added to. A second test asserts the audit
      still reaches a terminal state and stamps `terminated_at`, since
      that is what releases the owner's slot (§38).
    - `ProvidersTest` — a failed Test Connection reports the failed
      call's duration rather than null, checked for all three providers
      (`test_every_provider_reports_a_failed_calls_duration`).
    - `BackupDatabaseTest` — `db:backup` refuses a connection it cannot
      dump, leaves **no file behind** when it refuses (a partial dump
      restores cleanly and silently loses everything after the failure
      point), and dumps are git-ignored (a dump carries hashed passwords
      and encrypted provider keys).

    **Two spurious failures worth recording**, because both were mine and
    both looked like code defects: a MySQL deadlock on `ALTER TABLE ...
    ADD FOREIGN KEY` in the full suite, and three `ProvidersTest`
    failures. Both were caused by a second process hitting the same MySQL
    instance concurrently — audits and a queue worker in the first case, a
    second test run in the second. Both pass cleanly in isolation. **Do
    not run two test processes, or tests alongside a queue worker, against
    this MySQL instance.**

    Not covered: seven admin endpoints still have no UI (`fetchAudit`,
    `fetchCrawlJobs`, `fetchAdminSchedules`, `fetchAdminCmsPage`,
    `updateCmsPage`, `fetchProvider`, `fetchServiceReport`) — recorded as
    known gaps rather than built, since that is feature work. No automated
    test exercises the new lead-detail panel or share-event log (still no
    frontend test runner, §29). No audit has been run with an AI provider
    enabled, so the AI-assisted findings paths remain exercised only by
    faked HTTP.
