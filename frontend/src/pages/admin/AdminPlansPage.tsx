import { useEffect, useState } from 'react';
import * as adminApi from '../../api/admin';
import { apiErrorMessage } from '../../api/client';
import { Card } from '../../components/ui/Card';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { Badge } from '../../components/ui/Badge';
import { Spinner } from '../../components/ui/Spinner';
import { ErrorState } from '../../components/ui/ErrorState';
import { EmptyState } from '../../components/ui/EmptyState';
import type { AiModel, Feature, Plan } from '../../types';

type Tab = 'general' | 'pricing' | 'features' | 'models' | 'access' | 'limits' | 'status';

const TABS: { key: Tab; label: string }[] = [
  { key: 'general', label: 'General' },
  { key: 'pricing', label: 'Pricing' },
  { key: 'features', label: 'Audit Features' },
  { key: 'models', label: 'AI Models' },
  { key: 'access', label: 'Report Access' },
  { key: 'limits', label: 'Usage Limits' },
  { key: 'status', label: 'Status' },
];

const emptyForm = {
  name: '',
  slug: '',
  price_cents: 0,
  max_pages: 25,
  daily_audit_limit: 1,
  full_recrawls: 0,
  is_default: false,
  is_active: true,
  pdf_export: false,
  scheduled_audits: false,
  audit_history: false,
};

