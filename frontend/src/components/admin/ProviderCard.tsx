import { useEffect, useState } from 'react';
import * as adminApi from '../../api/admin';
import { apiErrorMessage } from '../../api/client';
import { Card } from '../ui/Card';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { Input } from '../ui/Input';
import { AiModelManager } from './AiModelManager';
import type { AiModel, ApiProviderSummary } from '../../types';

export function ProviderCard({ provider, onUpdated }: { provider: ApiProviderSummary; onUpdated: () => void }) {
  const [apiKey, setApiKey] = useState('');
  const [model, setModel] = useState(provider.model ?? '');
  const [catalogModels, setCatalogModels] = useState<AiModel[]>([]);
  const [maxTokens, setMaxTokens] = useState(String(provider.config?.max_tokens ?? ''));
  const [temperature, setTemperature] = useState(String(provider.config?.temperature ?? ''));
  const [requestTimeout, setRequestTimeout] = useState(String(provider.config?.request_timeout ?? ''));
  const [dailyLimit, setDailyLimit] = useState(String(provider.config?.daily_request_limit ?? ''));
  const [monthlyLimit, setMonthlyLimit] = useState(String(provider.config?.monthly_request_limit ?? ''));
  const [mobileEnabled, setMobileEnabled] = useState(Boolean(provider.config?.mobile_enabled ?? true));
  const [desktopEnabled, setDesktopEnabled] = useState(Boolean(provider.config?.desktop_enabled ?? false));

  const [isSaving, setIsSaving] = useState(false);
  const [isTesting, setIsTesting] = useState(false);
  const [isToggling, setIsToggling] = useState(false);
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);
  const [testResult, setTestResult] = useState<{ success: boolean; message: string; response_time_ms: number | null } | null>(null);

  const isAi = provider.provider_type === 'ai';
  const isPageSpeed = provider.slug === 'pagespeed';

  function reloadCatalogModels() {
    if (!isAi) return;
    adminApi.fetchAiModels(provider.slug).then(setCatalogModels).catch(() => undefined);
  }

  useEffect(reloadCatalogModels, [isAi, provider.slug]);

  async function handleSave(event: React.FormEvent) {
    event.preventDefault();
    setMessage(null);
    setIsSaving(true);
    try {
      const payload: adminApi.ProviderConfigPayload = {
        model: isAi ? model || null : undefined,
        request_timeout: requestTimeout ? Number(requestTimeout) : undefined,
        daily_request_limit: dailyLimit ? Number(dailyLimit) : null,
        monthly_request_limit: monthlyLimit ? Number(monthlyLimit) : null,
      };
      if (apiKey) payload.api_key = apiKey;
      if (isAi) {
        payload.max_tokens = maxTokens ? Number(maxTokens) : undefined;
        payload.temperature = temperature ? Number(temperature) : undefined;
      }
      if (isPageSpeed) {
        payload.mobile_enabled = mobileEnabled;
        payload.desktop_enabled = desktopEnabled;
      }

      await adminApi.configureProvider(provider.slug, payload);
      setMessage({ type: 'success', text: 'Configuration saved.' });
      setApiKey('');
      onUpdated();
    } catch (err) {
      setMessage({ type: 'error', text: apiErrorMessage(err) });
    } finally {
      setIsSaving(false);
    }
  }

  async function handleToggle() {
    setIsToggling(true);
    setMessage(null);
    try {
      await adminApi.setProviderStatus(provider.slug, !provider.is_enabled);
      onUpdated();
    } catch (err) {
      setMessage({ type: 'error', text: apiErrorMessage(err) });
    } finally {
      setIsToggling(false);
    }
  }

  async function handleTest() {
    setIsTesting(true);
    setTestResult(null);
    try {
      const result = await adminApi.testProviderConnection(provider.slug);
      setTestResult(result);
    } catch (err) {
      setTestResult({ success: false, message: apiErrorMessage(err), response_time_ms: null });
    } finally {
      setIsTesting(false);
    }
  }

  return (
    <Card>
      <div className="flex flex-wrap items-start justify-between gap-2 border-b border-slate-100 pb-4 dark:border-line">
        <div>
          <div className="flex items-center gap-2">
            <h2 className="font-semibold text-ink">{provider.name}</h2>
            <Badge tone={isAi ? 'purple' : 'blue'}>{provider.provider_type}</Badge>
            <Badge tone={provider.is_enabled ? 'green' : 'gray'}>{provider.is_enabled ? 'Enabled' : 'Disabled'}</Badge>
            <Badge tone={provider.is_configured ? 'blue' : 'yellow'}>{provider.is_configured ? 'Configured' : 'Not configured'}</Badge>
          </div>
          <p className="mt-1 text-xs text-muted">
            Key: {provider.masked_key ?? 'not set'} {provider.model && `· Model: ${provider.model}`}
          </p>
        </div>
        <Button variant={provider.is_enabled ? 'danger' : 'primary'} isLoading={isToggling} onClick={handleToggle}>
          {provider.is_enabled ? 'Disable' : 'Enable'} provider
        </Button>
      </div>

      <div className="grid grid-cols-2 gap-3 border-b border-slate-100 py-4 text-sm dark:border-line sm:grid-cols-4">
        <div>
          <p className="text-xs text-faint">Requests today</p>
          <p className="font-medium text-ink">
            {provider.requests_today}
            {provider.daily_limit !== null && ` / ${provider.daily_limit}`}
          </p>
        </div>
        <div>
          <p className="text-xs text-faint">Requests this month</p>
          <p className="font-medium text-ink">
            {provider.requests_this_month}
            {provider.monthly_limit !== null && ` / ${provider.monthly_limit}`}
          </p>
        </div>
        <div>
          <p className="text-xs text-faint">Failures today</p>
          <p className="font-medium text-ink">{provider.failures_today}</p>
        </div>
        <div>
          <p className="text-xs text-faint">Last success / failure</p>
          <p className="font-medium text-ink">
            {provider.last_success_at ? new Date(provider.last_success_at).toLocaleString() : '—'}
            {provider.last_failure_at && (
              <span className="block text-red-500">Failed: {new Date(provider.last_failure_at).toLocaleString()}</span>
            )}
          </p>
        </div>
      </div>

      <form onSubmit={handleSave} className="mt-4 grid gap-4 sm:grid-cols-2">
        <Input label="API Key" type="password" placeholder="Leave blank to keep current key" value={apiKey} onChange={(e) => setApiKey(e.target.value)} />
        {isAi && catalogModels.length > 0 && (
          <div className="flex flex-col gap-1">
            <label htmlFor="active-model" className="text-sm font-medium text-muted">
              Active model
            </label>
            <select
              id="active-model"
              value={model}
              onChange={(e) => setModel(e.target.value)}
              className="rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 outline-none focus:ring-2 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white"
            >
              <option value="">Select a model…</option>
              {catalogModels.map((m) => (
                <option key={m.id} value={m.model_id} disabled={!m.is_enabled}>
                  {m.display_name} {!m.is_enabled && '(disabled)'}
                </option>
              ))}
            </select>
          </div>
        )}
        {isAi && catalogModels.length === 0 && (
          <Input label="Model" placeholder="Add a model below first, or type an id" value={model} onChange={(e) => setModel(e.target.value)} />
        )}
        {isAi && <Input label="Max Tokens" type="number" value={maxTokens} onChange={(e) => setMaxTokens(e.target.value)} />}
        {isAi && <Input label="Temperature" type="number" step="0.1" min="0" max="2" value={temperature} onChange={(e) => setTemperature(e.target.value)} />}
        <Input label="Request Timeout (seconds)" type="number" value={requestTimeout} onChange={(e) => setRequestTimeout(e.target.value)} />
        <Input label="Daily Request Limit" type="number" placeholder="No limit" value={dailyLimit} onChange={(e) => setDailyLimit(e.target.value)} />
        {isAi && <Input label="Monthly Request Limit" type="number" placeholder="No limit" value={monthlyLimit} onChange={(e) => setMonthlyLimit(e.target.value)} />}
        {isPageSpeed && (
          <div className="flex items-center gap-6 sm:col-span-2">
            <label className="flex items-center gap-2 text-sm text-muted">
              <input type="checkbox" checked={mobileEnabled} onChange={(e) => setMobileEnabled(e.target.checked)} />
              Mobile strategy
            </label>
            <label className="flex items-center gap-2 text-sm text-muted">
              <input type="checkbox" checked={desktopEnabled} onChange={(e) => setDesktopEnabled(e.target.checked)} />
              Desktop strategy
            </label>
          </div>
        )}

        <div className="flex flex-wrap items-center gap-3 sm:col-span-2">
          <Button type="submit" isLoading={isSaving}>
            Save Configuration
          </Button>
          <Button type="button" variant="secondary" isLoading={isTesting} onClick={handleTest}>
            Test Connection
          </Button>
          {message && (
            <span className={`text-sm ${message.type === 'success' ? 'text-emerald-600' : 'text-red-600'}`}>{message.text}</span>
          )}
        </div>
      </form>

      {testResult && (
        <p className={`mt-3 text-sm ${testResult.success ? 'text-emerald-600' : 'text-red-600'}`}>
          {testResult.success ? '✓' : '✗'} {testResult.message}
          {testResult.response_time_ms !== null && ` (${testResult.response_time_ms}ms)`}
        </p>
      )}

      {isAi && <AiModelManager providerSlug={provider.slug} providerId={provider.id} onChange={reloadCatalogModels} />}
    </Card>
  );
}
