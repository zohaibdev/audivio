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
export interface MyFeatureAccess {
  key: string;
  name: string;
  description: string | null;
  enabled: boolean;
  plans_with_access: { id: number; name: string; slug: string }[];
}

export interface AiModel {
  id: number;
  api_provider_id: number;
  model_id: string;
  display_name: string;
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

export type AuditStatus = 'pending' | 'crawling' | 'analyzing' | 'completed' | 'failed';

export interface AuditSummary {
  audit_id: number;
  status: AuditStatus;
  pages_crawled: number;
  max_pages: number;
  error_message: string | null;
}

export interface ReportOverview {
  website_url: string;
  domain: string;
  platform: string;
  overall_score: number | null;
  category_scores: Record<string, number>;
  pages_crawled: number;
  audit_date: string | null;
}

export interface ReportIssue {
  category: string;
  severity: 'critical' | 'high' | 'medium' | 'low' | 'passed';
  priority?: 'high' | 'medium' | 'low';
  code: string;
  message: string;
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

export type ScheduleFrequency = 'daily' | 'weekly' | 'monthly';

export interface AuditSchedule {
  id: number;
  user_id: number;
  website_id: number;
  frequency: ScheduleFrequency;
  is_active: boolean;
  next_run_at: string;
  last_run_at: string | null;
  website?: { id: number; url: string; domain: string };
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

export interface Lead {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  website_url: string | null;
  message: string | null;
  source: string;
  status: string;
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