export function AdminPlansPage() {
  const [plans, setPlans] = useState<Plan[] | null>(null);
  const [allFeatures, setAllFeatures] = useState<Feature[] | null>(null);
  const [allAiModels, setAllAiModels] = useState<AiModel[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  const [isEditorOpen, setIsEditorOpen] = useState(false);
  const [activeTab, setActiveTab] = useState<Tab>('general');
  const [form, setForm] = useState(emptyForm);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [featureState, setFeatureState] = useState<Record<string, boolean>>({});
  const [selectedModelIds, setSelectedModelIds] = useState<number[]>([]);
  const [formError, setFormError] = useState<string | null>(null);
  const [isSaving, setIsSaving] = useState(false);
  const [isTogglingFeature, setIsTogglingFeature] = useState<number | null>(null);

  function load() {
    setError(null);
    adminApi
      .fetchPlans()
      .then((result) => setPlans(Array.isArray(result) ? result : result.data))
      .catch((err) => setError(apiErrorMessage(err)));
    adminApi.fetchFeatures().then(setAllFeatures).catch(() => undefined);
    adminApi.fetchAiModels().then(setAllAiModels).catch(() => undefined);
  }

  useEffect(load, []);

  function openCreate() {
    setEditingId(null);
    setForm(emptyForm);
    // A brand new plan starts with every feature enabled — matches how
    // existing plans were backfilled when this registry was introduced,
    // so a new plan isn't silently more restrictive than an admin expects.
    setFeatureState(Object.fromEntries((allFeatures ?? []).map((f) => [f.key, true])));
    setSelectedModelIds((allAiModels ?? []).map((m) => m.id));
    setActiveTab('general');
    setFormError(null);
    setIsEditorOpen(true);
  }

  function openEdit(plan: Plan) {
    setEditingId(plan.id);
    setForm({
      name: plan.name,
      slug: plan.slug,
      price_cents: plan.price_cents,
      max_pages: plan.max_pages,
      daily_audit_limit: plan.daily_audit_limit,
      full_recrawls: plan.full_recrawls,
      is_default: plan.is_default,
      is_active: plan.is_active,
      pdf_export: Boolean(plan.features?.pdf_export),
      scheduled_audits: Boolean(plan.features?.scheduled_audits),
      audit_history: Boolean(plan.features?.audit_history),
    });
    const pivotState: Record<string, boolean> = {};
    for (const f of plan.audit_features ?? []) {
      pivotState[f.key] = f.pivot.is_enabled;
    }
    setFeatureState(pivotState);
    setSelectedModelIds((plan.allowed_ai_models ?? []).map((m) => m.id));
    setActiveTab('general');
    setFormError(null);
    setIsEditorOpen(true);
  }

  function closeEditor() {
    setIsEditorOpen(false);
  }

  async function handleSave() {
    setFormError(null);
    setIsSaving(true);
    const payload: Partial<Plan> = {
      name: form.name,
      slug: form.slug,
      price_cents: form.price_cents,
      max_pages: form.max_pages,
      daily_audit_limit: form.daily_audit_limit,
      full_recrawls: form.full_recrawls,
      is_default: form.is_default,
      is_active: form.is_active,
      features: {
        pdf_export: form.pdf_export,
        scheduled_audits: form.scheduled_audits,
        audit_history: form.audit_history,
      },
    };
    try {
      const plan = editingId ? await adminApi.updatePlan(editingId, payload) : await adminApi.createPlan(payload);
      const featuresPayload = Object.entries(featureState).map(([key, is_enabled]) => ({ key, is_enabled }));
      await adminApi.updatePlanFeatures(plan.id, featuresPayload);
      await adminApi.updatePlanAiModels(plan.id, selectedModelIds);
      closeEditor();
      load();
    } catch (err) {
      setFormError(apiErrorMessage(err));
    } finally {
      setIsSaving(false);
    }
  }

  async function handleDelete(id: number) {
    try {
      await adminApi.deletePlan(id);
      load();
    } catch (err) {
      setError(apiErrorMessage(err));
    }
  }

  async function handleDuplicate(plan: Plan) {
    try {
      const copy = await adminApi.createPlan({
        name: `${plan.name} (copy)`,
        slug: `${plan.slug}-copy-${Date.now()}`,
        price_cents: plan.price_cents,
        max_pages: plan.max_pages,
        daily_audit_limit: plan.daily_audit_limit,
        full_recrawls: plan.full_recrawls,
        is_default: false,
        is_active: false,
        features: plan.features,
      });
      const featuresPayload = (plan.audit_features ?? []).map((f) => ({ key: f.key, is_enabled: f.pivot.is_enabled }));
      if (featuresPayload.length > 0) {
        await adminApi.updatePlanFeatures(copy.id, featuresPayload);
      }
      const modelIds = (plan.allowed_ai_models ?? []).map((m) => m.id);
      if (modelIds.length > 0) {
        await adminApi.updatePlanAiModels(copy.id, modelIds);
      }
      load();
    } catch (err) {
      setError(apiErrorMessage(err));
    }
  }

  async function toggleActive(plan: Plan) {
    try {
      await adminApi.updatePlan(plan.id, { is_active: !plan.is_active });
      load();
    } catch (err) {
      setError(apiErrorMessage(err));
    }
  }

  async function toggleFeatureGlobally(feature: Feature) {
    setIsTogglingFeature(feature.id);
    try {
      await adminApi.setFeatureStatus(feature.id, !feature.is_enabled);
      load();
    } catch (err) {
      setError(apiErrorMessage(err));
    } finally {
      setIsTogglingFeature(null);
    }
  }

  const modelsByProvider = new Map<string, AiModel[]>();
  for (const model of allAiModels ?? []) {
    const providerName = model.api_provider?.name ?? 'Unknown provider';
    modelsByProvider.set(providerName, [...(modelsByProvider.get(providerName) ?? []), model]);
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Pricing Plans</h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Audit features, AI models, and usage limits per plan.</p>
        </div>
        <Button onClick={openCreate}>New plan</Button>
      </div>

      {allFeatures && allFeatures.length > 0 && (
        <Card>
          <h2 className="mb-1 text-sm font-semibold text-slate-700 dark:text-slate-200">Global feature registry</h2>
          <p className="mb-3 text-xs text-slate-500 dark:text-slate-400">
            Disabling a feature here removes it from every plan immediately, even ones that individually enable it.
          </p>
          <div className="flex flex-wrap gap-2">
            {allFeatures.map((feature) => (
              <button
                key={feature.id}
                onClick={() => toggleFeatureGlobally(feature)}
                disabled={isTogglingFeature === feature.id}
                className="disabled:opacity-50"
              >
                <Badge tone={feature.is_enabled ? 'green' : 'red'}>{`${feature.name}: ${feature.is_enabled ? 'On' : 'Off'}`}</Badge>
              </button>
            ))}
          </div>
        </Card>
      )}

      {error && <ErrorState message={error} onRetry={load} />}
      {!error && !plans && <Spinner />}
      {plans && plans.length === 0 && <EmptyState title="No plans configured" />}

      {plans && plans.length > 0 && (
        <div className="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-800">
          <table className="w-full min-w-[900px] text-left text-sm">
            <thead className="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
              <tr>
                <th className="px-4 py-3">Plan</th>
                <th className="px-4 py-3">Price</th>
                <th className="px-4 py-3">Users</th>
                <th className="px-4 py-3">Features</th>
                <th className="px-4 py-3">AI Models</th>
                <th className="px-4 py-3">Daily Limit</th>
                <th className="px-4 py-3">Status</th>
                <th className="px-4 py-3">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 bg-white dark:divide-slate-800 dark:bg-slate-900">
              {plans.map((plan) => {
                const enabledFeatureCount = (plan.audit_features ?? []).filter((f) => f.pivot.is_enabled).length;
                return (
                  <tr key={plan.id}>
                    <td className="px-4 py-3 font-medium text-slate-900 dark:text-white">
                      {plan.name} {plan.is_default && <Badge tone="blue">Default</Badge>}
                    </td>
                    <td className="px-4 py-3 text-slate-600 dark:text-slate-300">${(plan.price_cents / 100).toFixed(0)}</td>
                    <td className="px-4 py-3 text-slate-600 dark:text-slate-300">{plan.subscriptions_count ?? 0}</td>
                    <td className="px-4 py-3 text-slate-600 dark:text-slate-300">{enabledFeatureCount}/{allFeatures?.length ?? 7}</td>
                    <td className="px-4 py-3 text-slate-600 dark:text-slate-300">{plan.allowed_ai_models?.length ?? 0}</td>
                    <td className="px-4 py-3 text-slate-600 dark:text-slate-300">{plan.daily_audit_limit}/day</td>
                    <td className="px-4 py-3">
                      <Badge tone={plan.is_active ? 'green' : 'gray'}>{plan.is_active ? 'Active' : 'Disabled'}</Badge>
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap gap-2">
                        <Button variant="secondary" onClick={() => openEdit(plan)}>Edit</Button>
                        <Button variant="secondary" onClick={() => handleDuplicate(plan)}>Duplicate</Button>
                        <Button variant="secondary" onClick={() => toggleActive(plan)}>{plan.is_active ? 'Disable' : 'Enable'}</Button>
                        <Button variant="danger" onClick={() => handleDelete(plan.id)}>Delete</Button>
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {isEditorOpen && (
        <div className="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 pt-10">
          <Card className="w-full max-w-3xl">
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-lg font-semibold text-slate-900 dark:text-white">{editingId ? 'Edit plan' : 'New plan'}</h2>
              <button onClick={closeEditor} className="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200" aria-label="Close">
                ✕
              </button>
            </div>

            <div className="mb-5 flex flex-wrap gap-1 border-b border-slate-200 dark:border-slate-800">
              {TABS.map((tab) => (
                <button
                  key={tab.key}
                  onClick={() => setActiveTab(tab.key)}
                  className={`rounded-t-md px-3 py-2 text-sm font-medium ${
                    activeTab === tab.key
                      ? 'border-b-2 border-indigo-600 text-indigo-600 dark:text-indigo-400'
                      : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200'
                  }`}
                >
                  {tab.label}
                </button>
              ))}
            </div>

            {activeTab === 'general' && (
              <div className="grid gap-4 sm:grid-cols-2">
                <Input label="Name" required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                <Input label="Slug" required value={form.slug} onChange={(e) => setForm({ ...form, slug: e.target.value })} />
              </div>
            )}

            {activeTab === 'pricing' && (
              <div className="grid gap-4 sm:grid-cols-2">
                <Input
                  label="Price (cents)"
                  type="number"
                  value={form.price_cents}
                  onChange={(e) => setForm({ ...form, price_cents: Number(e.target.value) })}
                />
                <p className="self-end text-sm text-slate-500 dark:text-slate-400">= ${(form.price_cents / 100).toFixed(2)} / month</p>
              </div>
            )}

            {activeTab === 'features' && (
              <div className="flex flex-col gap-3">
                <p className="text-sm text-slate-500 dark:text-slate-400">Enable or disable each audit type for this plan.</p>
                {(allFeatures ?? []).map((feature) => (
                  <label key={feature.key} className="flex items-start gap-3 rounded-md border border-slate-200 p-3 dark:border-slate-800">
                    <input
                      type="checkbox"
                      className="mt-1"
                      checked={Boolean(featureState[feature.key])}
                      disabled={!feature.is_enabled}
                      onChange={(e) => setFeatureState({ ...featureState, [feature.key]: e.target.checked })}
                    />
                    <span>
                      <span className="block text-sm font-medium text-slate-900 dark:text-white">
                        {feature.name} {!feature.is_enabled && <Badge tone="gray">Disabled globally</Badge>}
                      </span>
                      {feature.description && <span className="block text-xs text-slate-500 dark:text-slate-400">{feature.description}</span>}
                    </span>
                  </label>
                ))}
                {(allFeatures ?? []).length === 0 && <EmptyState title="No audit features registered yet" />}
              </div>
            )}

            {activeTab === 'models' && (
              <div className="flex flex-col gap-4">
                <p className="text-sm text-slate-500 dark:text-slate-400">Select which AI models users on this plan may use.</p>
                {[...modelsByProvider.entries()].map(([providerName, models]) => (
                  <div key={providerName}>
                    <h4 className="mb-2 text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{providerName}</h4>
                    <div className="flex flex-col gap-2">
                      {models.map((model) => (
                        <label key={model.id} className="flex items-center gap-3 rounded-md border border-slate-200 p-2 text-sm dark:border-slate-800">
                          <input
                            type="checkbox"
                            checked={selectedModelIds.includes(model.id)}
                            disabled={!model.is_enabled}
                            onChange={(e) =>
                              setSelectedModelIds(
                                e.target.checked ? [...selectedModelIds, model.id] : selectedModelIds.filter((id) => id !== model.id),
                              )
                            }
                          />
                          <span className="text-slate-900 dark:text-white">{model.display_name}</span>
                          {!model.is_enabled && <Badge tone="gray">Disabled globally</Badge>}
                        </label>
                      ))}
                    </div>
                  </div>
                ))}
                {(allAiModels ?? []).length === 0 && <EmptyState title="No AI models registered yet" />}
              </div>
            )}

            {activeTab === 'access' && (
              <div className="flex flex-col gap-3">
                <label className="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                  <input type="checkbox" checked={form.pdf_export} onChange={(e) => setForm({ ...form, pdf_export: e.target.checked })} />
                  PDF export
                </label>
                <label className="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                  <input type="checkbox" checked={form.scheduled_audits} onChange={(e) => setForm({ ...form, scheduled_audits: e.target.checked })} />
                  Scheduled audits
                </label>
                <label className="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                  <input type="checkbox" checked={form.audit_history} onChange={(e) => setForm({ ...form, audit_history: e.target.checked })} />
                  Audit history
                </label>
              </div>
            )}

            {activeTab === 'limits' && (
              <div className="grid gap-4 sm:grid-cols-2">
                <Input
                  label="Max pages per audit"
                  type="number"
                  required
                  value={form.max_pages}
                  onChange={(e) => setForm({ ...form, max_pages: Number(e.target.value) })}
                />
                <Input
                  label="Daily audit limit"
                  type="number"
                  required
                  value={form.daily_audit_limit}
                  onChange={(e) => setForm({ ...form, daily_audit_limit: Number(e.target.value) })}
                />
                <Input
                  label="Full recrawls (scheduled audits)"
                  type="number"
                  value={form.full_recrawls}
                  onChange={(e) => setForm({ ...form, full_recrawls: Number(e.target.value) })}
                />
                <p className="self-end text-xs text-slate-500 dark:text-slate-400 sm:col-span-2">
                  There is no separate website-count cap in this MVP — a user may add any number of websites within their audit limits above.
                </p>
              </div>
            )}

            {activeTab === 'status' && (
              <div className="flex flex-col gap-3">
                <label className="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                  <input type="checkbox" checked={form.is_default} onChange={(e) => setForm({ ...form, is_default: e.target.checked })} />
                  Default plan (new signups are subscribed to this plan)
                </label>
                <label className="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                  <input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
                  Active (visible on the public pricing page, open to new subscriptions)
                </label>
              </div>
            )}

            {formError && <p className="mt-4 text-sm text-red-600 dark:text-red-400">{formError}</p>}

            <div className="mt-6 flex gap-3 border-t border-slate-200 pt-4 dark:border-slate-800">
              <Button onClick={handleSave} isLoading={isSaving}>
                {editingId ? 'Save changes' : 'Create plan'}
              </Button>
              <Button variant="secondary" onClick={closeEditor}>
                Cancel
              </Button>
            </div>
          </Card>
        </div>
      )}
    </div>
  );
}
