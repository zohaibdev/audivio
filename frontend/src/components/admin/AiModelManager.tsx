import { useEffect, useState } from 'react';
import * as adminApi from '../../api/admin';
import { apiErrorMessage } from '../../api/client';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { Input } from '../ui/Input';
import type { AiModel } from '../../types';

const emptyForm = {
  model_id: '',
  display_name: '',
  description: '',
  input_cost_per_1k_tokens: '',
  output_cost_per_1k_tokens: '',
  context_window: '',
};

/**
 * Per-provider AI model catalog — add/edit/enable/disable/delete. Embedded
 * in ProviderCard rather than a separate page, since a model is always
 * scoped to one provider. Distinct from ApiProvider's own "Model" field
 * (the one model currently actively called) — see PlanAiModelResolver.
 */
export function AiModelManager({
  providerSlug,
  providerId,
  onChange,
}: {
  providerSlug: string;
  providerId: number;
  onChange?: () => void;
}) {
  const [models, setModels] = useState<AiModel[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [isAdding, setIsAdding] = useState(false);
  const [form, setForm] = useState(emptyForm);
  const [formError, setFormError] = useState<string | null>(null);
  const [isSaving, setIsSaving] = useState(false);

  function load() {
    adminApi
      .fetchAiModels(providerSlug)
      .then(setModels)
      .catch((err) => setError(apiErrorMessage(err)));
    onChange?.();
  }

  useEffect(load, [providerSlug]);

  async function handleAdd(event: React.FormEvent) {
    event.preventDefault();
    setFormError(null);
    setIsSaving(true);
    try {
      await adminApi.createAiModel({
        api_provider_id: providerId,
        model_id: form.model_id,
        display_name: form.display_name,
        description: form.description || null,
        input_cost_per_1k_tokens: form.input_cost_per_1k_tokens ? Number(form.input_cost_per_1k_tokens) : null,
        output_cost_per_1k_tokens: form.output_cost_per_1k_tokens ? Number(form.output_cost_per_1k_tokens) : null,
        context_window: form.context_window ? Number(form.context_window) : null,
      });
      setForm(emptyForm);
      setIsAdding(false);
      load();
    } catch (err) {
      setFormError(apiErrorMessage(err));
    } finally {
      setIsSaving(false);
    }
  }

  async function toggleEnabled(model: AiModel) {
    try {
      await adminApi.updateAiModel(model.id, { is_enabled: !model.is_enabled });
      load();
    } catch (err) {
      setError(apiErrorMessage(err));
    }
  }

  async function handleDelete(model: AiModel) {
    try {
      await adminApi.deleteAiModel(model.id);
      load();
    } catch (err) {
      setError(apiErrorMessage(err));
    }
  }

  return (
    <div className="mt-4 border-t border-slate-100 pt-4 dark:border-slate-800">
      <div className="mb-3 flex items-center justify-between">
        <h3 className="text-sm font-semibold text-slate-700 dark:text-slate-200">Model catalog</h3>
        <Button variant="secondary" onClick={() => setIsAdding((open) => !open)}>
          {isAdding ? 'Cancel' : 'Add model'}
        </Button>
      </div>

      {error && <p className="text-sm text-red-600 dark:text-red-400">{error}</p>}

      {isAdding && (
        <form onSubmit={handleAdd} className="mb-4 grid gap-3 rounded-md border border-slate-200 p-3 dark:border-slate-800 sm:grid-cols-2">
          <Input label="Model ID" required value={form.model_id} onChange={(e) => setForm({ ...form, model_id: e.target.value })} />
          <Input label="Display name" required value={form.display_name} onChange={(e) => setForm({ ...form, display_name: e.target.value })} />
          <Input
            label="Input cost / 1k tokens"
            type="number"
            step="0.000001"
            value={form.input_cost_per_1k_tokens}
            onChange={(e) => setForm({ ...form, input_cost_per_1k_tokens: e.target.value })}
          />
          <Input
            label="Output cost / 1k tokens"
            type="number"
            step="0.000001"
            value={form.output_cost_per_1k_tokens}
            onChange={(e) => setForm({ ...form, output_cost_per_1k_tokens: e.target.value })}
          />
          <Input label="Context window" type="number" value={form.context_window} onChange={(e) => setForm({ ...form, context_window: e.target.value })} />
          <Input label="Description" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
          {formError && <p className="text-sm text-red-600 dark:text-red-400 sm:col-span-2">{formError}</p>}
          <div className="sm:col-span-2">
            <Button type="submit" isLoading={isSaving}>Add</Button>
          </div>
        </form>
      )}

      {models === null && <p className="text-sm text-slate-400">Loading models…</p>}
      {models !== null && models.length === 0 && <p className="text-sm text-slate-400">No models registered for this provider yet.</p>}

      {models !== null && models.length > 0 && (
        <div className="flex flex-col gap-2">
          {models.map((model) => (
            <div key={model.id} className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-slate-200 p-2 text-sm dark:border-slate-800">
              <div>
                <span className="font-medium text-slate-900 dark:text-white">{model.display_name}</span>{' '}
                <span className="text-xs text-slate-400">({model.model_id})</span>{' '}
                <Badge tone={model.is_enabled ? 'green' : 'gray'}>{model.is_enabled ? 'Enabled' : 'Disabled'}</Badge>
              </div>
              <div className="flex gap-2">
                <Button variant="secondary" onClick={() => toggleEnabled(model)}>
                  {model.is_enabled ? 'Disable' : 'Enable'}
                </Button>
                <Button variant="danger" onClick={() => handleDelete(model)}>
                  Delete
                </Button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
