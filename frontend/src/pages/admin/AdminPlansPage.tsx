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
import type { Plan } from '../../types';

const emptyForm = {
  name: '',
  slug: '',
  price_cents: 0,
  max_pages: 25,
  daily_audit_limit: 1,
  full_recrawls: 0,
  is_default: false,
  pdf_export: false,
  scheduled_audits: false,
  audit_history: false,
};

export function AdminPlansPage() {
  const [plans, setPlans] = useState<Plan[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [form, setForm] = useState(emptyForm);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [isSaving, setIsSaving] = useState(false);

  function load() {
    setError(null);
    adminApi
      .fetchPlans()
      .then((result) => setPlans(Array.isArray(result) ? result : result.data))
      .catch((err) => setError(apiErrorMessage(err)));
  }

  useEffect(load, []);

  function startEdit(plan: Plan) {
    setEditingId(plan.id);
    setForm({
      name: plan.name,
      slug: plan.slug,
      price_cents: plan.price_cents,
      max_pages: plan.max_pages,
      daily_audit_limit: plan.daily_audit_limit,
      full_recrawls: plan.full_recrawls,
      is_default: plan.is_default,
      pdf_export: Boolean(plan.features?.pdf_export),
      scheduled_audits: Boolean(plan.features?.scheduled_audits),
      audit_history: Boolean(plan.features?.audit_history),
    });
  }

  function resetForm() {
    setEditingId(null);
    setForm(emptyForm);
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
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
      features: {
        pdf_export: form.pdf_export,
        scheduled_audits: form.scheduled_audits,
        audit_history: form.audit_history,
      },
    };
    try {
      if (editingId) {
        await adminApi.updatePlan(editingId, payload);
      } else {
        await adminApi.createPlan(payload);
      }
      resetForm();
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

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Plans & Usage Limits</h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Page limits, daily audit limits, and feature entitlements per plan.</p>
      </div>

      <Card>
        <h2 className="mb-4 text-sm font-semibold text-slate-700 dark:text-slate-200">{editingId ? 'Edit plan' : 'New plan'}</h2>
        <form onSubmit={handleSubmit} className="grid gap-4 sm:grid-cols-3">
          <Input label="Name" required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          <Input label="Slug" required value={form.slug} onChange={(e) => setForm({ ...form, slug: e.target.value })} />
          <Input label="Price (cents)" type="number" value={form.price_cents} onChange={(e) => setForm({ ...form, price_cents: Number(e.target.value) })} />
          <Input label="Max Pages" type="number" required value={form.max_pages} onChange={(e) => setForm({ ...form, max_pages: Number(e.target.value) })} />
          <Input label="Daily Audit Limit" type="number" required value={form.daily_audit_limit} onChange={(e) => setForm({ ...form, daily_audit_limit: Number(e.target.value) })} />
          <Input label="Full Recrawls" type="number" value={form.full_recrawls} onChange={(e) => setForm({ ...form, full_recrawls: Number(e.target.value) })} />
          <div className="flex flex-wrap items-center gap-4 sm:col-span-3">
            <label className="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
              <input type="checkbox" checked={form.is_default} onChange={(e) => setForm({ ...form, is_default: e.target.checked })} />
              Default plan
            </label>
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
          {formError && <p className="text-sm text-red-600 dark:text-red-400 sm:col-span-3">{formError}</p>}
          <div className="flex gap-3 sm:col-span-3">
            <Button type="submit" isLoading={isSaving}>
              {editingId ? 'Save changes' : 'Create plan'}
            </Button>
            {editingId && (
              <Button type="button" variant="secondary" onClick={resetForm}>
                Cancel
              </Button>
            )}
          </div>
        </form>
      </Card>

      {error && <ErrorState message={error} onRetry={load} />}
      {!error && !plans && <Spinner />}
      {plans && plans.length === 0 && <EmptyState title="No plans configured" />}

      {plans && plans.length > 0 && (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {plans.map((plan) => (
            <Card key={plan.id}>
              <div className="flex items-center justify-between">
                <h3 className="font-semibold text-slate-900 dark:text-white">{plan.name}</h3>
                {plan.is_default && <Badge tone="blue">Default</Badge>}
              </div>
              <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {plan.max_pages} pages · {plan.daily_audit_limit}/day · ${(plan.price_cents / 100).toFixed(0)}
              </p>
              <div className="mt-4 flex gap-2">
                <Button variant="secondary" onClick={() => startEdit(plan)}>
                  Edit
                </Button>
                <Button variant="danger" onClick={() => handleDelete(plan.id)}>
                  Delete
                </Button>
              </div>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
