export interface User {
  id: number;
  name: string;
  email: string;
  is_admin: boolean;
  created_at: string;
  subscription?: {
    id: number;
    status: string;
    started_at: string;
    ends_at: string | null;
    plan: Plan;
  } | null;
}

export interface Plan {
  id: number;
  name: string;
  slug: string;
  price_cents: number;
  max_pages: number;
  daily_audit_limit: number;
  full_recrawls: number;
  is_default: boolean;
  is_active: boolean;
  features: Record<string, boolean> | null;
  subscriptions_count?: number;
  audit_features?: PlanAuditFeature[];
  allowed_ai_models?: AiModel[];
}

/** One of the 7 registry rows (performance, accessibility, cro, seo, aio,
 * ecommerce, product_auditor) — distinct from Plan.features (unrelated
 * pdf_export/scheduled_audits/audit_history boolean perks). */
export interface Feature {
  id: number;
  key: string;
  name: string;
  description: string | null;
  is_enabled: boolean;
}

/** A Feature as it comes back nested under Plan.audit_features, carrying
 * the plan_features pivot's own enabled state alongside the feature row. */
export interface PlanAuditFeature extends Feature {
  pivot: { plan_id: number; feature_id: number; is_enabled: boolean };
}

/** GET /api/me/features shape — the current viewer's (or anonymous
 * visitor's default-plan) resolved access, not the raw registry. */
/** Keys of the on/off entitlements a plan grants (backend: App\Enums\PlanFeature). */
export type PlanFeatureKey =
  | 'pdf_export'
  | 'scheduled_audits'
  | 'audit_history'
  | 'report_sharing'
  | 'white_label'
  | 'custom_aio_prompts'
  | 'competitor_discovery';

export interface PlanFeatureState {
  label: string;
  /** Whether the caller may use it right now. */
  enabled: boolean;
  /**
   * Whether the capability exists at all yet. White label and competitor
   * discovery are configurable on a plan but not built, so they report
   * `available: false` and are never `enabled` — this is what lets the UI
   * say "not in your plan" and "coming soon" differently.
   */
  available: boolean;
}

/**
 * What the caller's plan allows. Purely descriptive — the server enforces
 * every one of these again where it matters.
 */
export interface MyEntitlements {
  plan: { id: number; name: string; slug: string } | null;
  is_guest: boolean;
  /** True when their own subscription lapsed and these are the default plan's terms. */
  is_downgraded: boolean;
  limits: {
    max_websites: number;
    max_pages: number;
    daily_audit_limit: number;
    /** null means unlimited, never "unset". */
    monthly_audit_limit: number | null;
    concurrent_audit_limit: number;
    max_audit_frequency: 'daily' | 'weekly' | 'monthly';
    report_visibility_percent: number;
    issues_per_service: number | null;
    ai_analyses_per_audit: number | null;
    aio_prompt_limit: number | null;
  };
  features: Record<PlanFeatureKey, PlanFeatureState>;
}

export interface MyFeatureAccess {
  key: string;
  name: string;
  description: string | null;
  enabled: boolean;
  plans_with_access: { id: number; name: string; slug: string }[];
}

/** How much model a task needs. A task is only ever served at or above its own tier. */
export type AiTaskComplexity = 'simple' | 'standard' | 'complex';

export interface AiModel {
  id: number;
  api_provider_id: number;
  model_id: string;
  display_name: string;
  /** What the model is fit for, which decides the tasks it is eligible for (§53). */
  tier: AiTaskComplexity;
  description: string | null;
  is_enabled: boolean;
  input_cost_per_1k_tokens: number | null;
  output_cost_per_1k_tokens: number | null;
  context_window: number | null;
  capabilities: string[] | null;
  api_provider?: { id: number; slug: string; name: string; is_enabled: boolean };
}

/** GET /api/me/ai-models shape — no cost fields, that's admin-only data. */
export interface MyAiModel {
  id: number;
  provider: string;
  provider_name: string;
  model_id: string;
  display_name: string;
  description: string | null;
  context_window: number | null;
  capabilities: string[] | null;
}

export type AuditStatus =
  | 'queued'
  | 'validating'
  | 'crawling'
  | 'analyzing'
  | 'generating_report'
  | 'completed'
  | 'failed'
  | 'cancelled';

