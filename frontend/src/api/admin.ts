import { apiClient, unwrap } from './client';
import type {
  AdminDashboardSummary,
  AiModel,
  ApiProviderSummary,
  ApiUsageLogEntry,
  CmsPage,
  Feature,
  Lead,
  Paginated,
  Plan,
  UsageByProviderRow,
  UsageDailyRow,
  AiTaskComplexity,
  UsageByModelRow,
  UsageSummary,
  User,
} from '../types';

export function fetchDashboardSummary() {
  return unwrap<AdminDashboardSummary>(apiClient.get('/admin/dashboard'));
}

export function fetchUsers(page = 1) {
  return unwrap<Paginated<User & { subscription?: { plan?: Plan } }>>(apiClient.get('/admin/users', { params: { page } }));
}

export function fetchWebsites(page = 1) {
  return unwrap<Paginated<{ id: number; primary_url: string; domain: string; name: string | null; audits_count: number }>>(
    apiClient.get('/admin/websites', { params: { page } }),
  );
}

export function fetchAudits(page = 1) {
  return unwrap<Paginated<{ id: number; status: string; website?: { primary_url: string }; created_at: string }>>(
    apiClient.get('/admin/audits', { params: { page } }),
  );
}

export function fetchAudit(id: number) {
  return unwrap<Record<string, unknown>>(apiClient.get(`/admin/audits/${id}`));
}

export function fetchCrawlJobs(page = 1) {
  return unwrap<Paginated<Record<string, unknown>>>(apiClient.get('/admin/crawl-jobs', { params: { page } }));
}

export function fetchAdminSchedules(page = 1) {
  return unwrap<Paginated<Record<string, unknown>>>(apiClient.get('/admin/audit-schedules', { params: { page } }));
}

export function fetchFailedJobs(page = 1) {
  return unwrap<Paginated<{ id: number; connection: string; queue: string; exception: string; failed_at: string }>>(
    apiClient.get('/admin/failed-jobs', { params: { page } }),
  );
}

export function deleteFailedJob(id: number) {
  return unwrap<null>(apiClient.delete(`/admin/failed-jobs/${id}`));
}

export function fetchAiUsage() {
  return unwrap<Record<string, unknown>>(apiClient.get('/admin/ai-usage'));
}

export interface SystemHealth {
  database: { status: 'ok' | 'error'; message: string | null };
  cache: { status: 'ok' | 'error'; message: string | null };
  queue_connection: string;
}

export function fetchSystemHealth() {
  return unwrap<SystemHealth>(apiClient.get('/admin/system-health'));
}

// --- Plans ---

export function fetchPlans() {
  return unwrap<Paginated<Plan> | Plan[]>(apiClient.get('/admin/plans'));
}

export function createPlan(payload: Partial<Plan>) {
  return unwrap<Plan>(apiClient.post('/admin/plans', payload));
}

export function updatePlan(id: number, payload: Partial<Plan>) {
  return unwrap<Plan>(apiClient.put(`/admin/plans/${id}`, payload));
}

export function deletePlan(id: number) {
  return unwrap<null>(apiClient.delete(`/admin/plans/${id}`));
}

export function updatePlanFeatures(planId: number, features: { key: string; is_enabled: boolean }[]) {
  return unwrap<Plan>(apiClient.put(`/admin/plans/${planId}/features`, { features }));
}

export function updatePlanAiModels(planId: number, aiModelIds: number[]) {
  return unwrap<Plan>(apiClient.put(`/admin/plans/${planId}/ai-models`, { ai_model_ids: aiModelIds }));
}

// --- Audit feature registry (global enable/disable only) ---

export function fetchFeatures() {
  return unwrap<Feature[]>(apiClient.get('/admin/features'));
}

export function setFeatureStatus(id: number, isEnabled: boolean) {
  return unwrap<Feature>(apiClient.patch(`/admin/features/${id}/status`, { is_enabled: isEnabled }));
}

// --- AI model catalog ---

export function fetchAiModels(provider?: string) {
  return unwrap<AiModel[]>(apiClient.get('/admin/ai/models', { params: provider ? { provider } : {} }));
}

export interface AiModelPayload {
  api_provider_id?: number;
  model_id?: string;
  display_name?: string;
  tier?: AiTaskComplexity;
  description?: string | null;
  is_enabled?: boolean;
  input_cost_per_1k_tokens?: number | null;
  output_cost_per_1k_tokens?: number | null;
  context_window?: number | null;
  capabilities?: string[] | null;
}

export function createAiModel(payload: AiModelPayload) {
  return unwrap<AiModel>(apiClient.post('/admin/ai/models', payload));
}

export function updateAiModel(id: number, payload: AiModelPayload) {
  return unwrap<AiModel>(apiClient.put(`/admin/ai/models/${id}`, payload));
}

