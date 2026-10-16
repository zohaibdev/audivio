import { apiClient, unwrap } from './client';
import type { Paginated } from '../types';

/** The five per-service scores a website card shows, in display order. */
export interface WebsiteCardScores {
  performance: number | null;
  accessibility: number | null;
  seo: number | null;
  conversion: number | null;
  aio: number | null;
}

/**
 * The scores and counts come from the last *completed* audit.
 * `latest_audit` is separate and non-null only while a newer run is still
 * going or has failed — so a card can show "re-auditing now" without
 * losing the numbers the user could already see.
 */
export interface MyWebsite {
  id: number;
  name: string;
  domain: string;
  normalized_domain: string;
  primary_url: string;
  screenshot_url: string | null;
  audits_count: number;

  last_audit_at: string | null;
  last_audit_id: number | null;
  pages_analyzed: number | null;

  overall_score: number | null;
  scores: WebsiteCardScores;

  critical_issues: number;
  high_issues: number;

  latest_audit: {
    id: number;
    status: string;
    pages_crawled: number;
    max_pages: number;
  } | null;
}

export interface WebsiteOverview extends MyWebsite {
  category: string | null;
  description: string | null;
  products_or_services: string | null;
  /** What the business wants to be found for in AI search (section 42). */
  aio_goal: string | null;
  country: string | null;
  budget: string | null;
  average_product_cost: string | null;
  competitors: string[];
  platform: string | null;
  created_at: string | null;
}

export interface ScorePoint {
  audit_id: number;
  date: string | null;
  score: number;
}

export interface IssueTrendPoint {
  audit_id: number;
  date: string | null;
  critical: number;
  high: number;
  medium: number;
  low: number;
  /**
   * Carried alongside the counts (§52): an issue count only means
   * something next to how much of the site was crawled to find them.
   */
  pages_analyzed: number;
}

export interface AuditHistoryRow {
  id: number;
  status: string;
  created_at: string | null;
  completed_at: string | null;
  pages_crawled: number;
  error_message: string | null;
  overall_score: number | null;
  critical_issues: number;
  high_issues: number;
}

export interface ServiceScore {
  category: string;
  score: number | null;
  is_applicable: boolean;
}

export interface WebsitePage {
  url: string;
  title: string | null;
  http_status: number | null;
  load_time_ms: number | null;
  issue_count: number;
  severity_summary: Record<string, number>;
  issues_by_service: Record<string, number>;
}

export interface WebsiteRecommendation {
  category: string;
  severity: string;
  code: string;
  message: string;
  recommendation: string;
}

/** Which onboarding fields are filled in, and what that unlocks. */
export interface WebsiteOnboarding {
  aio_goal: string | null;
  missing_fields: string[];
  is_complete: boolean;
  /**
   * False means three of the ten AIO checks are skipped for want of an
   * AIO goal — not that the audit is invalid.
   */
  unlocks_context_prompts: boolean;
}

export interface CompetitorVisibility {
  structured_data: boolean;
  clear_headings: boolean;
  descriptive_titles: boolean;
  summary: string | null;
}

export interface WebsiteCompetitor {
  id: number;
  name: string;
  domain: string;
  url: string | null;
  source: 'user' | 'discovered';
  source_label: string;
  reason: string | null;
  is_analyzed: boolean;
  /** Null means not analyzed — never render it as a score of zero. */
  aio_score: number | null;
  pages_analyzed: number | null;
  topics: string[];
  visibility: CompetitorVisibility | null;
  analysis_error: string | null;
  analyzed_at: string | null;
}

export interface AioPrompt {
  id: number;
  prompt: string;
  sort_order: number;
}

export interface AioPromptSettings {
  prompts: AioPrompt[];
  defaults: { key: string; question: string; needs_context: boolean }[];
  /** Null means unlimited. */
  limit: number | null;
  can_add: boolean;
  context: { skipped: string[]; missing_fields: string[] };
}

export interface WebsiteDetail {
  overview: WebsiteOverview;
  latest_audit: ({ id: number; status: string; scores?: ServiceScore[] } & Record<string, unknown>) | null;
  audit_history: AuditHistoryRow[];
  /** Keyed by category; a category no audit scored is absent, not zero-filled. */
  score_trends: Record<string, ScorePoint[]>;
  service_scores: ServiceScore[];
  issue_trends: IssueTrendPoint[];
  pages: WebsitePage[];
  recommendations: WebsiteRecommendation[];
  onboarding: WebsiteOnboarding;
  competitors: WebsiteCompetitor[];
  consultation: { audit_id: number | null; website_url: string };
}

export interface WebsiteProfileUpdate {
  name?: string | null;
  category?: string | null;
  description?: string | null;
  products_or_services?: string | null;
  aio_goal?: string | null;
  country?: string | null;
  budget?: number | null;
  average_product_cost?: number | null;
  competitors?: string[];
}

export function fetchMyWebsites(page = 1) {
  return unwrap<Paginated<MyWebsite>>(apiClient.get('/websites', { params: { page } }));
}

/**
 * `identifier` is a website id or its normalized domain. It selects which
 * of *your* websites to load — the backend resolves it against the
 * authenticated user's own records only, so a domain in the URL never
 * grants access to anything.
 */
export function fetchWebsite(identifier: string | number) {
  return unwrap<WebsiteDetail>(apiClient.get(`/websites/${encodeURIComponent(String(identifier))}`));
}

export function updateWebsite(identifier: string | number, profile: WebsiteProfileUpdate) {
  return unwrap<WebsiteDetail>(
    apiClient.put(`/websites/${encodeURIComponent(String(identifier))}`, profile),
  );
}

/** Custom AIO prompts for one website (section 42). */
export function fetchAioPrompts(identifier: string | number) {
  return unwrap<AioPromptSettings>(
    apiClient.get(`/websites/${encodeURIComponent(String(identifier))}/aio-prompts`),
  );
}

export function createAioPrompt(identifier: string | number, prompt: string) {
  return unwrap<AioPrompt>(
    apiClient.post(`/websites/${encodeURIComponent(String(identifier))}/aio-prompts`, { prompt }),
  );
}

export function deleteAioPrompt(identifier: string | number, promptId: number) {
  return unwrap<null>(
    apiClient.delete(`/websites/${encodeURIComponent(String(identifier))}/aio-prompts/${promptId}`),
  );
}

export interface MyAudit {
  id: number;
  status: string;
  pages_crawled: number;
  max_pages: number;
  created_at: string;
  website: { id: number; primary_url: string; domain: string };
  scores: { category: string; score: number }[];
}

export function fetchMyAudits(page = 1) {
  return unwrap<Paginated<MyAudit>>(apiClient.get('/audits', { params: { page } }));
}