export type AuditStageKey =
  | 'preparing'
  | 'validating'
  | 'discovering'
  | 'crawling'
  | 'screenshots'
  | 'performance'
  | 'seo'
  | 'accessibility'
  | 'cro'
  | 'aio'
  | 'recommendations'
  | 'building_report';

export interface AuditStageStep {
  stage: AuditStageKey;
  label: string;
  state: 'done' | 'current' | 'pending';
}

export interface AuditServiceProgress {
  category: string;
  label: string;
  /** `done` because a result exists, never because time passed. */
  state: 'done' | 'running' | 'pending';
}

/**
 * The estimate, or an honest account of why there isn't one (§51).
 *
 * `is_available` is false until the first page completes, because before
 * that there is no measured rate to project from.
 */
export interface AuditEstimate {
  is_available: boolean;
  reason: string | null;
  remaining_seconds: number | null;
  elapsed_seconds: number | null;
  total_seconds: number | null;
  basis?: string;
}

export interface AuditProgress {
  stage: AuditStageKey | null;
  stage_label: string | null;
  stages: AuditStageStep[];
  /** What the crawler actually found — not the plan's page cap. */
  pages_discovered: number;
  pages_analyzed: number;
  screenshots_captured: number;
  services: AuditServiceProgress[];
  services_running: string[];
  estimate: AuditEstimate;
  scale_note: string | null;
}

export interface AuditSummary {
  audit_id: number;
  status: AuditStatus;
  /** Human-readable form of `status`, supplied by the backend so the two never drift. */
  status_label: string;
  /** True while the audit still holds one of the owner's concurrent slots. */
  is_active: boolean;
  is_cancellable: boolean;
  pages_crawled: number;
  /** Absent on audits fetched before §51; the UI degrades to the coarse view. */
  progress?: AuditProgress;
  max_pages: number;
  error_message: string | null;
  /** Which stage an audit was in when it failed or was cancelled. */
  failed_stage: string | null;
}

export type SiteTypeKey =
  | 'ecommerce'
  | 'service_business'
  | 'saas'
  | 'blog'
  | 'portfolio'
  | 'landing_page'
  | 'local_business'
  | 'other';

export interface ReportOverview {
  website_url: string;
  domain: string;
  platform: string;
  overall_score: number | null;
  category_scores: Record<string, number>;
  pages_crawled: number;
  audit_date: string | null;
  /** Null for audits that ran before classification existed. */
  site_type?: SiteTypeKey | null;
  site_type_label?: string | null;
  site_type_description?: string | null;
  site_type_confidence?: number | null;
  /** The evidence behind the classification, strongest first. */
  site_type_signals?: string[];
}

export interface ReportIssue {
  category: string;
  severity: 'critical' | 'high' | 'medium' | 'low' | 'passed';
  priority?: 'high' | 'medium' | 'low';
  code: string;
  message: string;
  /** Why this matters, in consequences rather than rules (§47). */
  why_it_matters?: string | null;
  recommendation: string | null;
  page_url: string | null;
  meta: Record<string, unknown> | null;
}

export interface ReportRecommendation {
  category: string;
  severity: string;
  priority?: string;
  message: string;
  recommendation: string;
}

export interface ReportPageFinding {
  url: string;
  http_status: number;
  title: string | null;
  issue_count: number;
}

export interface Report {
  is_full_access: boolean;
  overview: ReportOverview;
  issues: ReportIssue[];
  recommendations: ReportRecommendation[];
  ai_analysis: Record<string, string>;
  pages?: ReportPageFinding[];
  hidden_issue_count?: number;
  hidden_recommendation_count?: number;
}

// --- Per-service reports (§35) ---

/**
 * The seven services the backend reports on (IssueTaxonomy::SERVICES).
 * `product_auditor` was missing here while the API had been returning it
 * since §36, so the type could not catch anything that forgot it.
 */
export type ServiceKey =
  | 'performance'
  | 'accessibility'
  | 'seo'
  | 'cro'
  | 'aio'
  | 'ecommerce'
  | 'product_auditor';

/**
 * Display names for the services, mirroring IssueTaxonomy::SERVICES on the
 * backend. Used where a visitor picks services by name (§55) rather than
 * reading them off a report that already labelled them.
 */
export const SERVICE_NAMES: Record<ServiceKey, string> = {
  performance: 'Page Speed & Performance',
  accessibility: 'Accessibility / ADA',
  seo: 'SEO',
  cro: 'Conversion Rate Optimization',
  aio: 'AI Search Readiness',
  ecommerce: 'Ecommerce',
  product_auditor: 'AI Product Audit',
};