export function deleteAiModel(id: number) {
  return unwrap<null>(apiClient.delete(`/admin/ai/models/${id}`));
}

// --- Leads ---

export function fetchAdminLeads(status?: string) {
  return unwrap<Paginated<Lead>>(apiClient.get('/admin/leads', { params: status ? { status } : {} }));
}

export function fetchAdminLead(id: number) {
  return unwrap<Lead & { activities: { id: number; note: string; created_at: string; user?: { name: string } }[] }>(
    apiClient.get(`/admin/leads/${id}`),
  );
}

export function updateLeadStatus(id: number, status: string) {
  return unwrap<Lead>(apiClient.put(`/admin/leads/${id}`, { status }));
}

export function addLeadNote(id: number, note: string) {
  return unwrap<{ id: number; note: string }>(apiClient.post(`/admin/leads/${id}/activities`, { note }));
}

// --- CMS ---

export function fetchAdminCmsPages() {
  return unwrap<CmsPage[]>(apiClient.get('/admin/cms'));
}

export function fetchAdminCmsPage(id: number) {
  return unwrap<CmsPage>(apiClient.get(`/admin/cms/${id}`));
}

export function createCmsPage(payload: { slug: string; title: string; meta_description?: string }) {
  return unwrap<CmsPage>(apiClient.post('/admin/cms', payload));
}

export function updateCmsPage(id: number, payload: Partial<{ slug: string; title: string; meta_description: string }>) {
  return unwrap<CmsPage>(apiClient.put(`/admin/cms/${id}`, payload));
}

export function deleteCmsPage(id: number) {
  return unwrap<null>(apiClient.delete(`/admin/cms/${id}`));
}

export function putCmsSection(pageId: number, sectionKey: string, content: Record<string, unknown>, sortOrder = 0) {
  return unwrap<{ id: number; section_key: string; content: Record<string, unknown> }>(
    apiClient.put(`/admin/cms/${pageId}/sections/${sectionKey}`, { content, sort_order: sortOrder }),
  );
}

export function deleteCmsSection(pageId: number, sectionKey: string) {
  return unwrap<null>(apiClient.delete(`/admin/cms/${pageId}/sections/${sectionKey}`));
}

// --- AI/API providers ---

export function fetchProviders() {
  return unwrap<ApiProviderSummary[]>(apiClient.get('/admin/providers'));
}

export function fetchProvider(slug: string) {
  return unwrap<ApiProviderSummary>(apiClient.get(`/admin/providers/${slug}`));
}

export interface ProviderConfigPayload {
  api_key?: string | null;
  model?: string | null;
  max_tokens?: number;
  temperature?: number;
  request_timeout?: number;
  daily_request_limit?: number | null;
  monthly_request_limit?: number | null;
  mobile_enabled?: boolean;
  desktop_enabled?: boolean;
  cost_per_1k_input_tokens?: number | null;
  cost_per_1k_output_tokens?: number | null;
}

export function configureProvider(slug: string, payload: ProviderConfigPayload) {
  return unwrap<ApiProviderSummary>(apiClient.put(`/admin/providers/${slug}`, payload));
}

export function setProviderStatus(slug: string, isEnabled: boolean) {
  return unwrap<ApiProviderSummary>(apiClient.patch(`/admin/providers/${slug}/status`, { is_enabled: isEnabled }));
}

export function testProviderConnection(slug: string) {
  return unwrap<{ success: boolean; response_time_ms: number | null; message: string }>(
    apiClient.post(`/admin/providers/${slug}/test`),
  );
}

// --- Usage analytics ---

export interface UsageLogFilters {
  provider?: string;
  service?: string;
  model?: string;
  user_id?: number;
  audit_id?: number;
  success?: boolean;
  from?: string;
  to?: string;
  page?: number;
}

export function fetchUsageSummary(filters: UsageLogFilters = {}) {
  return unwrap<UsageSummary>(apiClient.get('/admin/usage/summary', { params: filters }));
}

export function fetchUsageDaily(filters: UsageLogFilters = {}) {
  return unwrap<UsageDailyRow[]>(apiClient.get('/admin/usage/daily', { params: filters }));
}

export function fetchUsageByProvider(filters: UsageLogFilters = {}) {
  return unwrap<UsageByProviderRow[]>(apiClient.get('/admin/usage/by-provider', { params: filters }));
}

/** Usage grouped by the model actually called, not the one configured (§53). */
export function fetchUsageByModel(filters: UsageLogFilters = {}) {
  return unwrap<UsageByModelRow[]>(apiClient.get('/admin/usage/by-model', { params: filters }));
}

export function fetchUsageLogs(filters: UsageLogFilters = {}) {
  return unwrap<Paginated<ApiUsageLogEntry>>(apiClient.get('/admin/usage/logs', { params: filters }));
}