/** `partial` = the service's AI-assisted checks did not run, so the score
 * covers only the rule-based checks and must not be shown as a clean pass. */
export type ServiceStatus = 'good' | 'needs_improvement' | 'poor' | 'partial' | 'not_applicable';

export type ServiceConfidence = 'complete' | 'partial' | 'unavailable';

export type Severity = 'critical' | 'high' | 'medium' | 'low' | 'passed';

export interface ServiceIssue {
  code: string;
  category: string;
  severity: Severity;
  title: string;
  message: string;
  /**
   * Why this matters, in consequences rather than rules (§47). Kept for
   * anonymous viewers: it is the reason, not the fix.
   */
  why_it_matters?: string | null;
  /** Absent for anonymous viewers — the fix is full-access content. */
  recommendation?: string | null;
  evidence?: Record<string, unknown>;
  affected_pages?: string[];
  affected_page_count: number;
  is_site_wide: boolean;
  detected_by: 'rule' | 'ai';
}

export interface ServiceCategoryGroup {
  name: string;
  issue_count: number;
  severity_summary: Record<string, number>;
  /** Absent for anonymous viewers: names and counts show, findings don't. */
  issues?: ServiceIssue[];
}

export interface ServiceRecommendation {
  title: string;
  category: string;
  severity: Severity;
  priority?: string;
  problem: string;
  recommended_action: string;
  affected_pages?: string[];
  affected_page_count?: number;
  evidence?: Record<string, unknown>;
  effort?: string;
  detected_by?: 'rule' | 'ai';
}

export interface ServicePageFinding {
  url: string;
  title: string | null;
  http_status: number;
  issue_count: number;
  severity_summary: Record<string, number>;
  issues: ServiceIssue[];
  screenshots: { desktop: string | null; mobile: string | null };
}

export interface ServiceAiState {
  used: boolean;
  available: boolean | null;
  analysis: string | null;
  error: string | null;
}

// --- Performance-specific report detail (§36) ---

export type VitalStatus = 'good' | 'needs_improvement' | 'poor' | 'unavailable';

export interface CoreWebVital {
  key: string;
  label: string;
  unit: string;
  about: string;
  /** Null means not measured — render as "unavailable", never as 0. */
  value: number | null;
  source: 'crawler' | 'pagespeed' | null;
  good_below: number | null;
  poor_above: number | null;
  status: VitalStatus;
}

/** A Lighthouse finding that carries a measured saving. */
export interface PerformanceOpportunity {
  id: string;
  title: string;
  description: string | null;
  category: string;
  score: number | null;
  /** Lighthouse's own wording, e.g. "Potential savings of 320 KiB". */
  display_value: string | null;
  savings_ms: number | null;
  savings_bytes: number | null;
  resources: PerformanceAffectedResource[];
}

/**
 * A failing audit Lighthouse did not quantify. Deliberately has no
 * savings fields — attaching one would invent the figure.
 */
export interface PerformanceDiagnostic {
  id: string;
  title: string;
  description: string | null;
  category: string;
  score: number | null;
  display_value: string | null;
  resources: PerformanceAffectedResource[];
}

export interface PerformanceAffectedResource {
  url: string;
  total_bytes?: number;
  wasted_bytes?: number;
  wasted_ms?: number;
}

/**
 * Real-user (CrUX) measurements. When unavailable this still carries a
 * reason, because a silently missing INP reads as "no INP problem".
 */
export interface PerformanceFieldBlock {
  available: boolean;
  reason: string | null;
  note?: string;
  overall_category: string | null;
  metrics: Record<string, { value: number; category: string | null }>;
}

export interface PerformanceStrategyBlock {
  available: boolean;
  reason: string | null;
  score: number | null;
  score_source?: string | null;
  vitals: CoreWebVital[];
  opportunities?: PerformanceOpportunity[];
  diagnostics?: PerformanceDiagnostic[];
  /** Null when Lighthouse never ran — never zero. */
  potential_savings?: { ms: number | null; bytes: number | null };
  field?: PerformanceFieldBlock;
  /** Present only in the restricted view, where the lists are withheld. */
  opportunity_count?: number;
  diagnostic_count?: number;
}

export interface PerformanceCategoryRow {
  category: string;
  label: string;
  finding_count: number;
  savings_ms: number | null;
  savings_bytes: number | null;
  /** Which strategy the grouping was built from — mobile where possible. */
  strategy: string | null;
}

export interface PerformanceFindingsBlock {
  opportunities: PerformanceOpportunity[];
  diagnostics: PerformanceDiagnostic[];
  potential_savings: { ms: number | null; bytes: number | null };
}

/** Measured from response headers by the crawler. */
export interface PerformanceCachingSummary {
  consideredCount: number;
  cachedCount: number;
  uncachedCount: number;
  uncachedBytes: number;
  uncachedSamples: { url: string; bytes: number; cacheControl: string | null }[];
}

export interface PerformanceCompressionSummary {
  consideredCount: number;
  compressedCount: number;
  uncompressedCount: number;
  uncompressedBytes: number;
  uncompressedSamples: { url: string; bytes: number }[];
}

export interface PerformanceResourceRow {
  type: string;
  requests: number;
  bytes: number;
}

export interface PerformanceResources {
  available: boolean;
  measured_pages: number;
  by_type: PerformanceResourceRow[];
  totals: Record<string, number>;
}

export interface PerformancePageRow {
  url: string;
  title: string | null;
  mobile_score: number | null;
  desktop_score: number | null;
  lcp_ms: number | null;
  cls: number | null;
  tbt_ms: number | null;
  ttfb_ms: number | null;
  fcp_ms: number | null;
  speed_index_ms: number | null;
  total_requests: number | null;
  transfer_bytes: number | null;
  third_party_bytes: number | null;
  dom_nodes: number | null;
  by_type: Record<string, { requests: number; bytes: number }> | null;
  screenshots: { desktop: string | null; mobile: string | null };
  /** Null for a strategy this page was never measured at. */
  findings: {
    mobile: PerformanceFindingsBlock | null;
    desktop: PerformanceFindingsBlock | null;
  };
  caching: PerformanceCachingSummary | null;
  compression: PerformanceCompressionSummary | null;
}

export interface PerformanceDetail {
  mobile: PerformanceStrategyBlock;
  desktop: PerformanceStrategyBlock;
  resources: PerformanceResources;
  categories: PerformanceCategoryRow[];
  pages: PerformancePageRow[];
}

/** One failing element as axe recorded it, on the page it was found on. */
export interface AccessibilityIssueElement {
  page_url: string;
  selector: string | null;
  /** Null for audits crawled before per-node markup was captured. */
  html: string | null;
  /** axe's own explanation of why this element failed. */
  evidence: string | null;
}

export interface SeoPreviewField {
  value: string | null;
  source: string | null;
  /** True when the value is borrowed from a tag not written for sharing. */
  is_fallback: boolean;
}

export interface SeoSocialPreview {
  url: string;
  has_open_graph: boolean;
  has_twitter_card: boolean;
  has_og_image: boolean;
  has_twitter_card_type: boolean;
  missing_og_tags: string[];
  og_type: string | null;
  preview: {
    title: SeoPreviewField;
    description: SeoPreviewField;
    image: SeoPreviewField;
    site_name: SeoPreviewField;
    url: SeoPreviewField;
    card_type: string | null;
  };
}

export interface SeoRedirectHop {
  url: string;
  status: number | null;
  /** 'unknown' for hops recorded before per-hop statuses were captured. */
  type: 'permanent' | 'temporary' | 'other' | 'unknown';
}

export interface SeoRedirectEntry {
  final_url: string;
  hop_count: number;
  hops: SeoRedirectHop[];
  is_chain: boolean;
  is_loop: boolean;
  has_temporary_redirect: boolean;
  final_status: number | null;
}

export interface SeoSchemaType {
  type: string;
  label: string;
  why: string;
  present: boolean;
  page_count: number;
  pages: string[];
}

export interface SeoPageRow {
  url: string;
  http_status: number | null;
  title: string | null;
  title_length: number | null;
  meta_description_length: number | null;
  canonical_url: string | null;
  canonical_points_elsewhere: boolean;
  is_indexable: boolean | null;
  indexability_reasons: string[];
  h1_count: number;
  image_count: number;
  images_missing_alt: number;
  internal_links_in: number;
  internal_links_out: number;
  redirect_hop_count: number;
  has_open_graph: boolean | null;
  schema_types: string[];
  load_time_ms: number | null;
}

/** 'unknown' means the crawl could not tell — never rendered as "missing". */
export type SeoFileStatus = 'found' | 'not_found' | 'unknown';

export interface SeoDetail {
  coverage: {
    pages_analyzed: number;
    checks: { check: string; label: string; ran: boolean; not_run_reason: string | null }[];
    structured_data_note: string;
  } | null;
  redirects: {
    total: number;
    chains: number;
    loops: number;
    temporary: number;
    entries: SeoRedirectEntry[];
    scope_note: string;
  };
  social: {
    analyzed_page_count: number | null;
    pages_with_open_graph: number;
    pages_with_og_image: number;
    pages_with_twitter_card: number;
    previews: SeoSocialPreview[];
    preview_note: string;
  };
  schema: {
    types: SeoSchemaType[];
    pages: {
      url: string;
      types: string[];
      problems: { type: string; label: string; missing_required: string[]; missing_recommended: string[] }[];
    }[];
    note: string;
  };
  pages: SeoPageRow[];
  site: {
    robots_txt: { status: SeoFileStatus; url: string | null; disallow_count: number | null; declared_sitemaps: string[] };
    sitemap: { status: SeoFileStatus; url_count: number | null; declared_in_robots: boolean | null };
    links: {
      internal: number;
      external: number;
      broken_internal: number;
      nofollow_internal: number;
      into_redirects: number;
      orphan_pages: number;
      orphan_page_urls: string[];
    };
  };
}

/**
 * Backlinks come from a third-party index or not at all. `not_configured`
 * and `unavailable` both render as "no data" — never as zero.
 */
export interface BacklinkState {
  status: 'not_configured' | 'unavailable' | 'available';
  provider: string | null;
  message: string | null;
  data: {
    domain: string;
    provider: string;
    fetched_at: string;
    total_backlinks: number | null;
    referring_domains: number | null;
    domain_authority: number | null;
    authority_scale: string | null;
    top_referrers: { domain: string; url?: string; anchor_text?: string | null; authority?: number | null }[];
  } | null;
}

export interface AccessibilityDisclaimer {
  title: string;
  summary: string;
  detail: string;
  score_note: string;
}

export interface AccessibilityCheckSummary {
  check: string;
  label: string;
  /** Absent in the restricted view, which shows counts without the copy. */
  why_it_matters?: string;
  issue_count: number;
  element_count: number;
  /** "No automated check found a problem here" — not "this area is accessible". */
  passed: boolean;
}

export interface AccessibilityDetail {
  summary: {
    score: number | null;
    critical_issues: number;
    high_issues: number;
    medium_issues: number;
    low_issues: number;
    passed_checks: number;
    affected_pages: number;
    affected_elements: number;
  };
  coverage: {
    pages_crawled: number;
    pages_scanned: number;
    is_partial: boolean;
    needs_review_count: number;
    needs_review: string[];
  };
  checks: AccessibilityCheckSummary[];
  /** Empty in the restricted view; the citations are full-access detail. */
  wcag_criteria: { criterion: string; level: string | null }[];
  /** Never null and never withheld — see ServiceReportVisibilityFilter. */
  disclaimer: AccessibilityDisclaimer;
}

export interface ServiceReport {
  service: ServiceKey;
  name: string;
  score: number | null;
  is_applicable: boolean;
  not_applicable_reason: string | null;
  /** Signals behind a skip decision. Shown at every visibility tier. */
  not_applicable_evidence?: string[];
  confidence: ServiceConfidence;
  coverage_note: string | null;
  status: ServiceStatus;
  what_this_means: string | null;
  severity_summary: Record<string, number>;
  categories: ServiceCategoryGroup[];
  issues: ServiceIssue[];
  recommendations: ServiceRecommendation[];
  pages: ServicePageFinding[];
  ai: ServiceAiState;
  /** Present only on the performance service; null elsewhere. */
  performance?: PerformanceDetail | null;
  /** Present only on the accessibility service; null elsewhere. */
  accessibility?: AccessibilityDetail | null;
  /** Present only on the SEO service; null elsewhere. */
  seo?: SeoDetail | null;
  /** Present only on the SEO service; null elsewhere. */
  backlinks?: BacklinkState | null;
  is_full_access?: boolean;
  hidden_issue_count?: number;
  hidden_recommendation_count?: number;
  hidden_page_count?: number;
}

export interface ServiceReportsResponse {
  overview: ReportOverview;
  services: Partial<Record<ServiceKey, ServiceReport>>;
  is_full_access: boolean;
}

export interface ExplorerPage {
  url: string;
  /**
   * Derived from this page's own issues using the same severity weights
   * the category scores use — not a Lighthouse score, and never shown as
   * one.
   */
  score: number;
  title: string | null;
  http_status: number;
  load_time_ms: number | null;
  issue_count: number;
  severity_summary: Record<string, number>;
  issues_by_service: Record<string, number>;
  screenshots: { desktop: string | null; mobile: string | null };
}

export type ScheduleFrequency = 'daily' | 'weekly' | 'monthly';

export interface AuditSchedule {
  id: number;
  user_id: number;
  website_id: number;
  frequency: ScheduleFrequency;
  is_active: boolean;
  next_run_at: string;
  last_run_at: string | null;
  /** Serialized Website model — the column is `primary_url` since §37. */
  website?: { id: number; primary_url: string; domain: string };
}

export interface CmsSection {
  id: number;
  section_key: string;
  content: Record<string, unknown>;
  sort_order: number;
}

export interface CmsPage {
  id: number;
  slug: string;
  title: string;
  meta_description: string | null;
  sections: CmsSection[];
}

/** Where a lead sits in the sales pipeline (§55). */
export type LeadStatus = 'new' | 'contacted' | 'qualified' | 'proposal' | 'won' | 'lost';

export interface Lead {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  /** Free text the visitor gave, or the audit's URL. Not an association. */
  website_url: string | null;
  /** The real association, set from the audit rather than the request (§55). */
  website_id: number | null;
  website?: { id: number; normalized_domain: string; primary_url: string } | null;
  audit_id: number | null;
  message: string | null;
  /** Findings from the audit that prompted the request, already verified. */
  issue_codes: string[] | null;
  requested_services: string[] | null;
  source: string;
  status: LeadStatus;
  created_at: string;
}

export interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
  per_page: number;
}

export interface ApiProviderSummary {
  id: number;
  slug: string;
  name: string;
  provider_type: 'ai' | 'audit';
  is_enabled: boolean;
  is_configured: boolean;
  masked_key: string | null;
  model: string | null;
  config: Record<string, unknown> | null;
  last_success_at: string | null;
  last_failure_at: string | null;
  last_error: string | null;
  requests_today: number;
  requests_this_month: number;
  failures_today: number;
  daily_limit: number | null;
  monthly_limit: number | null;
  daily_usage_percentage: number | null;
  monthly_usage_percentage: number | null;
}

export interface UsageSummary {
  total_requests: number;
  successful_requests: number;
  failed_requests: number;
  total_tokens: number;
  estimated_cost: number;
  average_response_time_ms: number | null;
  /**
   * Deliberately outside the dashboard's date filter — "today" and "this
   * month" are questions about now, not about the selected range.
   */
  requests_today: number;
  requests_this_month: number;
}

export interface UsageDailyRow {
  date: string;
  requests: number;
  failures: number;
  total_tokens: number;
  estimated_cost: number;
}

export interface UsageByProviderRow {
  provider: string;
  requests: number;
  failures: number;
  total_tokens: number;
  estimated_cost: number;
  average_response_time_ms: number | null;
}

export interface UsageByModelRow {
  model: string;
  provider: string;
  requests: number;
  failures: number;
  total_tokens: number;
  estimated_cost: number;
  average_response_time_ms: number | null;
}

export interface ApiUsageLogEntry {
  id: number;
  provider: string;
  service: string;
  model: string | null;
  success: boolean;
  http_status: number | null;
  response_time_ms: number | null;
  total_tokens: number | null;
  estimated_cost: number | null;
  created_at: string;
  user: { id: number; name: string } | null;
  audit: { id: number } | null;
}

export interface AdminDashboardSummary {
  users: { total: number; admins: number; new_today: number };
  websites: { total: number };
  audits: { total: number; today: number; this_month: number; by_status: Record<string, number> };
  leads: { total: number; by_status: Record<string, number> };
  ai_usage: { total_calls: number; failed_calls: number; total_prompt_tokens: number; total_completion_tokens: number };
  failed_jobs: { total: number };
  audit_schedules: { total: number; active: number };
  provider_usage_today: {
    requests_by_provider: Record<string, number>;
    ai_requests: number;
    ai_tokens: number;
    estimated_ai_cost: number;
    pagespeed_requests: number;
  };
}
